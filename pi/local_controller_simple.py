#!/usr/bin/env python3
"""
Simplified Local Controller for SCADA System
Handles local device communication (Kasa plugs) without local database
"""

import json
import sys
import time
import logging
import subprocess
import asyncio
import kasa
import mysql.connector
import threading
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler
from socketserver import ThreadingMixIn
from dotenv import load_dotenv
import os

# Load .env file
load_dotenv(os.path.join(os.path.dirname(__file__), '.env'))

NODE_ID = os.getenv('NODE_ID', 'node_01')

# Database configuration from environment
db_config = {
    'host': os.getenv('DB_HOST'),
    'user': os.getenv('DB_USER'),
    'password': os.getenv('DB_PASSWORD'),
    'database': os.getenv('DB_NAME')
}

SETTINGS_POLL_RATE = 5  # seconds between settings refreshes


class DeviceManager:
    """Manages device registration and trigger state fetching from VPS database"""
    
    def __init__(self, kasa_controller, plug_cache):
        self.kasa = kasa_controller
        self.plug_cache = plug_cache
        self.logger = logging.getLogger('DeviceManager')
        self.settings_lock = threading.Lock()
        self.shared_triggers = {}  # device_id -> desired_state
    
    def get_db_connection(self):
        """Create a database connection"""
        try:
            return mysql.connector.connect(**db_config)
        except Exception as e:
            self.logger.error(f"Database connection failed: {e}")
            return None
    
    def register_device(self, device_id, alias, ip_address, device_type='kasa_plug'):
        """Register or update a device in the VPS database (kasa_plugs table)"""
        conn = self.get_db_connection()
        if not conn:
            return False
        
        try:
            cursor = conn.cursor()
            cursor.execute(
                """INSERT INTO kasa_plugs (plug_id, display_name, ip_address, location) 
                   VALUES (%s, %s, %s, %s)
                   ON DUPLICATE KEY UPDATE 
                   display_name = VALUES(display_name), 
                   ip_address = VALUES(ip_address),
                   updated_at = NOW()""",
                (device_id, alias, ip_address, NODE_ID)
            )
            conn.commit()
            cursor.close()
            conn.close()
            self.logger.info(f"Registered device {device_id} ({alias}) to VPS")
            return True
        except Exception as e:
            self.logger.error(f"Failed to register device {device_id}: {e}")
            return False
    
    def fetch_trigger_settings(self):
        """Fetch trigger states from VPS database (plug_triggers table)"""
        conn = self.get_db_connection()
        if not conn:
            return None
        
        try:
            cursor = conn.cursor()
            cursor.execute(
                """SELECT plug_id, action, trigger_type, node_id, threshold_value 
                   FROM plug_triggers 
                   WHERE (node_id = %s OR node_id IS NULL) AND is_active = 1""",
                (NODE_ID,)
            )
            rows = cursor.fetchall()
            cursor.close()
            conn.close()
            
            triggers = {}
            for row in rows:
                plug_id, action, trigger_type, node_id, threshold_value = row
                # action is 'turn_on' or 'turn_off' - convert to boolean desired_state
                desired_state = (action == 'turn_on')
                triggers[plug_id] = {
                    'desired_state': desired_state,
                    'action': action,
                    'trigger_type': trigger_type,
                    'node_id': node_id,
                    'threshold_value': float(threshold_value) if threshold_value else None
                }
            return triggers
        except Exception as e:
            self.logger.error(f"Error fetching trigger settings: {e}")
            return None
    
    def settings_loop(self):
        """Polls the database for trigger settings and updates shared state"""
        self.logger.info(f"Settings polling loop started (every {SETTINGS_POLL_RATE}s)")
        while True:
            new_triggers = self.fetch_trigger_settings()
            if new_triggers is not None:
                with self.settings_lock:
                    old_triggers = self.shared_triggers.copy()
                    self.shared_triggers = new_triggers
                
                # Log changes
                for device_id, trigger in new_triggers.items():
                    old_state = old_triggers.get(device_id, {}).get('desired_state')
                    new_state = trigger['desired_state']
                    if old_state != new_state:
                        self.logger.info(f"Trigger changed for {device_id}: {old_state} -> {new_state}")
            time.sleep(SETTINGS_POLL_RATE)
    
    def log_action(self, plug_id, action, trigger_type, triggered_by, sensor_value=None, threshold_value=None):
        """Log a plug action to plug_actions_log table"""
        conn = self.get_db_connection()
        if not conn:
            return False
        
        try:
            cursor = conn.cursor()
            cursor.execute(
                """INSERT INTO plug_actions_log 
                   (plug_id, action, trigger_type, triggered_by, node_id, sensor_value, threshold_value) 
                   VALUES (%s, %s, %s, %s, %s, %s, %s)""",
                (plug_id, action, trigger_type, triggered_by, NODE_ID, sensor_value, threshold_value)
            )
            conn.commit()
            cursor.close()
            conn.close()
            return True
        except Exception as e:
            self.logger.error(f"Failed to log action for {plug_id}: {e}")
            return False
    
    def execute_triggers(self):
        """Execute trigger commands based on desired vs actual state"""
        with self.settings_lock:
            triggers = self.shared_triggers.copy()
        
        for device_id, trigger in triggers.items():
            plug_info = self.plug_cache.get(device_id)
            if not plug_info:
                self.logger.warning(f"Trigger for unknown device: {device_id}")
                continue
            
            desired_state = trigger['desired_state']
            ip_address = plug_info['ip_address']
            action = trigger['action']  # 'turn_on' or 'turn_off'
            trigger_type = trigger.get('trigger_type', 'manual')
            threshold_value = trigger.get('threshold_value')
            
            # Get current state
            status_result = self.kasa.get_plug_status(ip_address)
            if not status_result['success']:
                self.logger.error(f"Cannot get status for {device_id}: {status_result.get('error')}")
                continue
            
            current_state = status_result.get('is_on', False)
            
            # Execute if state differs
            if current_state != desired_state:
                self.logger.info(f"Executing trigger: {device_id} -> {action}")
                result = self.kasa.control_plug(ip_address, desired_state)
                if result['success']:
                    self.logger.info(f"Successfully set {device_id} to {action}")
                    # Log the action to database
                    self.log_action(
                        plug_id=device_id,
                        action=action,
                        trigger_type=trigger_type,
                        triggered_by='auto_trigger',
                        threshold_value=threshold_value
                    )
                else:
                    self.logger.error(f"Failed to set {device_id}: {result.get('error')}")


