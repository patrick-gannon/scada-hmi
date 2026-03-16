import board
import adafruit_ahtx0
import mysql.connector
import time
import threading
from datetime import datetime
from dotenv import load_dotenv
import os

# Load .env file
load_dotenv(os.path.join(os.path.dirname(__file__), '.env'))

NODE_ID  = os.getenv('NODE_ID', 'node_01')
LOCATION = os.getenv('LOCATION', 'unknown')

db_config = {
    'host':     os.getenv('DB_HOST'),
    'user':     os.getenv('DB_USER'),
    'password': os.getenv('DB_PASSWORD'),
    'database': os.getenv('DB_NAME')
}

SETTINGS_POLL_RATE = 5  # seconds between settings refreshes

# Shared settings state — written by settings loop, read by log loop
settings_lock = threading.Lock()
shared_settings = {
    'log_interval': 300,
    'logging_active': True,
}


def fetch_settings():
    """Open a short-lived connection, pull settings, return as dict."""
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        cursor.execute("SELECT setting_name, setting_value FROM settings")
        rows = cursor.fetchall()
        cursor.close()
        conn.close()
        raw = {row[0]: row[1] for row in rows}
        return {
            'log_interval': int(raw.get('log_interval', 300)),
            'logging_active': raw.get('logging_active', '1') == '1',
        }
    except Exception as e:
        print(f"[Settings] Error fetching settings: {e}")
        return None  # None signals "keep using last known good values"


def settings_loop():
    """Polls the database every SETTINGS_POLL_RATE seconds and updates shared state."""
    global shared_settings
    print(f"[Settings] Polling loop started (every {SETTINGS_POLL_RATE}s)")
    while True:
        new_settings = fetch_settings()
        if new_settings is not None:
            with settings_lock:
                old = shared_settings.copy()
                shared_settings = new_settings
            # Log any changes so the operator can see them take effect
            if new_settings['log_interval'] != old['log_interval']:
                print(f"[Settings] log_interval changed: {old['log_interval']}s → {new_settings['log_interval']}s")
            if new_settings['logging_active'] != old['logging_active']:
                state = "ACTIVE" if new_settings['logging_active'] else "PAUSED"
                print(f"[Settings] logging_active changed → {state}")
        time.sleep(SETTINGS_POLL_RATE)


def log_loop(sensor):
    """Reads the sensor and writes to the database according to shared settings."""
    print(f"[Logger] Data loop started | Node: {NODE_ID} | Location: {LOCATION}")
    while True:
        # Snapshot shared settings at the top of each cycle
        with settings_lock:
            interval = shared_settings['log_interval']
            active   = shared_settings['logging_active']

        if active:
            try:
                temp     = round(sensor.temperature, 2)
                humidity = round(sensor.relative_humidity, 2)

                conn = mysql.connector.connect(**db_config)
                cursor = conn.cursor()
                cursor.execute(
                    "INSERT INTO environment (node_id, temperature, humidity) VALUES (%s, %s, %s)",
                    (NODE_ID, temp, humidity)
                )
                conn.commit()
                cursor.close()
                conn.close()

                print(f"{datetime.now()} | {NODE_ID} | Temp: {temp}°C | Humidity: {humidity}% | Saved to DB")

            except Exception as e:
                print(f"[Logger] Error: {e}")
                interval = 300  # fall back to safe interval on error
        else:
            print(f"{datetime.now()} | {NODE_ID} | Logging paused by HMI")

        # Sleep in small increments so a short new interval takes effect quickly
        # rather than waiting out the remainder of a long old interval.
        elapsed = 0
        while elapsed < interval:
            time.sleep(1)
            elapsed += 1
            # Re-read interval in case it changed mid-sleep
            with settings_lock:
                interval = shared_settings['log_interval']


def main():
    sensor = adafruit_ahtx0.AHTx0(board.I2C())
    print(f"SCADA logger starting | Node: {NODE_ID} | Location: {LOCATION}")

    # Do an initial settings fetch before starting so the log loop
    # has valid values from the very first cycle.
    initial = fetch_settings()
    if initial:
        with settings_lock:
            shared_settings.update(initial)

    # Settings loop runs as a daemon thread — it dies automatically
    # if the main process exits.
    t = threading.Thread(target=settings_loop, daemon=True)
    t.start()

    # Data loop runs on the main thread.
    log_loop(sensor)


if __name__ == "__main__":
    main()
