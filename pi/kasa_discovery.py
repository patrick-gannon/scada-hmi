#!/usr/bin/env python3
"""
Kasa Plug Auto-Discovery
Automatically discovers all Kasa plugs on the network and adds them to the database
"""

import asyncio
import kasa
import mysql.connector
import sys
import logging
from datetime import datetime

# Configuration
CONFIG_FILE = 'scada_config.ini'

def load_config():
    """Load configuration from INI file"""
    import configparser
    config = configparser.ConfigParser()
    config.read(CONFIG_FILE)
    return config

def setup_logging():
    """Setup logging"""
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(levelname)s - %(message)s'
    )
    return logging.getLogger('KasaDiscovery')

class KasaDiscovery:
    """Auto-discover Kasa plugs"""
    
    def __init__(self):
        self.logger = setup_logging()
        self.config = load_config()
        self.db_config = {
            'host': self.config.get('database', 'host'),
            'user': self.config.get('database', 'user'),
            'password': self.config.get('database', 'password'),
            'database': self.config.get('database', 'database')
        }
    
    async def discover_plugs(self):
        """Discover all Kasa plugs on network"""
        self.logger.info("Starting Kasa plug discovery...")
        
        discovered_plugs = []
        
        try:
            # Discover all devices
            devices = await kasa.Discover.discover()
            
            for device in devices:
                try:
                    await device.update()
                    
                    plug_info = {
                        'ip_address': device.host,
                        'alias': device.alias or f"Kasa Plug {device.host}",
                        'device_type': device.device_type,
                        'model': getattr(device, 'model', 'Unknown'),
                        'is_on': device.is_on if hasattr(device, 'is_on') else None
                    }
                    
                    discovered_plugs.append(plug_info)
                    self.logger.info(f"Found: {device.alias} at {device.host} ({device.device_type})")
                    
                except Exception as e:
                    self.logger.error(f"Error getting details for {device.host}: {e}")
                    continue
                    
        except Exception as e:
            self.logger.error(f"Discovery failed: {e}")
        
        return discovered_plugs
    
    def add_plugs_to_database(self, plugs):
        """Add discovered plugs to database"""
        if not plugs:
            self.logger.info("No plugs found to add")
            return
        
        try:
            conn = mysql.connector.connect(**self.db_config)
            cursor = conn.cursor()
            
            added_count = 0
            updated_count = 0
            
            for plug in plugs:
                # Generate a plug_id from the alias or IP
                plug_id = plug['alias'].lower().replace(' ', '_').replace('-', '_')
                if not plug_id or len(plug_id) < 3:
                    plug_id = f"kasa_plug_{plug['ip_address'].replace('.', '_')}"
                
                # Check if plug already exists
                cursor.execute("SELECT plug_id FROM kasa_plugs WHERE plug_id = %s OR ip_address = %s", 
                             (plug_id, plug['ip_address']))
                existing = cursor.fetchone()
                
                if existing:
                    # Update existing plug
                    cursor.execute("""
                        UPDATE kasa_plugs 
                        SET display_name = %s, ip_address = %s, location = %s, updated_at = NOW()
                        WHERE plug_id = %s OR ip_address = %s
                    """, (plug['alias'], plug['ip_address'], f"Auto-discovered {plug['model']}", 
                          plug_id, plug['ip_address']))
                    updated_count += 1
                    self.logger.info(f"Updated plug: {plug['alias']}")
                else:
                    # Add new plug
                    cursor.execute("""
                        INSERT INTO kasa_plugs (plug_id, display_name, ip_address, location)
                        VALUES (%s, %s, %s, %s)
                    """, (plug_id, plug['alias'], plug['ip_address'], 
                          f"Auto-discovered {plug['model']}"))
                    added_count += 1
                    self.logger.info(f"Added new plug: {plug['alias']}")
            
            conn.commit()
            cursor.close()
            conn.close()
            
            self.logger.info(f"Discovery complete: {added_count} added, {updated_count} updated")
            
        except Exception as e:
            self.logger.error(f"Database error: {e}")
    
    async def run_discovery(self):
        """Run the complete discovery process"""
        self.logger.info("=== Kasa Plug Auto-Discovery ===")
        
        # Discover plugs
        plugs = await self.discover_plugs()
        
        if plugs:
            self.logger.info(f"Found {len(plugs)} Kasa plugs")
            
            # Show what we found
            for i, plug in enumerate(plugs, 1):
                self.logger.info(f"{i}. {plug['alias']} - {plug['ip_address']} ({plug['model']})")
            
            # Add to database
            self.add_plugs_to_database(plugs)
        else:
            self.logger.info("No Kasa plugs found on network")
        
        self.logger.info("=== Discovery Complete ===")

if __name__ == '__main__':
    discovery = KasaDiscovery()
    asyncio.run(discovery.run_discovery())
