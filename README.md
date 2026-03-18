# SCADA/HMI Environment Monitoring System

A real-world SCADA system built on Raspberry Pi, MySQL, and Grafana — designed as a portfolio project for controls and automation engineering.

## Overview

This project demonstrates core SCADA/HMI concepts including distributed sensor nodes, a centralized historian database, operator controls, and an alarm management system.

## Hardware
In-Progress
<!-- ADD PHOTO: hardware_setup.jpg - Photo of Pi with AHT10 wired up -->

### Wiring
| AHT10 Pin | Raspberry Pi Pin |
|---|---|
| VCC | 3.3V (Pin 1) |
| GND | GND (Pin 6) |
| SDA | GPIO2 (Pin 3) |
| SCL | GPIO3 (Pin 5) |

<!-- ADD DIAGRAM: wiring_diagram.png - GPIO wiring diagram -->

## What is SCADA?

SCADA (Supervisory Control and Data Acquisition) is an industrial control system used to monitor and control equipment in industries like manufacturing, energy, and utilities. This project replicates those concepts on a small scale using a Raspberry Pi as a field device reporting to a central database and operator interface.

## Dashboard
Grafana
![Grafana Dashboard](docs/grafana_dashboard.png)

## HMI

A web-based operator interface deployed at [utilities.blue/environment-monitor](https://utilities.blue/environment-monitor/)

<!-- ADD SCREENSHOT: hmi_screenshot.png - Operator controls interface -->

### HMI Features

- **Login authentication** — bcrypt passwords, PHP sessions, Secure/HttpOnly cookies
- **Role-based access** — Admin (full control) and Viewer (read-only) levels
- **Live data view** — real-time temperature and humidity per node, auto-refreshes every 5 seconds
- **Sparklines** — 1-hour trend graph per node, per measurement
- **Online/offline detection** — nodes marked offline if no reading within 1.5× scan interval
- **Operator controls** — start/stop logging, adjust scan rate, all changes reflect on field devices immediately
- **Kasa smart plug controls** — trigger outlets from the HMI (stub, wired to audit trail)
- **Per-node thresholds** — set high/low limits for temperature and humidity per node or globally
- **Alarm historian** — all threshold breaches logged with 30-minute cooldown between alerts
- **Email alerts** — PHPMailer/Gmail SMTP notifications on threshold breach
- **Discord alerts** — webhook notifications (configure `DISCORD_WEBHOOK_URL` in `.env`)
- **Audit trail** — every operator action logged with username, old value, and new value
- **User management** — admins can create, disable, and change roles of operator accounts
- **Node display names** — human-readable aliases for node IDs, set from the HMI

## System Architecture

```
[AHT10 Sensor] → [Raspberry Pi Node] → [SSH Tunnel] → [MySQL on VPS] → [Grafana Dashboard]
                                                              ↑
                                                        [HMI Interface]
                                                        - Start/Stop logging
                                                        - Adjust scan rate
                                                        - Set alarm thresholds
                                                        - Email / Discord alerts
                                                        - Audit trail
```

## Features

- Multi-node support — each Pi is independently identified by node ID and location
- Dynamic scan rate — adjustable from HMI without touching field devices
- Remote start/stop — operator can pause/resume logging from HMI
- Audit trail — all operator actions logged with username, old and new values
- Auto-restart — systemd service restarts the logger on reboot or crash
- Alarm management — email and Discord alerts on threshold breach
- Kasa smart plug integration — outlet control from HMI (in progress)

## Tech Stack

| Component | Technology |
|---|---|
| Field Sensor | AHT10 Temperature & Humidity |
| Field Device | Raspberry Pi 4 Model B |
| Communication | SSH Tunnel / I2C |
| Database | MySQL on VPS |
| Visualization | Grafana |
| HMI | PHP, PDO, PHPMailer — Apache on VPS |
| Alerts | Email (Gmail SMTP) / Discord Webhook |
| Language | Python 3 (field) / PHP 8 (HMI) |

## Project Structure

```
scada-hmi/
├── pi/
│   ├── log_environment.py        # Main logging script
│   └── scada_config.template.ini # Config template (copy to scada_config.ini)
├── database/
│   ├── schema.sql                # Core database schema (environment, settings, audit_log)
│   └── hmi_schema.sql            # HMI additions (users, nodes, thresholds, alarm_log)
├── hmi/                          # Web-based operator interface
│   ├── index.php                 # Main dashboard
│   ├── login.php                 # Authentication
│   ├── api/
│   │   ├── auth.php              # Login / logout
│   │   ├── data.php              # GET endpoints (live, audit, alarms, thresholds)
│   │   └── control.php           # POST endpoints (settings, thresholds, users, Kasa)
│   ├── includes/
│   │   ├── db.php                # PDO database connection
│   │   ├── auth.php              # Session management and role checks
│   │   └── alerts.php            # Threshold checker and alert dispatcher (cron)
│   ├── .env.template             # Environment variable template
│   ├── .htaccess                 # Directory protection
│   ├── composer.json             # PHP dependencies (PHPMailer)
│   └── hmi_schema.sql            # HMI database tables
├── docs/                         # Wiring diagrams and documentation
└── README.md
```

## Getting Started

### Field Device (Raspberry Pi)

#### 1. Clone the repo
```bash
git clone https://github.com/patrick-gannon/scada-hmi.git
```

#### 2. Set up the database
```bash
mysql -u root -p < database/schema.sql
```

#### 3. Configure your node
```bash
cp pi/scada_config.template.ini pi/scada_config.ini
nano pi/scada_config.ini
```

#### 4. Install dependencies
```bash
pip3 install adafruit-circuitpython-ahtx0 mysql-connector-python --break-system-packages
```

#### 5. Run the logger
```bash
python3 pi/log_environment.py
```

#### 6. Set up as a service
```bash
sudo cp pi/scada.service /etc/systemd/system/
sudo systemctl enable scada
sudo systemctl start scada
```

---

### HMI (VPS / Web Server)

Requires: PHP 8.0+, extensions `pdo_mysql`, `curl`, `json`, Apache with `mod_alias` and `mod_rewrite`.

#### 1. Deploy files
```bash
sudo mkdir -p /var/www/html/environment-monitor
sudo cp -r hmi/* /var/www/html/environment-monitor/
sudo chown -R www-data:www-data /var/www/html/environment-monitor/
```

#### 2. Install PHP dependencies
```bash
cd /var/www/html/environment-monitor
composer install
```

#### 3. Configure environment
```bash
cp .env.template .env
nano .env        # fill in DB credentials, SMTP, Discord webhook
chmod 640 .env
chown www-data:www-data .env
```

#### 4. Set up HMI database tables
```bash
mysql -u your_db_user -p scada < hmi_schema.sql
```

#### 5. Configure Apache
Add to your SSL VirtualHost:
```apache
Alias /environment-monitor /var/www/html/environment-monitor

<Directory /var/www/html/environment-monitor>
    Options -Indexes -FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php
</Directory>

<Directory /var/www/html/environment-monitor/includes>
    Require all denied
</Directory>
```

#### 6. Set up alert cron job
```bash
sudo crontab -e -u www-data
# Add:
* * * * * /usr/bin/php /var/www/html/environment-monitor/includes/alerts.php >> /var/log/scada-alerts.log 2>&1
```

#### 7. Default login
```
Username: admin
Password: password  ← change immediately after first login
```

---

## Industry Concepts Demonstrated

| This Project | Industry Equivalent |
|---|---|
| AHT10 on Pi | Field sensor / RTU |
| MySQL on VPS | Historian database (OSIsoft PI) |
| Grafana | SCADA trending screen |
| HMI with setpoints | Operator workstation |
| Email / Discord alerts | Alarm management system |
| Kasa plug control | PLC output / actuator |
| Audit log | Regulatory compliance logging |
| Multi-node support | Distributed control system |
| Role-based access | Operator vs engineer access levels |

## Author

Patrick Gannon
