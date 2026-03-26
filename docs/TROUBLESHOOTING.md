# Troubleshooting Guide

## SSH Tunnel Issues

### Problem: Pi cannot connect to MySQL on VPS

**Symptoms:**
- Sensor data not appearing in HMI
- `log_environment.py` shows connection errors
- `nc -zv 127.0.0.1 3306` fails on Pi

**Check:**
```bash
# On Pi - verify tunnel process exists
ps aux | grep "ssh.*3306"

# Check tunnel port
nc -zv 127.0.0.1 3306

# Check service status
sudo systemctl status scada
sudo journalctl -u scada -n 20
```

**Fix:**
```bash
# Restart the scada service (recreates both tunnels)
sudo systemctl restart scada

# Or kill and recreate manually
pkill -f "ssh.*3306"
ssh -f -N -L 3306:127.0.0.1:3306 ${SSH_USER}@${SSH_HOST}
```

### Problem: VPS cannot reach Pi's Kasa controller

**Symptoms:**
- "Pi controller unavailable: HTTP 0" error
- Plug control fails from HMI
- `nc -zv 127.0.0.1 8081` fails on VPS

**Check:**
```bash
# On VPS - verify reverse tunnel port
nc -zv 127.0.0.1 8081

# On Pi - check local controller is listening
sudo ss -tlnp | grep 8081

# Check service status
sudo systemctl status local_controller
```

**Fix:**
```bash
# On Pi - restart both services
sudo systemctl restart scada
sudo systemctl restart local_controller
```

### Problem: Tunnels keep dying

**Symptoms:**
- Intermittent connection failures
- Services work after restart but fail later

**Check:**
```bash
# Verify check_tunnel.sh is running
crontab -l | grep check_tunnel

# Check logs
sudo tail /var/log/syslog | grep scada
```

**Fix:**
Ensure `check_tunnel.sh` is installed and running via cron:
```bash
# On Pi
cp pi/check_tunnel.sh ~/scada-hmi/pi/
chmod +x ~/scada-hmi/pi/check_tunnel.sh
crontab -e
# Add: */2 * * * * /home/patrick/scada-hmi/pi/check_tunnel.sh
```

## Environment Variables Not Set

**Symptoms:**
- Service starts but tunnels don't appear
- Logs show "SSH_HOST: unbound variable"

**Check:**
```bash
sudo systemctl cat scada | grep Environment
```

**Fix:**
Edit `/etc/systemd/system/scada.service` and ensure:
```ini
[Service]
Environment="SSH_USER=your_username"
Environment="SSH_HOST=your_vps_ip"
```

Then reload:
```bash
sudo systemctl daemon-reload
sudo systemctl restart scada
```

## Automation Trigger Issues

### Problem: Sensor triggers fire every minute

**Cause:** Missing cooldown logic in `kasa_control.php`

**Fix:** Ensure trigger cooldown is implemented:
```php
// Check cooldown - don't re-trigger within 1 hour
$recentCheck = $this->db->prepare("
    SELECT id FROM plug_actions_log 
    WHERE plug_id = ? AND trigger_name = ? 
    AND trigger_type = ?
    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
```

### Problem: Time schedules not executing

**Check:**
```bash
# Verify cron job exists
sudo crontab -l -u www-data | grep check_schedules

# Test manually
/usr/bin/php /var/www/html/environment-monitor/includes/check_schedules.php
```

**Fix:**
```bash
sudo crontab -e -u www-data
# Add: * * * * * /usr/bin/php /var/www/html/environment-monitor/includes/check_schedules.php
```

## Database Migration Issues

### Problem: "Unknown column 'days_of_week'"

**Fix:** Run migration script:
```sql
ALTER TABLE plug_triggers ADD COLUMN days_of_week VARCHAR(20) DEFAULT '0123456';
ALTER TABLE plug_actions_log ADD COLUMN trigger_name VARCHAR(80);
```

## Debug Logging

Enable debug logging temporarily:

```php
// In kasa_control.php or data.php
error_log("Debug message: " . $variable);
```

View logs:
```bash
# On VPS
sudo tail -f /var/log/apache2/error.log

# On Pi
sudo journalctl -u scada -f
```