class KasaController:
    """Local Kasa plug controller"""
    
    def __init__(self):
        self.logger = logging.getLogger('KasaController')
        # Load Kasa credentials from environment or config
        self.kasa_username = self.load_kasa_credentials().get('email')
        self.kasa_password = self.load_kasa_credentials().get('password')
    
    def load_kasa_credentials(self):
        """Load Kasa credentials from config file"""
        try:
            import configparser
            config = configparser.ConfigParser()
            
            # Try multiple paths for the config file
            config_paths = [
                '/home/patrick/scada-hmi/pi/scada_config.ini',
                'scada_config.ini',
                './scada_config.ini'
            ]
            
            for path in config_paths:
                try:
                    config.read(path)
                    email = config.get('kasa', 'email', fallback=None)
                    password = config.get('kasa', 'password', fallback=None)
                    
                    if email and password:
                        self.logger.info(f"Loaded credentials from {path}")
                        return {'email': email, 'password': password}
                except Exception as e:
                    self.logger.debug(f"Could not read config from {path}: {e}")
                    continue
            
            self.logger.warning("Could not load Kasa credentials from any config file")
            return {'email': None, 'password': None}
                
        except Exception as e:
            self.logger.warning(f"Could not load Kasa credentials: {e}")
            return {'email': None, 'password': None}
    
    def control_plug(self, ip_address, state):
        """Control a Kasa plug by IP address - SIMPLE VERSION"""
        try:
            action = 'on' if state else 'off'
            
            # Debug: Check if credentials are loaded
            self.logger.info(f"Using credentials: {self.kasa_username} (password: {'*' * len(self.kasa_password) if self.kasa_password else 'None'})")
            
            if not self.kasa_username or not self.kasa_password:
                return {'success': False, 'error': 'Kasa credentials not loaded'}
            
            # Use the exact method that worked in your test, with proper session cleanup
            cmd = [
                'python3', '-c', f"""
import asyncio
from kasa import Discover, Credentials

async def control():
    try:
        credentials = Credentials("{self.kasa_username}", "{self.kasa_password}")
        device = await Discover.discover_single("{ip_address}", credentials=credentials)
        await device.update()
        if {state}:
            await device.turn_on()
        else:
            await device.turn_off()
        print("Success")
    except Exception as e:
        print(f"Error: {{e}}")

try:
    asyncio.run(control())
except Exception as e:
    print(f"Error: {{e}}")
"""]
            
            self.logger.info(f"Controlling plug {ip_address} -> {action}")
            
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
            
            if result.returncode == 0 and "Success" in result.stdout:
                self.logger.info(f"Successfully turned plug {ip_address} {action}")
                return {'success': True, 'output': 'Plug turned ' + action}
            else:
                self.logger.error(f"Failed to control plug {ip_address}: {result.stderr}")
                return {'success': False, 'error': result.stderr}
                
        except Exception as e:
            self.logger.error(f"Error controlling plug {ip_address}: {str(e)}")
            return {'success': False, 'error': str(e)}
    
    def control_plug_cli_auth(self, ip_address, state):
        """Control plug using kasa CLI with authentication"""
        try:
            action = 'on' if state else 'off'
            
            # Use the exact method that works (from your test)
            cmd = [
                'python3', '-c', f"""
import asyncio
from kasa import Discover, Credentials

async def control():
    try:
        credentials = Credentials("{self.kasa_username}", "{self.kasa_password}")
        device = await Discover.discover_single("{ip_address}", credentials=credentials)
        await device.update()
        if {state}:
            await device.turn_on()
        else:
            await device.turn_off()
        print("Success")
    except Exception as e:
        print(f"Error: {{e}}")

asyncio.run(control())
"""]
            
            self.logger.info(f"Trying cloud auth for {ip_address}")
            
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
            
            if result.returncode == 0 and "Success" in result.stdout and not result.stderr:
                self.logger.info(f"Successfully turned plug {ip_address} {action} (cloud auth)")
                return {'success': True, 'output': result.stdout}
            else:
                self.logger.error(f"Cloud auth failed for {ip_address}: {result.stderr}")
                return {'success': False, 'error': result.stderr}
                
        except subprocess.TimeoutExpired:
            self.logger.error(f"Timeout controlling plug {ip_address} with cloud auth")
            return {'success': False, 'error': 'Timeout'}
        except Exception as e:
            self.logger.error(f"Error in cloud auth control: {str(e)}")
            return {'success': False, 'error': str(e)}
    
    def control_plug_subprocess(self, ip_address, state):
        """Fallback subprocess control method"""
        try:
            action = 'on' if state else 'off'
            
            # Try different command formats with IotPlug
            commands = [
                ['python3', '-c', f"""
import asyncio
from kasa.iot import IotPlug

async def control():
    try:
        device = IotPlug('{ip_address}')
        await device.update()
        if {state}:
            await device.turn_on()
        else:
            await device.turn_off()
        print('Success')
    except Exception as e:
        print(f'Error: {{e}}')

asyncio.run(control())
"""],
                # Fallback to module command if available
                ['python3', '-m', 'kasa', '--host', ip_address, action]
            ]
            
            for cmd in commands:
                try:
                    result = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
                    
                    # Check for actual success, not just return code
                    if result.returncode == 0 and "Success" in result.stdout and not result.stderr:
                        self.logger.info(f"Successfully turned plug {ip_address} {action} (subprocess)")
                        return {'success': True, 'output': result.stdout}
                    elif "No module named kasa.__main__" in result.stderr:
                        self.logger.warning("kasa module command not available, trying next method")
                        continue
                    else:
                        self.logger.error(f"Command failed: {result.stderr}")
                        continue
                        
                except subprocess.TimeoutExpired:
                    self.logger.error(f"Timeout controlling plug {ip_address}")
                    continue
                except Exception as e:
                    self.logger.error(f"Command error: {e}")
                    continue
            
            return {'success': False, 'error': 'All control methods failed'}
                
        except Exception as e:
            self.logger.error(f"Error in subprocess control: {str(e)}")
            return {'success': False, 'error': str(e)}
    
    def control_plug_cloud(self, ip_address, state):
        """Control Kasa plug via cloud (for 125M)"""
        try:
            action = 'on' if state else 'off'
            
            # Use cloud authentication
            cmd = ['python3', '-c', f"""
import asyncio
import kasa
from kasa import Credentials

async def control():
    try:
        credentials = Credentials("{self.kasa_username}", "{self.kasa_password}")
        device = await kasa.Discover.discover_single("{ip_address}", credentials=credentials)
        await device.update()
        if {state}:
            await device.turn_on()
        else:
            await device.turn_off()
        print("Success")
    except Exception as e:
        print(f"Error: {{e}}")

asyncio.run(control())
"""]
            
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
            
            if result.returncode == 0 and "Success" in result.stdout:
                self.logger.info(f"Successfully turned plug {ip_address} {action} (cloud)")
                return {'success': True, 'output': result.stdout}
            else:
                self.logger.error(f"Cloud control failed for {ip_address}: {result.stderr}")
                return {'success': False, 'error': result.stderr}
                
        except Exception as e:
            self.logger.error(f"Error in cloud control: {str(e)}")
            return {'success': False, 'error': str(e)}
    
    def get_plug_status(self, ip_address):
        """Get plug status"""
        try:
            cmd = ['python3', '-m', 'kasa', '--host', ip_address, '--json']
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
            
            if result.returncode == 0:
                data = json.loads(result.stdout)
                is_on = not data.get('led_off', True)
                return {'success': True, 'is_on': is_on, 'data': data}
            else:
                return {'success': False, 'error': result.stderr}
                
        except Exception as e:
            return {'success': False, 'error': str(e)}

