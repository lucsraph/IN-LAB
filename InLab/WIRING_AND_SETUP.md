# RFID-ESP32 Borrowing & Inventory Management System
## Complete Wiring Diagram + Setup Guide

---

## 📦 Components List

| Component              | Qty | Notes                         |
|------------------------|-----|-------------------------------|
| ESP32 Dev Board        | 1   | 30-pin or 38-pin variant      |
| MFRC522 RFID Reader    | 1   | SPI interface, 3.3V           |
| LCD 16x2 with I2C PCF8574 | 1 | Address 0x27 or 0x3F      |
| Green LED + 220Ω R     | 1   | Successful Borrow signal      |
| Blue LED + 220Ω R      | 1   | Successful Return signal      |
| Red LED + 220Ω R       | 1   | Error / Unsuccessful signal   |
| Passive Buzzer         | 1   | Optional audio feedback       |
| Breadboard             | 1   |                               |
| Jumper Wires           | ~25 | Male-to-Male                  |
| RFID Cards/Tags        | —   | 13.56 MHz Mifare              |
| USB Micro-B Cable      | 1   | For programming/power         |

---

## 🔌 WIRING DIAGRAM

```
                   ┌──────────────────────────────────────────────┐
                   │              ESP32 Dev Board                  │
                   │                                               │
                   │   3V3 ●────────────┬──────────── ● 3V3 (LCD) │
                   │                    │                          │
                   │                    └──────────── ● VCC (RFID)│
                   │                                               │
                   │   GND ●────────────┬──────────── ● GND (LCD) │
                   │                    │                          │
                   │                    ├──────────── ● GND (RFID)│
                   │                    │                          │
                   │                    ├──[GND]─ LED Resistors    │
                   │                    │                          │
                   │                    └──────────── ● GND (Buzz) │
                   │                                               │
                   │   GPIO21(SDA)●──────────────── ● SDA (LCD)   │
                   │   GPIO22(SCL)●──────────────── ● SCL (LCD)   │
                   │                                               │
                   │   GPIO18(SCK) ●─────────────── ● SCK (RFID)  │
                   │   GPIO19(MISO)●─────────────── ● MISO(RFID)  │
                   │   GPIO23(MOSI)●─────────────── ● MOSI(RFID)  │
                   │   GPIO5  (SS) ●─────────────── ● SDA (RFID)  │
                   │   GPIO4  (RST)●─────────────── ● RST (RFID)  │
                   │                                               │
                   │   GPIO25 ●──[220Ω]──[GREEN LED]──● GND       │
                   │   GPIO26 ●──[220Ω]──[BLUE  LED]──● GND       │
                   │   GPIO27 ●──[220Ω]──[RED   LED]──● GND       │
                   │   GPIO32 ●──────────[BUZZER+]────● GND       │
                   └──────────────────────────────────────────────┘
```

---

## 📌 Pin Reference Table

### MFRC522 RFID Reader → ESP32
```
MFRC522 Pin │ ESP32 Pin  │ Description
────────────┼────────────┼─────────────────
SDA (CS)    │ GPIO 5     │ SPI Chip Select
SCK         │ GPIO 18    │ SPI Clock
MOSI        │ GPIO 23    │ SPI Master Out
MISO        │ GPIO 19    │ SPI Master In
IRQ         │ (unused)   │ Interrupt (not used)
GND         │ GND        │ Ground
RST         │ GPIO 4     │ Reset (moved from GPIO22 to avoid I2C conflict)
3.3V        │ 3V3        │ Power (NEVER 5V!)
```

> ⚠️ **WARNING**: MFRC522 is 3.3V ONLY. Connecting to 5V will destroy the module.

### LCD I2C 16x2 → ESP32
```
LCD I2C Pin │ ESP32 Pin  │ Description
────────────┼────────────┼─────────────────
VCC         │ 3V3 or 5V  │ Power
GND         │ GND        │ Ground
SDA         │ GPIO 21    │ I2C Data
SCL         │ GPIO 22    │ I2C Clock
```
> Note: GPIO22 is now exclusively used for LCD SCL. RFID RST is on GPIO4.

