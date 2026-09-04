<?php
// ============================================================
//  Live Feed API - feed.php
//  Path: C:/xampp/htdocs/rfid_inventory/api/feed.php
//
//  GET ?after=0  → returns all logs with id > 0 (latest 20)
//  GET ?after=55 → returns only new logs since id 55
//  This lets the dashboard poll every 3 seconds efficiently
//  without re-fetching data it already has.
// ============================================================
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate'); // prevent browser caching

$afterId = isset($_GET['after']) ? (int)$_GET['after'] : 0;
$db      = getDB();

// Join with transactions + items + users to get rich feed data
$stmt = $db->prepare("
    SELECT
        sl.id,
        sl.rfid_uid,
        sl.action,
        sl.result,
        sl.message,
        sl.ip_address,
        sl.logged_at,
        i.item_name,
        u.full_name  AS borrower
    FROM system_logs sl
    LEFT JOIN items i ON sl.rfid_uid = i.rfid_uid
    LEFT JOIN users u ON (
        SELECT t.user_id FROM transactions t
        JOIN items ti ON t.item_id = ti.id
        WHERE ti.rfid_uid = sl.rfid_uid
          AND t.action = sl.action
        ORDER BY t.created_at DESC
        LIMIT 1
    ) = u.id
    WHERE sl.id > ?
    ORDER BY sl.id DESC
    LIMIT 20
");
$stmt->execute([$afterId]);
$logs = $stmt->fetchAll();

jsonResponse([
    'logs'  => $logs,
    'count' => count($logs),
    'since' => $afterId,
]);