class ThreadedHTTPServer(ThreadingMixIn, HTTPServer):
    """Multi-threaded HTTP server to handle concurrent requests"""
    daemon_threads = True  # Allow server to exit even if threads are still running
    allow_reuse_address = True  # Allow quick restart on same port

class SimpleCommandHandler(BaseHTTPRequestHandler):
    """Simplified HTTP request handler"""
    
    def __init__(self, *args, kasa_controller=None, plug_cache=None, **kwargs):
        self.kasa = kasa_controller
        self.plug_cache = plug_cache or {}
        super().__init__(*args, **kwargs)
    
    def do_POST(self):
        """Handle POST requests with timeout protection"""
        # Set socket timeout for this request to prevent hanging
        self.connection.settimeout(30)  # 30 second timeout for the entire request
        
        content_length = int(self.headers['Content-Length'])
        post_data = self.rfile.read(content_length)
        
        try:
            data = json.loads(post_data.decode('utf-8'))
            command = data.get('command')
            
            # Use a thread-safe approach with timeout for potentially slow operations
            import concurrent.futures
            
            def execute_command():
                if command == 'control_plug':
                    return self.handle_control_plug(data)
                elif command == 'get_plug_status':
                    return self.handle_get_status(data)
                elif command == 'list_plugs':
                    return self.handle_list_plugs()
                elif command == 'discover_plugs':
                    return self.handle_discover_plugs()
                elif command == 'test':
                    return {'success': True, 'message': 'Local controller is working', 'plugs_found': len(self.plug_cache)}
                else:
                    return {'success': False, 'error': 'Unknown command'}
            
            # Execute with timeout to prevent hanging
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
                future = executor.submit(execute_command)
                try:
                    result = future.result(timeout=25)  # 25 second timeout for command execution
                except concurrent.futures.TimeoutError:
                    result = {'success': False, 'error': 'Command execution timed out'}
                    logging.error(f"Command {command} timed out after 25 seconds")
            
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps(result).encode())
            
        except Exception as e:
            logging.error(f"Error in do_POST: {str(e)}")
            self.send_response(500)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({'success': False, 'error': str(e)}).encode())
    
    def handle_control_plug(self, data):
        """Handle plug control command"""
        try:
            plug_id = data.get('plug_id')
            state = data.get('state')
            
            if not plug_id:
                return {'success': False, 'error': 'plug_id required'}
            
            # Get plug info from cache
            plug_info = self.plug_cache.get(plug_id)
            if not plug_info:
                return {'success': False, 'error': f'Plug {plug_id} not found. Available plugs: {list(self.plug_cache.keys())}'}
            
            ip_address = plug_info['ip_address']
            
            # Control the plug
            result = self.kasa.control_plug(ip_address, state)
            
            # Log the action
            action = 'turn_on' if state else 'turn_off'
            logging.info(f"Plug {plug_id} ({plug_info['alias']}) at {ip_address} action: {action} via remote command")
            
            if result['success']:
                result['plug_info'] = plug_info
            
            return result
            
        except Exception as e:
            return {'success': False, 'error': str(e)}
    
    def handle_get_status(self, data):
        """Handle get plug status command"""
        try:
            plug_id = data.get('plug_id')
            
            if not plug_id:
                return {'success': False, 'error': 'plug_id required'}
            
            plug_info = self.plug_cache.get(plug_id)
            if not plug_info:
                return {'success': False, 'error': f'Plug {plug_id} not found'}
            
            ip_address = plug_info['ip_address']
            result = self.kasa.get_plug_status(ip_address)
            
            if result['success']:
                result['plug_info'] = plug_info
            
            return result
            
        except Exception as e:
            return {'success': False, 'error': str(e)}
    
    def handle_list_plugs(self):
        """Handle list plugs command"""
        try:
            return {
                'success': True, 
                'plugs': self.plug_cache,
                'count': len(self.plug_cache)
            }
        except Exception as e:
            return {'success': False, 'error': str(e)}
    
    def handle_discover_plugs(self):
        """Handle manual discover command"""
        try:
            # This would trigger a rediscovery
            return {
                'success': True,
                'message': 'Discovery triggered - check logs for results',
                'current_plugs': list(self.plug_cache.keys())
            }
        except Exception as e:
            return {'success': False, 'error': str(e)}
    
    def log_message(self, format, *args):
        """Override to prevent default logging"""
        pass

