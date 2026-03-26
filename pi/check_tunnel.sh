#!/bin/bash
# Health check for SCADA tunnels (MySQL 3306 and Controller 8081)
# Run via cron every 2 minutes: */2 * * * * /home/patrick/scada-hmi/pi/check_tunnel.sh

restart_needed=false

if ! nc -z 127.0.0.1 3306 2>/dev/null; then
    logger -t scada "MySQL tunnel down"
    restart_needed=true
fi

if ! nc -z 127.0.0.1 8081 2>/dev/null; then
    logger -t scada "Controller tunnel down"
    restart_needed=true
fi

if [ "$restart_needed" = true ]; then
    logger -t scada "Restarting scada service to rebuild tunnels"
    sudo systemctl restart scada
fi