### LEDs → ESP32
```
LED Color   │ ESP32 Pin  │ Meaning
────────────┼────────────┼──────────────────────
Green (+)   │ GPIO 25    │ Successful BORROW
Blue  (+)   │ GPIO 26    │ Successful RETURN
Red   (+)   │ GPIO 27    │ Error / Failed scan
LED   (-)   │ GND (via 220Ω resistor)
```

### Buzzer → ESP32
```
Buzzer (+)  │ GPIO 32    │ Audio feedback
Buzzer (-)  │ GND        │
```

---

## 🖥️ XAMPP SERVER SETUP

### Step 1: Install XAMPP
1. Download XAMPP from https://www.apachefriends.org
2. Install with Apache + MySQL components
3. Start Apache and MySQL from XAMPP Control Panel

### Step 2: Create Database
1. Open phpMyAdmin: http://localhost/phpmyadmin
2. Click "New" → name it `rfid_inventory` → Create
3. Click "Import" → select `database/schema.sql`
4. Click "Go" to run the SQL

### Step 3: Deploy PHP Files
Copy this folder structure to `C:/xampp/htdocs/rfid_inventory/`:
```
rfid_inventory/
├── api/
│   ├── config/
│   │   └── db.php
│   ├── process.php        ← ESP32 calls this
│   ├── transactions.php
│   └── items.php
└── web/
    └── index.html         ← Open this in browser
```

### Step 4: Find Your PC's IP Address
Open Command Prompt:
```
ipconfig
```
Look for `IPv4 Address` (e.g., 192.168.1.100)
This is what you put in ESP32 code: `const char* serverIP = "192.168.1.100";`

### Step 5: Configure ESP32 Code
In `ESP32_RFID_Borrowing.ino`, update:
```cpp
const char* ssid     = "YOUR_WIFI_NAME";
const char* password = "YOUR_WIFI_PASSWORD";
const char* serverIP = "192.168.1.XXX";  // Your PC's IP
```

---

## 📚 ARDUINO LIBRARIES (Install via Library Manager)

| Library                      | Author        |
|------------------------------|---------------|
| MFRC522                      | GithubCommunity |
| LiquidCrystal_I2C            | Frank de Brabander |
| ArduinoJson                  | Benoit Blanchon |
| WiFi                         | Arduino (built-in for ESP32) |
| HTTPClient                   | Arduino (built-in for ESP32) |

Install: Arduino IDE → Sketch → Include Library → Manage Libraries

---

## 🔄 SYSTEM FLOW

```
ESP32 detects card
        │
        ▼
   Read RFID UID
        │
        ▼
POST /api/process.php
  uid=A1:B2:C3:D4
        │
        ▼
   PHP checks DB ──── UID not found ──→ RED LED + "Unknown Card"
        │
   UID found
        │
   ┌────┴────┐
   │         │
 available  borrowed
   │         │
   ▼         ▼
 BORROW    RETURN
   │         │
GREEN LED  BLUE LED
   │         │
 Update DB  Update DB
   │         │
   └────┬────┘
        │
   JSON response
   to ESP32 LCD
```

---

## 🌐 WEB DASHBOARD ACCESS

Open browser on any device connected to the same network:
```
http://192.168.1.100/rfid_inventory/web/index.html
```

Or from the XAMPP machine:
```
http://localhost/rfid_inventory/web/index.html
```

---

## 🔧 TROUBLESHOOTING

| Problem | Solution |
|---------|----------|
| LCD shows nothing | Check I2C address: try 0x27 or 0x3F |
| RFID not reading | Check SPI wires, confirm 3.3V not 5V |
| WiFi won't connect | Check SSID/password, same 2.4GHz network |
| HTTP 404 error | Verify file path in htdocs |
| HTTP 500 error | Check db.php credentials |
| LEDs not lighting | Check resistor polarity and GPIO pin numbers |

---

## 📋 I2C ADDRESS SCANNER

If LCD address is unknown, upload this to ESP32 first:
```cpp
#include <Wire.h>
void setup() {
  Serial.begin(115200);
  Wire.begin(21, 22);
  for (byte i = 1; i < 127; i++) {
    Wire.beginTransmission(i);
    if (Wire.endTransmission() == 0)
      Serial.printf("I2C device at 0x%02X\n", i);
  }
}
void loop() {}
```