class SimpleLocalController:
    """Simplified main local controller with VPS database integration"""
    
    def __init__(self):
        self.kasa = KasaController()
        self.setup_logging()
        self.plug_cache = {}
        # Load credentials for cloud discovery fallback
        self.kasa_username = self.kasa.kasa_username
        self.kasa_password = self.kasa.kasa_password
        # Initialize device manager for VPS integration
        self.device_manager = DeviceManager(self.kasa, self.plug_cache)
        self.logger.info(f"Local Controller initialized for node: {NODE_ID}")
    
    def setup_logging(self):
        """Setup logging"""
        logging.basicConfig(
            level=logging.INFO,
            format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
            handlers=[
                logging.FileHandler('/home/patrick/scada_local_controller.log'),
                logging.StreamHandler()
            ]
        )
        self.logger = logging.getLogger('SimpleLocalController')
    
    async def auto_discover_plugs(self):
        """Auto-discover Kasa plugs and cache them"""
        self.logger.info("Auto-discovering Kasa plugs...")
        
        try:
            # Try local discovery first (without auth)
            devices = await kasa.Discover.discover()
            self.logger.info(f"Discovery found {len(devices)} devices")
            
            # Discovery returns a dict: {ip_address: device_object}
            for device_address, device in devices.items():
                try:
                    self.logger.info(f"Processing device: {device_address} -> {device} (type: {type(device)})")
                    
                    # Try to get device info with cloud auth if available
                    device_alias = f"Kasa Plug {device_address}"
                    device_type = "Unknown"
                    
                    if self.kasa_username and self.kasa_password:
                        try:
                            from kasa import Credentials
                            credentials = Credentials(self.kasa_username, self.kasa_password)
                            cloud_device = await kasa.Discover.discover_single(device_address, credentials=credentials)
                            await cloud_device.update()
                            device_alias = getattr(cloud_device, 'alias', None) or device_alias
                            device_type = getattr(cloud_device, 'device_type', 'Unknown')
                            self.logger.info(f"Got device info from cloud: {device_alias}")
                        except Exception as cloud_e:
                            self.logger.warning(f"Cloud discovery failed for {device_address}: {cloud_e}")
                    
                    # Create plug_id from alias
                    plug_id = device_alias.lower().replace(' ', '_').replace('-', '_')
                    if not plug_id or len(plug_id) < 3:
                        plug_id = f"kasa_plug_{device_address.replace('.', '_')}"
                    
                    # Cache the plug info
                    self.plug_cache[plug_id] = {
                        'ip_address': device_address,
                        'alias': device_alias,
                        'device_type': str(device_type)
                    }
                    
                    # Register device to VPS database
                    self.device_manager.register_device(plug_id, device_alias, device_address, 'kasa_plug')
                    
                    self.logger.info(f"Discovered: {device_alias} ({plug_id}) at {device_address} ({device_type})")
                    
                except Exception as e:
                    self.logger.error(f"Error discovering device {device_address}: {e}")
                    continue
                    
        except Exception as e:
            self.logger.error(f"Auto-discovery failed: {e}")
            # Try with cloud auth if available
            if self.kasa_username and self.kasa_password:
                await self.try_cloud_discovery()
    
    async def try_cloud_discovery(self):
        """Try cloud discovery as fallback"""
        try:
            from kasa import Credentials
            credentials = Credentials(self.kasa_username, self.kasa_password)
            devices = await kasa.Discover.discover(credentials=credentials)
            self.logger.info(f"Cloud discovery found {len(devices)} devices")
            
            for device in devices:
                try:
                    await device.update()
                    plug_id = device.alias.lower().replace(' ', '_').replace('-', '_')
                    if not plug_id or len(plug_id) < 3:
                        plug_id = f"kasa_plug_{device.host.replace('.', '_')}"
                    
                    self.plug_cache[plug_id] = {
                        'ip_address': device.host,
                        'alias': device.alias,
                        'device_type': device.device_type
                    }
                    
                    # Register device to VPS database
                    self.device_manager.register_device(plug_id, device.alias, device.host, 'kasa_plug')
                    
                    self.logger.info(f"Cloud discovered: {device.alias} ({plug_id}) at {device.host}")
                    
                except Exception as e:
                    self.logger.error(f"Error in cloud discovery for {device.host}: {e}")
                    continue
                    
        except Exception as e:
            self.logger.error(f"Cloud discovery also failed: {e}")
    
    def start_http_server(self):
        """Start HTTP server for receiving commands"""
        port = 8081  # Changed from 8080 to avoid AMP panel conflict
        
        def handler(*args, **kwargs):
            return SimpleCommandHandler(*args, kasa_controller=self.kasa, plug_cache=self.plug_cache, **kwargs)
        
        server = ThreadedHTTPServer(('0.0.0.0', port), handler)
        self.logger.info(f"Simplified local controller started on port {port} (multi-threaded)")
        server.serve_forever()
    
    def trigger_loop(self):
        """Periodically execute trigger commands based on VPS database settings"""
        self.logger.info("Trigger execution loop started (every 10s)")
        # Wait a moment for initial discovery and settings fetch
        time.sleep(3)
        while True:
            try:
                if self.plug_cache:
                    self.device_manager.execute_triggers()
            except Exception as e:
                self.logger.error(f"Error in trigger loop: {e}")
            time.sleep(10)  # Check triggers every 10 seconds
    
    def run(self):
        """Run the simplified local controller with VPS integration"""
        self.logger.info("Starting Simplified SCADA Local Controller")
        self.logger.info(f"Node ID: {NODE_ID} | Database: {db_config.get('host', 'NOT CONFIGURED')}")
        
        # Run auto-discovery first
        try:
            asyncio.run(self.auto_discover_plugs())
        except Exception as e:
            self.logger.error(f"Startup discovery failed: {e}")
        
        # Start settings polling thread (daemon - dies with main process)
        settings_thread = threading.Thread(target=self.device_manager.settings_loop, daemon=True)
        settings_thread.start()
        
        # Start trigger execution thread (daemon)
        trigger_thread = threading.Thread(target=self.trigger_loop, daemon=True)
        trigger_thread.start()
        
        # Start HTTP server in a separate thread
        server_thread = threading.Thread(target=self.start_http_server, daemon=True)
        server_thread.start()
        
        # Keep the main thread alive and periodically refresh discovery
        try:
            while True:
                time.sleep(300)  # Rediscover every 5 minutes
                self.logger.info("Refreshing plug discovery...")
                try:
                    asyncio.run(self.auto_discover_plugs())
                except Exception as e:
                    self.logger.error(f"Refresh discovery failed: {e}")
        except KeyboardInterrupt:
            self.logger.info("Shutting down local controller")

if __name__ == '__main__':
    controller = SimpleLocalController()
    controller.run()
