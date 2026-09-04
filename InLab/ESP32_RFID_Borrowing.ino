/*
 * RFID-ESP32 Borrowing & Inventory Management System
 * Components: ESP32, MFRC522, LCD I2C (16x2), Red/Blue/Green LEDs
 * Backend: XAMPP (Apache + MySQL + PHP)
 */

#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

// ─── WiFi Credentials ──────────────────────────────────────────────────────
const char* ssid     = "YOUR_WIFI_SSID";
const char* password = "YOUR_WIFI_PASSWORD";

// ─── Server Configuration ──────────────────────────────────────────────────
// Replace with your PC's local IP address (find via ipconfig / ifconfig)
const char* serverIP = "192.168.1.100";
const int   serverPort = 80;
String baseURL = "http://" + String(serverIP) + "/rfid_inventory/api/";

#define DEFAULT_BORROW_DAYS 3   // Days before item is considered overdue

// ─── Pin Definitions ───────────────────────────────────────────────────────
// MFRC522 SPI Pins (VSPI)
#define SS_PIN   5    // SDA/CS
#define RST_PIN  4    // RST (moved from GPIO22 — was conflicting with LCD SCL)

// LED Pins
#define LED_GREEN 25  // Successful borrow
#define LED_BLUE  26  // Successful return
#define LED_RED   27  // Error / unsuccessful

// Buzzer (optional)
#define BUZZER_PIN 32

// ─── Objects ───────────────────────────────────────────────────────────────
MFRC522 mfrc522(SS_PIN, RST_PIN);
LiquidCrystal_I2C lcd(0x27, 16, 2); // I2C address 0x27, 16 cols, 2 rows

// ─── Custom LCD Characters ─────────────────────────────────────────────────
byte checkMark[8] = {0,0,1,2,20,8,0,0};
byte crossMark[8] = {0,17,10,4,10,17,0,0};

// ─── Setup ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);

  // LED Pins
  pinMode(LED_GREEN,  OUTPUT);
  pinMode(LED_BLUE,   OUTPUT);
  pinMode(LED_RED,    OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  allLedsOff();

  // LCD Init
  Wire.begin(21, 22); // SDA=21, SCL=22 (default ESP32)
  lcd.init();
  lcd.backlight();
  lcd.createChar(0, checkMark);
  lcd.createChar(1, crossMark);
  lcdMsg("RFID Inventory", "  Initializing..");

  // SPI + RFID
  SPI.begin(18, 19, 23, SS_PIN); // SCK, MISO, MOSI, SS
  mfrc522.PCD_Init();

  // WiFi
  connectWiFi();

  lcdMsg("Scan User ID", "to begin...");
  Serial.println("System Ready - Waiting for User ID card...");
}

// ─── State Machine ─────────────────────────────────────────────────────────
enum ScanState { WAIT_USER, WAIT_ITEM };
ScanState scanState   = WAIT_USER;
String    scannedUser = "";               // UID of the user card
unsigned long stepTime = 0;              // When user card was scanned
const unsigned long TIMEOUT_MS = 10000; // 10-second timeout

// ─── Main Loop ─────────────────────────────────────────────────────────────
void loop() {

  // ── WiFi watchdog — checks & auto-reconnects in background ─────────────
  checkWiFiConnection();

  // ── Timeout check ──────────────────────────────────────────────────────
  if (scanState == WAIT_ITEM && (millis() - stepTime > TIMEOUT_MS)) {
    Serial.println("Timeout! No item scanned.");
    lcdMsg("Timeout!", "Transaction cancelled");
    errorSignal();
    resetState();
    return;
  }

  if (!mfrc522.PICC_IsNewCardPresent() || !mfrc522.PICC_ReadCardSerial()) {
    delay(50);
    return;
  }

  String uid = getUID();
  Serial.println("Card UID: " + uid);

  mfrc522.PICC_HaltA();
  mfrc522.PCD_StopCrypto1();

  // ── Step 1: Waiting for User ID ────────────────────────────────────────
  if (scanState == WAIT_USER) {
    // Verify this UID belongs to a registered user
    if (verifyUser(uid)) {
      scannedUser = uid;
      scanState   = WAIT_ITEM;
      stepTime    = millis();
      lcdMsg("Hello! Now scan", "the item...");
      Serial.println("User verified: " + uid + " — waiting for item...");
    } else {
      lcdMsg("Unknown Card!", "Register first");
      errorSignal();
      // Forward the unknown UID to the dashboard register page
      reportUnknownUID(uid);
      // stay in WAIT_USER
    }
    return;
  }

  // ── Step 2: Waiting for Item ───────────────────────────────────────────
  if (scanState == WAIT_ITEM) {
    // Make sure user doesn't scan their own card again by mistake
    if (uid == scannedUser) {
      lcdMsg("Scan the ITEM,", "not your ID!");
      delay(1500);
      lcdMsg("Scan item now", "(" + String((TIMEOUT_MS - (millis()-stepTime))/1000) + "s left)");
      return;
    }
    // If item UID is also unknown, report it too so it can be registered
    processTransaction(scannedUser, uid);
    resetState();
  }
}

