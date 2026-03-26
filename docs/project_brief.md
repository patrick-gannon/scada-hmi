# SCADA/HMI Project Brief

## Project Goal
Build a full SCADA/HMI system from scratch as a portfolio / concept project . Demonstrates real-world industrial controls
and automation concepts using affordable hardware and open source software.

## Current Status
Phase 1 complete, Phase 2 in progress.

---

## Completed

### Phase 1: Core System
- Raspberry Pi 4 Model B with AHT10 temperature/humidity sensor
- MySQL database on VPS with SSH tunnel
- Python logger with systemd service
- Multi-node support with node IDs

### Phase 2: HMI & Visualization
- Web-based HMI with authentication (bcrypt, sessions)
- Role-based access (admin/viewer)
- Live data view with sparklines
- Per-node thresholds and alarm management
- Email and Discord alerts
- Audit trail logging
- User management
- **Grafana dashboard** — Temperature/humidity trending per node

### Phase 3: Automation System (Completed)
- **Kasa smart plug integration**
  - Manual control from HMI
  - Time-based schedules with day-of-week selection
  - Sensor triggers (temperature/humidity thresholds)
  - Action logging with trigger names
  - 1-hour cooldown between trigger firings
- **Automation tab** in HMI for schedule/trigger management
- **Cron jobs**: Time schedules (check_schedules.php) and sensor triggers (alerts.php)
- **Pi health monitoring**: check_tunnel.sh for automatic tunnel restart

---

## Planned / Future

### Visualization
- Grafana dashboard with temperature/humidity trends

### Hardware Expansion
- Additional Pi nodes in different locations
- Different sensor types on future nodes
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
