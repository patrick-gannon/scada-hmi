# SCADA/HMI Project Brief

## Project Goal
Build a full SCADA/HMI system from scratch as a portfolio project for Mohawk College
Automation Engineering Technology (403). Demonstrates real-world industrial controls
and automation concepts using affordable hardware and open source software.

## Current Status
Phase 1 complete, Phase 2 in progress.

---

## Completed

### Hardware
- Raspberry Pi 4 Model B
- AHT10 Temperature & Humidity Sensor (I2C, address 0x38)
- Wiring: SDA → GPIO2, SCL → GPIO3, VCC → 3.3V, GND → GND

### Software
- I2C enabled via raspi-config
- Adafruit AHTx0 CircuitPython library
- Python logger script reads sensor and writes to MySQL
- SSH tunnel from Pi to VPS for secure database connection
- SSH key authentication (passwordless)
- systemd service (scada.service) for auto-start and auto-restart on reboot

### Database (MySQL on VPS)
- Database: `scada`
- Table: `environment` — stores node_id, temperature, humidity, recorded_at
- Table: `settings` — dynamic operator controls read by Pi each loop
  - `log_interval` — scan rate in seconds (default 300)
  - `logging_active` — start/stop logging (1=active, 0=paused)
- Table: `audit_log` — tracks all changes to settings table
  - MySQL trigger `settings_audit` auto-logs old/new values on every update
  - HMI will write username when operator makes changes

### Multi-Node Support
- Each Pi has a local `scada_config.ini` (gitignored) with node_id and location
- `scada_config.template.ini` is committed to GitHub as a safe reference
- Script reads identity from config — identical script runs on every node
- Nodes differentiated by node_id in the environment table

### GitHub
- Repo: https://github.com/patrick-gannon/scada-hmi
- Sensitive config excluded via .gitignore
- schema.sql allows full database rebuild with one command

---

## In Progress
- Phase 2: Grafana dashboard connected to MySQL
  - Temperature and humidity trends per node
  - Filter by node_id

---

## Planned

### HMI Application
- Web-based interface (framework TBD)
- Operator controls:
  - Start/stop logging
  - Adjust scan rate (log_interval)
  - Set alarm thresholds per node
- User authentication (operator vs admin roles)
- Audit trail display
- All HMI actions write username to audit_log

### Alarm Management
- Threshold settings stored in database
- Notifications:
  - Email alerts
  - Discord webhook messages
  - Kasa smart plug interaction (on/off based on conditions)
- Alarm historian — log every threshold breach with timestamp

### Kasa Smart Plug Monitoring
- python-kasa library
- Monitor plug state and power usage
- Log to separate table in MySQL
- Display in Grafana alongside environment data

### Future Hardware
- Additional Pi nodes in different locations
- Potentially different sensor types on future nodes
- MQTT protocol for node communication (industry standard)

---

## Design Decisions

| Decision | Reason |
|---|---|
| SSH tunnel instead of direct MySQL port | More secure, common in production systems |
| Config file per node | Identical scripts across all nodes, only config differs |
| Settings in database | HMI can change scan rate without touching Pi code |
| Audit trail | Accountability, troubleshooting, mirrors industrial requirements |
| systemd service | Proper Linux service management, auto-restart on crash |
| Single MySQL database | Central historian, easy to query across all nodes |

---

## Industry Concepts Demonstrated

| This Project | Industry Equivalent |
|---|---|
| AHT10 on Pi | Field sensor / RTU |
| MySQL on VPS | Historian database (OSIsoft PI) |
| Grafana | SCADA trending screen |
| HMI with setpoints | Operator workstation |
| Email/Discord alerts | Alarm management system |
| Kasa plug control | PLC output / actuator |
| Audit log | Regulatory compliance logging |
| Multi-node support | Distributed control system |

---

## Tech Stack
- Python 3
- Raspberry Pi 4 Model B
- AHT10 sensor (I2C)
- MySQL 8 on Ubuntu VPS
- Grafana (planned)
- systemd
- SSH tunneling
- Git / GitHub

## Author
Patrick Gannon
