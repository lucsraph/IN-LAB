<?php
// ============================================================
//  Overdue Items API - overdue.php
//  Path: C:/xampp/htdocs/rfid_inventory/api/overdue.php
// ============================================================
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$db = getDB();

$overdue = $db->query("
    SELECT
        t.id AS transaction_id,
        i.item_name,
        i.rfid_uid,
        u.full_name  AS borrower,
        u.student_id,
        u.email,
        DATE_FORMAT(t.borrow_date, '%Y-%m-%d %H:%i') AS borrow_date,
        DATE_FORMAT(t.due_date,    '%Y-%m-%d %H:%i') AS due_date,
        DATEDIFF(NOW(), t.due_date) AS days_overdue
    FROM transactions t
    JOIN items i ON t.item_id = i.id
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.action = 'borrow'
      AND t.status = 'active'
      AND t.due_date < NOW()
    ORDER BY t.due_date ASC
")->fetchAll();

// Also mark those transactions as overdue in DB
if (!empty($overdue)) {
    $ids = implode(',', array_column($overdue, 'transaction_id'));
    $db->exec("UPDATE transactions SET status = 'overdue' WHERE id IN ($ids)");
}

jsonResponse(['overdue' => $overdue, 'count' => count($overdue)]);
