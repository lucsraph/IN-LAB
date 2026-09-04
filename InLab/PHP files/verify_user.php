<?php
// ============================================================
//  Verify User Endpoint - verify_user.php
//  Path: C:/xampp/htdocs/rfid_inventory/api/verify_user.php
//  Called by ESP32 after Step 1 scan (User ID card)
//  POST: uid=XX:XX:XX:XX
//  Returns: { status: "found", name: "Juan Dela Cruz" }
//        or { status: "not_found" }
// ============================================================

require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'POST required']);
}

$uid = isset($_POST['uid']) ? strtoupper(trim($_POST['uid'])) : '';

if (empty($uid)) {
    jsonResponse(['status' => 'error', 'message' => 'UID required']);
}

$db   = getDB();
$stmt = $db->prepare("SELECT id, full_name, student_id, department FROM users WHERE rfid_uid = ? AND is_active = 1");
$stmt->execute([$uid]);
$user = $stmt->fetch();

if ($user) {
    jsonResponse([
        'status'     => 'found',
        'name'       => $user['full_name'],
        'student_id' => $user['student_id'],
        'department' => $user['department'],
    ]);
} else {
    jsonResponse(['status' => 'not_found']);
}