// ─── Reset back to initial state ───────────────────────────────────────────
void resetState() {
  scanState   = WAIT_USER;
  scannedUser = "";
  stepTime    = 0;
  delay(2000);
  lcdMsg("Scan User ID", "to begin...");
}

// ─── Verify User via HTTP ───────────────────────────────────────────────────
bool verifyUser(String userUID) {
  if (WiFi.status() != WL_CONNECTED) {
    lcdMsg("No WiFi!", "Reconnecting...");
    errorSignal();
    return false;
  }

  HTTPClient http;
  http.begin(baseURL + "verify_user.php");
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  int code = http.POST("uid=" + userUID);
  if (code == HTTP_CODE_OK) {
    String resp = http.getString();
    http.end();
    StaticJsonDocument<128> doc;
    deserializeJson(doc, resp);
    return String(doc["status"].as<String>()) == "found";
  }
  http.end();
  return false;
}

// ─── Report unregistered UID to dashboard register page ────────────────────
void reportUnknownUID(String uid) {
  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;
  http.begin(baseURL + "latest_scan.php");
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.POST("uid=" + uid);   // fire-and-forget, don't care about response
  http.end();
  Serial.println("Reported unknown UID to register page: " + uid);
}


void processTransaction(String userUID, String itemUID) {
  if (WiFi.status() != WL_CONNECTED) {
    lcdMsg("No WiFi!", "Try again soon");
    errorSignal();
    return;
  }

  HTTPClient http;
  http.begin(baseURL + "process.php");
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String postData = "user_uid=" + userUID + "&item_uid=" + itemUID;
  int httpCode = http.POST(postData);

  if (httpCode == HTTP_CODE_OK) {
    String response = http.getString();
    Serial.println("Server Response: " + response);
    parseResponse(response, itemUID);
  } else {
    Serial.println("HTTP Error: " + String(httpCode));
    lcdMsg("Server Error!", "Check Connection");
    errorSignal();
  }

  http.end();
}

// ─── Parse JSON Response ────────────────────────────────────────────────────
void parseResponse(String json, String itemUID) {
  StaticJsonDocument<256> doc;
  DeserializationError err = deserializeJson(doc, json);

  if (err) {
    Serial.println("JSON Parse Error");
    lcdMsg("Parse Error!", "Try Again");
    errorSignal();
    return;
  }

  String status   = doc["status"].as<String>();
  String message  = doc["message"].as<String>();
  String itemName = doc["item_name"].as<String>();
  String borrower = doc["borrower"].as<String>();
  String action   = doc["action"].as<String>();

  Serial.println("Status: " + status + " | Action: " + action + " | User: " + borrower);

  if (status == "success") {
    if (action == "borrow") {
      lcdMsg("Borrowed!", itemName.substring(0, 16));
      delay(1500);
      lcdMsg("By: " + borrower.substring(0, 12), "Due: " + String(DEFAULT_BORROW_DAYS) + " days");
      borrowSignal();
    } else if (action == "return") {
      lcdMsg("Returned!", itemName.substring(0, 16));
      delay(1500);
      lcdMsg("By: " + borrower.substring(0, 12), "Thank you!");
      returnSignal();
    }
  } else if (status == "error") {
    lcdMsg("ERROR!", message.substring(0, 16));
    errorSignal();
  } else if (status == "not_found") {
    lcdMsg("Unknown Item!", itemUID.substring(0, 16));
    errorSignal();
  }
}

// ─── LED & Buzzer Signals ───────────────────────────────────────────────────
void borrowSignal() {
  allLedsOff();
  digitalWrite(LED_GREEN, HIGH);
  tone(BUZZER_PIN, 1000, 200);
  delay(2000);
  allLedsOff();
}

void returnSignal() {
  allLedsOff();
  digitalWrite(LED_BLUE, HIGH);
  tone(BUZZER_PIN, 1200, 200);
  delay(300);
  tone(BUZZER_PIN, 1500, 200);
  delay(2000);
  allLedsOff();
}

