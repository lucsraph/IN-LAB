<?php
// ============================================================
//  Transactions API - transactions.php
//  Path: C:/xampp/htdocs/rfid_inventory/api/transactions.php
// ============================================================
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$db = getDB();

$stmt = $db->query("
    SELECT 
        t.id,
        i.item_name,
        i.rfid_uid AS item_uid,
        t.action,
        t.borrow_date,
        t.return_date,
        t.due_date,
        t.status,
        t.created_at,
        u.full_name AS borrower,
        u.student_id
    FROM transactions t
    JOIN items i ON t.item_id = i.id
    LEFT JOIN users u ON t.user_id = u.id
    ORDER BY t.created_at DESC
    LIMIT 50
");

$transactions = $stmt->fetchAll();

// Summary counts
$today = date('Y-m-d');
$borrows = $db->query("SELECT COUNT(*) FROM transactions WHERE action='borrow' AND DATE(created_at)='$today'")->fetchColumn();
$returns = $db->query("SELECT COUNT(*) FROM transactions WHERE action='return' AND DATE(created_at)='$today'")->fetchColumn();
$errors  = $db->query("SELECT COUNT(*) FROM system_logs WHERE result='error' AND DATE(logged_at)='$today'")->fetchColumn();

jsonResponse([
    'transactions'   => $transactions,
    'today_borrows'  => (int)$borrows,
    'today_returns'  => (int)$returns,
    'today_errors'   => (int)$errors,
]);
