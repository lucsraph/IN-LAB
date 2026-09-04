<?php
// ============================================================
//  Activity Logs API - logs.php
//  Path: C:/xampp/htdocs/rfid_inventory/api/logs.php
// ============================================================
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$db   = getDB();
$logs = $db->query("
    SELECT * FROM system_logs
    ORDER BY logged_at DESC
    LIMIT 100
")->fetchAll();

jsonResponse(['logs' => $logs]);