void errorSignal() {
  allLedsOff();
  digitalWrite(LED_RED, HIGH);
  tone(BUZZER_PIN, 400, 500);
  delay(1500);
  allLedsOff();
}

void allLedsOff() {
  digitalWrite(LED_GREEN, LOW);
  digitalWrite(LED_BLUE,  LOW);
  digitalWrite(LED_RED,   LOW);
}

// ─── Helpers ────────────────────────────────────────────────────────────────
String getUID() {
  String uid = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(mfrc522.uid.uidByte[i], HEX);
    if (i < mfrc522.uid.size - 1) uid += ":";
  }
  uid.toUpperCase();
  return uid;
}

void lcdMsg(String line1, String line2) {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(line1.substring(0, 16));
  lcd.setCursor(0, 1);
  lcd.print(line2.substring(0, 16));
}

// ─── WiFi Watchdog ──────────────────────────────────────────────────────────
// Checks connection every 5 seconds without blocking the main loop
#define WIFI_CHECK_INTERVAL  5000   // Check every 5 seconds
#define WIFI_RETRY_INTERVAL  10000  // Retry attempt every 10 seconds
#define WIFI_MAX_RETRIES     5      // Force full reconnect after 5 failed retries

unsigned long lastWifiCheck  = 0;
unsigned long lastWifiRetry  = 0;
int           wifiRetryCount = 0;
bool          wifiWasLost    = false;  // tracks if we just recovered

// ─── WiFi Status Check — called every loop, non-blocking ────────────────────
void checkWiFiConnection() {
  unsigned long now = millis();

  // Only check every WIFI_CHECK_INTERVAL ms
  if (now - lastWifiCheck < WIFI_CHECK_INTERVAL) return;
  lastWifiCheck = now;

  if (WiFi.status() == WL_CONNECTED) {
    // WiFi is fine — if we just recovered, show it
    if (wifiWasLost) {
      wifiWasLost    = false;
      wifiRetryCount = 0;
      Serial.println("WiFi Reconnected: " + WiFi.localIP().toString());
      lcdMsg("WiFi Restored!", WiFi.localIP().toString());
      delay(1500);
      // Return LCD to correct state based on scan state
      if (scanState == WAIT_USER) lcdMsg("Scan User ID", "to begin...");
      else                        lcdMsg("Hello! Now scan", "the item...");
    }
    return;
  }

  // ── WiFi is disconnected ────────────────────────────────────────────────
  wifiWasLost = true;
  Serial.println("WiFi disconnected! Retry #" + String(wifiRetryCount + 1));

  // Only retry every WIFI_RETRY_INTERVAL ms
  if (now - lastWifiRetry < WIFI_RETRY_INTERVAL) {
    // Show disconnected status on LCD briefly (don't interrupt a scan-in-progress)
    if (scanState == WAIT_USER) {
      lcdMsg("No WiFi!", "Retrying...");
    }
    return;
  }
  lastWifiRetry = now;
  wifiRetryCount++;

  // After too many soft retries, do a full WiFi reset
  if (wifiRetryCount >= WIFI_MAX_RETRIES) {
    Serial.println("Max retries reached — forcing full WiFi reset...");
    wifiRetryCount = 0;
    WiFi.disconnect(true);  // Full disconnect + clear credentials
    delay(500);
    WiFi.mode(WIFI_OFF);
    delay(500);
    WiFi.mode(WIFI_STA);
    delay(500);
  }

  // Attempt reconnect
  lcdMsg("Reconnecting...", String(wifiRetryCount) + " attempt(s)");
  Serial.println("Attempting WiFi reconnect...");
  WiFi.begin(ssid, password);
}

// ─── Initial WiFi Connect (blocking, only runs once at startup) ─────────────
void connectWiFi() {
  lcdMsg("Connecting WiFi", ssid);
  Serial.print("Connecting to WiFi");

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);   // ESP32 built-in auto-reconnect
  WiFi.persistent(true);         // Remember credentials across reboots
  WiFi.begin(ssid, password);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 40) {
    delay(500);
    Serial.print(".");
    attempts++;

    // Blink red LED while waiting
    digitalWrite(LED_RED, attempts % 2 == 0 ? HIGH : LOW);
  }
  allLedsOff();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWiFi Connected: " + WiFi.localIP().toString());
    lcdMsg("WiFi Connected!", WiFi.localIP().toString());
    delay(1500);
  } else {
    Serial.println("\nWiFi Failed! Will keep retrying in background...");
    lcdMsg("WiFi Failed!", "Will retry...");
    errorSignal();
    delay(1500);
    // Don't halt — system will retry in the background via checkWiFiConnection()
  }
}

