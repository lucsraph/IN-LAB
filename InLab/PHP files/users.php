<?php
// ============================================================
//  Users/Borrowers API - users.php
//  Path: C:/xampp/htdocs/rfid_inventory/api/users.php
//  GET              → list all users
//  POST action=add  → register new borrower
//  POST action=delete → remove borrower
// ============================================================
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: List all users ────────────────────────────────────────────────────
if ($method === 'GET') {
    $users = $db->query("SELECT * FROM users ORDER BY full_name")->fetchAll();
    jsonResponse(['users' => $users]);
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD new borrower ───────────────────────────────────────────────────
    if ($action === 'add') {
        $uid   = strtoupper(trim($_POST['rfid_uid']  ?? ''));
        $name  = trim($_POST['full_name']  ?? '');

        if (empty($uid) || empty($name)) {
            jsonResponse(['status' => 'error', 'message' => 'RFID UID and Full Name are required'], 400);
        }

        // Check for duplicate UID
        $check = $db->prepare("SELECT id FROM users WHERE rfid_uid = ?");
        $check->execute([$uid]);
        if ($check->fetch()) {
            jsonResponse(['status' => 'error', 'message' => 'This RFID UID is already registered to another user'], 409);
        }

        $stmt = $db->prepare("
            INSERT INTO users (rfid_uid, full_name, student_id, department, email, phone, role)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $uid,
            $name,
            trim($_POST['student_id']  ?? ''),
            trim($_POST['department']  ?? ''),
            trim($_POST['email']       ?? ''),
            trim($_POST['phone']       ?? ''),
            $_POST['role']             ?? 'student',
        ]);

        jsonResponse([
            'status'  => 'success',
            'id'      => $db->lastInsertId(),
            'message' => 'Borrower registered successfully'
        ]);
    }

    // ── DELETE borrower ────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid ID'], 400);
        }

        // Check if user has active borrows before deleting
        $active = $db->prepare("
            SELECT COUNT(*) FROM transactions WHERE user_id = ? AND status = 'active'
        ");
        $active->execute([$id]);
        if ((int)$active->fetchColumn() > 0) {
            jsonResponse([
                'status'  => 'error',
                'message' => 'Cannot remove: this user has active borrowed items'
            ], 409);
        }

        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        jsonResponse(['status' => 'success', 'message' => 'Borrower removed']);
    }

    // ── UPDATE borrower ────────────────────────────────────────────────────
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid ID'], 400);
        }

        $db->prepare("
            UPDATE users SET full_name=?, student_id=?, department=?, email=?, phone=?, role=?
            WHERE id=?
        ")->execute([
            trim($_POST['full_name']  ?? ''),
            trim($_POST['student_id'] ?? ''),
            trim($_POST['department'] ?? ''),
            trim($_POST['email']      ?? ''),
            trim($_POST['phone']      ?? ''),
            $_POST['role']            ?? 'student',
            $id
        ]);
        jsonResponse(['status' => 'success', 'message' => 'Borrower updated']);
    }

    jsonResponse(['status' => 'error', 'message' => 'Unknown action'], 400);
}
