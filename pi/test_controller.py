#!/usr/bin/env python3
"""
Simple test controller to debug issues
"""

import json
import logging
import subprocess
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler
import threading

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger('TestController')

class TestCommandHandler(BaseHTTPRequestHandler):
    def do_POST(self):
        content_length = int(self.headers['Content-Length'])
        post_data = self.rfile.read(content_length)
        
        try:
            data = json.loads(post_data.decode('utf-8'))
            command = data.get('command')
            
            if command == 'test':
                result = {'success': True, 'message': 'Test controller working'}
            elif command == 'list_plugs':
                result = {'success': True, 'plugs': {}, 'count': 0}
            else:
                result = {'success': False, 'error': f'Unknown command: {command}'}
            
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps(result).encode())
            
        except Exception as e:
            self.send_response(500)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({'success': False, 'error': str(e)}).encode())
    
    def log_message(self, format, *args):
        pass

def start_server():
    server = HTTPServer(('0.0.0.0', 8080), TestCommandHandler)
    logger.info("Test controller started on port 8080")
    server.serve_forever()

if __name__ == '__main__':
    logger.info("Starting test controller")
    start_server()
