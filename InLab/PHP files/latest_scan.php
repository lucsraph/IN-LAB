<?php
// ============================================================
//  Latest Scan API - latest_scan.php
//
//  Two modes:
//  GET  ?check=UID   → check if a UID is already registered
//  GET  (no params)  → return the most recently scanned UID
//                      that is NOT yet registered anywhere
//
//  The ESP32 posts unknown UIDs here via POST uid=XX:XX
//  so the register page can auto-detect freshly scanned cards.
// ============================================================
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

$db = getDB();

// ── ESP32 POST: store a freshly scanned unknown UID ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = strtoupper(trim($_POST['uid'] ?? ''));
    if (!$uid) jsonResponse(['status' => 'error', 'message' => 'uid required'], 400);

    // Store in system_logs with action='pending_register'
    // Only insert if not already registered anywhere
    $isItem = $db->prepare("SELECT id FROM item_units WHERE rfid_uid = ?");
    $isItem->execute([$uid]);
    $isUser = $db->prepare("SELECT id FROM users WHERE rfid_uid = ?");
    $isUser->execute([$uid]);

    if ($isItem->fetch() || $isUser->fetch()) {
        jsonResponse(['status' => 'already_registered']);
    }

    // Delete any older pending entries for this UID to keep table clean
    $db->prepare("DELETE FROM system_logs WHERE action='pending_register' AND rfid_uid=?")
       ->execute([$uid]);

    $db->prepare("INSERT INTO system_logs (rfid_uid, action, result, message, ip_address)
                  VALUES (?, 'pending_register', 'warning', 'Unregistered card scanned', ?)")
       ->execute([$uid, $_SERVER['REMOTE_ADDR']]);

    jsonResponse(['status' => 'received', 'uid' => $uid]);
}

// ── GET ?check=UID: is this UID already registered? ───────────
if (isset($_GET['check'])) {
    $uid = strtoupper(trim($_GET['check']));

    $itemQ = $db->prepare("
        SELECT u.rfid_uid, u.unit_label, t.item_name
        FROM item_units u
        JOIN item_types t ON u.item_type_id = t.id
        WHERE u.rfid_uid = ?");
    $itemQ->execute([$uid]);
    $item = $itemQ->fetch();

    if ($item) {
        jsonResponse([
            'registered'    => true,
            'registered_as' => 'Item',
            'name'          => $item['item_name'] . ' — ' . $item['unit_label'],
        ]);
    }

    $userQ = $db->prepare("SELECT full_name, student_id FROM users WHERE rfid_uid = ?");
    $userQ->execute([$uid]);
    $user = $userQ->fetch();

    if ($user) {
        jsonResponse([
            'registered'    => true,
            'registered_as' => 'Borrower',
            'name'          => $user['full_name'] . ' (' . ($user['student_id'] ?? 'no ID') . ')',
        ]);
    }

    jsonResponse(['registered' => false]);
}

// ── GET (no params): return latest unregistered scanned UID ───
$stmt = $db->prepare("
    SELECT rfid_uid, logged_at
    FROM system_logs
    WHERE action = 'pending_register'
    ORDER BY logged_at DESC
    LIMIT 1");
$stmt->execute();
$row = $stmt->fetch();

if ($row) {
    // Only return UIDs scanned within the last 60 seconds
    $age = time() - strtotime($row['logged_at']);
    if ($age <= 60) {
        jsonResponse(['uid' => $row['rfid_uid'], 'scanned_at' => $row['logged_at']]);
    }
}

jsonResponse(['uid' => null]);
