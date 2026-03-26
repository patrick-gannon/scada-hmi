#!/bin/bash
# SCADA tunnel and logger wrapper script
# Handles SSH tunnel lifecycle properly without leaving orphans

SSH_USER="${SSH_USER:-pgannon}"
SSH_HOST="${SSH_HOST:-69.48.207.60}"
LOG_FILE="/home/patrick/scada_tunnel.log"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

# Kill existing tunnels
cleanup() {
    log "Cleaning up SSH tunnels..."
    pkill -f "ssh.*-L 3306:127.0.0.1:3306" 2>/dev/null || true
    pkill -f "ssh.*-R 8081:localhost:8081" 2>/dev/null || true
    sleep 1
}

# Start tunnels
start_tunnels() {
    log "Starting SSH tunnels..."
    
    # MySQL forward tunnel (Pi -> VPS)
    ssh -f -N -o ServerAliveInterval=60 -o ServerAliveCountMax=3 \
        -o ExitOnForwardFailure=yes \
        -L 3306:127.0.0.1:3306 \
        "${SSH_USER}@${SSH_HOST}" &
    
    # Controller reverse tunnel (VPS -> Pi)  
    ssh -f -N -o ServerAliveInterval=60 -o ServerAliveCountMax=3 \
        -o ExitOnForwardFailure=yes \
        -R 8081:localhost:8081 \
        "${SSH_USER}@${SSH_HOST}" &
    
    sleep 2
}

# Verify tunnels are working
verify_tunnels() {
    for i in {1..10}; do
        if nc -z 127.0.0.1 3306 && nc -z 127.0.0.1 8081; then
            log "Tunnels verified"
            return 0
        fi
        sleep 1
    done
    log "Tunnel verification failed"
    return 1
}

# Main
log "SCADA service starting"
cleanup
start_tunnels

if ! verify_tunnels; then
    log "Failed to establish tunnels, exiting"
    cleanup
    exit 1
fi

log "Starting log_environment.py"
# Run the main Python script in foreground so systemd can track it
exec /usr/bin/python3 /home/patrick/scada-hmi/pi/log_environment.py
