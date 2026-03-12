import board
import adafruit_ahtx0
import mysql.connector
import time
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

def get_settings(cursor):
    cursor.execute("SELECT setting_name, setting_value FROM settings")
    rows = cursor.fetchall()
    return {row[0]: row[1] for row in rows}

def main():
    sensor = adafruit_ahtx0.AHTx0(board.I2C())
    print(f"SCADA logger started | Node: {NODE_ID} | Location: {LOCATION}")

    while True:
        try:
            conn = mysql.connector.connect(**db_config)
            cursor = conn.cursor()

            settings = get_settings(cursor)
            interval = int(settings.get('log_interval', 300))
            active = settings.get('logging_active', '1') == '1'

            if active:
                temp = round(sensor.temperature, 2)
                humidity = round(sensor.relative_humidity, 2)

                cursor.execute(
                    "INSERT INTO environment (node_id, temperature, humidity) VALUES (%s, %s, %s)",
                    (NODE_ID, temp, humidity)
                )
                conn.commit()
                print(f"{datetime.now()} | {NODE_ID} | Temp: {temp}°C | Humidity: {humidity}% | Saved to DB")
            else:
                print(f"{datetime.now()} | {NODE_ID} | Logging paused by HMI")

        except Exception as e:
            print(f"Error: {e}")
            interval = 300

        finally:
            if conn.is_connected():
                cursor.close()
                conn.close()

        time.sleep(interval)

if __name__ == "__main__":
    main()
