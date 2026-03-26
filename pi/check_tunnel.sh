#!/bin/bash
# Health check for SCADA tunnels and local controller
# Run via cron every 2 minutes: */2 * * * * /home/patrick/scada-hmi/pi/check_tunnel.sh

restart_needed=false

# Check MySQL tunnel (forward: Pi -> VPS)
if ! nc -z 127.0.0.1 3306 2>/dev/null; then
    logger -t scada "MySQL tunnel down"
    restart_needed=true
fi

# Check controller tunnel (reverse: VPS -> Pi)
if ! nc -z 127.0.0.1 8081 2>/dev/null; then
    logger -t scada "Controller tunnel down"
    restart_needed=true
fi

# Check if local_controller process is running
if ! pgrep -f "local_controller" > /dev/null; then
    logger -t scada "local_controller process not found"
    restart_needed=true
fi

# Check if local_controller HTTP is actually responding
# Send a test request - should return within 5 seconds
http_test=$(curl -s -m 5 -X POST \
    -H "Content-Type: application/json" \
    -d '{"command":"test"}' \
    http://127.0.0.1:8081 2>&1)

if [ $? -ne 0 ] || [ -z "$http_test" ]; then
    logger -t scada "local_controller HTTP not responding"
    restart_needed=true
else
    # Log successful health check occasionally (every 10 checks ~20 min)
    if [ $((RANDOM % 10)) -eq 0 ]; then
        logger -t scada "Health check OK: $(echo "$http_test" | head -c 100)"
    fi
fi

if [ "$restart_needed" = true ]; then
    logger -t scada "Restarting services to restore connectivity"
    # Kill any existing SSH tunnels to prevent duplicates
    pkill -f "ssh.*8081"
    pkill -f "ssh.*3306"
    sleep 1
    # Restart both services
    sudo systemctl restart local_controller
    sleep 2
    sudo systemctl restart scada
    logger -t scada "Services restarted"
fi
