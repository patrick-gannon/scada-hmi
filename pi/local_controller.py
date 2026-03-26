#!/usr/bin/env python3
"""
Local Controller for SCADA System
Handles local device communication (Kasa plugs) and receives commands from VPS
"""

import mysql.connector
import json
import sys
import time
import logging
from datetime import datetime
import subprocess
import threading
from http.server import HTTPServer, BaseHTTPRequestHandler
import urllib.parse

# Configuration
CONFIG_FILE = 'scada_config.ini'

def load_config():
    """Load configuration from INI file"""
    import configparser
    config = configparser.ConfigParser()
    config.read(CONFIG_FILE)
    return config

class KasaController:
    """Local Kasa plug controller"""
    
    def __init__(self):
        self.logger = logging.getLogger('KasaController')
    
    def control_plug(self, ip_address, state):
        """Control a Kasa plug by IP address"""
        try:
            action = 'on' if state else 'off'
            cmd = ['python3', '-m', 'kasa', '--host', ip_address, action]
            
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
            
            if result.returncode == 0:
                self.logger.info(f"Successfully turned plug {ip_address} {action}")
                return {'success': True, 'output': result.stdout}
            else:
                self.logger.error(f"Failed to control plug {ip_address}: {result.stderr}")
                return {'success': False, 'error': result.stderr}
                
        except subprocess.TimeoutExpired:
            self.logger.error(f"Timeout controlling plug {ip_address}")
            return {'success': False, 'error': 'Timeout'}
        except Exception as e:
            self.logger.error(f"Error controlling plug {ip_address}: {str(e)}")
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

class CommandHandler(BaseHTTPRequestHandler):
    """HTTP request handler for receiving commands from VPS"""
    
    def __init__(self, *args, kasa_controller=None, db_config=None, **kwargs):
        self.kasa = kasa_controller
        self.db_config = db_config
        super().__init__(*args, **kwargs)
    
    def do_POST(self):
        """Handle POST requests"""
        content_length = int(self.headers['Content-Length'])
        post_data = self.rfile.read(content_length)
        
        try:
            data = json.loads(post_data.decode('utf-8'))
            command = data.get('command')
            
            if command == 'control_plug':
                result = self.handle_control_plug(data)
            elif command == 'get_plug_status':
                result = self.handle_get_status(data)
            else:
                result = {'success': False, 'error': 'Unknown command'}
            
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps(result).encode())
            
        except Exception as e:
            self.send_response(500)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({'success': False, 'error': str(e)}).encode())
    
    def handle_control_plug(self, data):
        """Handle plug control command"""
        try:
            plug_id = data.get('plug_id')
            state = data.get('state')
            
            # Get plug info from local database cache
            plug_info = self.get_plug_info(plug_id)
            if not plug_info:
                return {'success': False, 'error': 'Plug not found'}
            
            # Control the plug
            result = self.kasa.control_plug(plug_info['ip_address'], state)
            
            if result['success']:
                # Log the action to local database
                self.log_plug_action(plug_id, 'turn_on' if state else 'turn_off', 'remote_command')
                
            return result
            
        except Exception as e:
            return {'success': False, 'error': str(e)}
    
    def handle_get_status(self, data):
        """Handle get plug status command"""
        try:
            plug_id = data.get('plug_id')
            plug_info = self.get_plug_info(plug_id)
            if not plug_info:
                return {'success': False, 'error': 'Plug not found'}
            
            return self.kasa.get_plug_status(plug_info['ip_address'])
            
        except Exception as e:
            return {'success': False, 'error': str(e)}
    
    def get_plug_info(self, plug_id):
        """Get plug information from local cache"""
        # For now, we'll use a simple in-memory cache
        # In production, you might want to sync this from the VPS database
        return self.plug_cache.get(plug_id)
    
    def log_plug_action(self, plug_id, action, trigger_type):
        """Log plug action to local database"""
        try:
            conn = mysql.connector.connect(**self.db_config)
            cursor = conn.cursor()
            
            cursor.execute("""
                INSERT INTO local_plug_actions (plug_id, action, trigger_type, created_at)
                VALUES (%s, %s, %s, NOW())
            """, (plug_id, action, trigger_type))
            
            conn.commit()
            cursor.close()
            conn.close()
            
        except Exception as e:
            logging.error(f"Failed to log plug action: {str(e)}")
    
    def log_message(self, format, *args):
        """Override to prevent default logging"""
        pass

class LocalController:
    """Main local controller"""
    
    def __init__(self):
        self.config = load_config()
        self.db_config = {
            'host': self.config.get('database', 'host'),
            'user': self.config.get('database', 'user'),
            'password': self.config.get('database', 'password'),
            'database': self.config.get('database', 'database')
        }
        self.kasa = KasaController()
        self.plug_cache = {}
        self.setup_logging()
        self.sync_plugs_from_vps()
    
    def setup_logging(self):
        """Setup logging"""
        logging.basicConfig(
            level=logging.INFO,
            format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
            handlers=[
                logging.FileHandler('/var/log/scada_local_controller.log'),
                logging.StreamHandler()
            ]
        )
        self.logger = logging.getLogger('LocalController')
    
    def sync_plugs_from_vps(self):
        """Sync plug information from VPS database"""
        try:
            conn = mysql.connector.connect(**self.db_config)
            cursor = conn.cursor(dictionary=True)
            
            cursor.execute("SELECT plug_id, ip_address, display_name FROM kasa_plugs WHERE is_active = 1")
            plugs = cursor.fetchall()
            
            for plug in plugs:
                self.plug_cache[plug['plug_id']] = plug
            
            cursor.close()
            conn.close()
            
            self.logger.info(f"Synced {len(plugs)} plugs from VPS")
            
        except Exception as e:
            self.logger.error(f"Failed to sync plugs: {str(e)}")
    
    def start_http_server(self):
        """Start HTTP server for receiving commands"""
        port = self.config.getint('local_controller', 'port', fallback=8080)
        
        def handler(*args, **kwargs):
            return CommandHandler(*args, kasa_controller=self.kasa, db_config=self.db_config, plug_cache=self.plug_cache, **kwargs)
        
        server = HTTPServer(('0.0.0.0', port), handler)
        self.logger.info(f"Local controller HTTP server started on port {port}")
        server.serve_forever()
    
    def run(self):
        """Run the local controller"""
        self.logger.info("Starting SCADA Local Controller")
        
        # Start HTTP server in a separate thread
        server_thread = threading.Thread(target=self.start_http_server)
        server_thread.daemon = True
        server_thread.start()
        
        # Keep the main thread alive and periodically sync plugs
        try:
            while True:
                time.sleep(300)  # Sync every 5 minutes
                self.sync_plugs_from_vps()
        except KeyboardInterrupt:
            self.logger.info("Shutting down local controller")

if __name__ == '__main__':
    controller = LocalController()
    controller.run()
