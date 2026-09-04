<?php
// ============================================================
//  Database Configuration
//  Path: C:/xampp/htdocs/rfid_inventory/config/db.php
// ============================================================

define('DB_HOST',     'localhost');
define('DB_USER',     'root');         // XAMPP default
define('DB_PASS',     '');             // XAMPP default (blank)
define('DB_NAME',     'rfid_inventory');
define('DB_CHARSET',  'utf8mb4');

// Default borrow duration in days
define('DEFAULT_BORROW_DAYS', 3);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['status' => 'error', 'message' => 'DB Connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data);
    exit;
}
