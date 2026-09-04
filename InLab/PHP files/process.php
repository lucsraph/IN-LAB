<?php
// ============================================================
//  ESP32 API Endpoint - process.php (stack-aware)
//  POST: user_uid=XX:XX & item_uid=YY:YY
// ============================================================
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    jsonResponse(['status'=>'error','message'=>'POST required']);

$userUID = strtoupper(trim($_POST['user_uid'] ?? ''));
$itemUID = strtoupper(trim($_POST['item_uid'] ?? ''));

if (!$userUID || !$itemUID)
    jsonResponse(['status'=>'error','message'=>'Both user_uid and item_uid are required']);

$db  = getDB();
$ip  = $_SERVER['REMOTE_ADDR'];

// ── 1. Verify User ─────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM users WHERE rfid_uid=? AND is_active=1");
$stmt->execute([$userUID]);
$user = $stmt->fetch();

if (!$user) {
    logAction($db, $userUID, 'scan', 'error', 'Unknown or inactive user', $ip);
    jsonResponse(['status'=>'error','message'=>'User not registered','uid'=>$userUID]);
}

// ── 2. Look up Item Unit by UID ────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT u.*, t.item_name, t.description, t.category, t.location
    FROM item_units u
    JOIN item_types t ON u.item_type_id = t.id
    WHERE u.rfid_uid = ?
");
$stmt->execute([$itemUID]);
$unit = $stmt->fetch();

if (!$unit) {
    // Log as pending register so the dashboard register page can detect it
    $db->prepare("DELETE FROM system_logs WHERE action='pending_register' AND rfid_uid=?")
       ->execute([$itemUID]);
    $db->prepare("INSERT INTO system_logs (rfid_uid,action,result,message,ip_address)
                  VALUES(?,'pending_register','warning','Unregistered item scanned',?)")
       ->execute([$itemUID, $ip]);
    logAction($db, $itemUID, 'scan', 'error', 'Unknown item UID', $ip);
    jsonResponse(['status'=>'not_found','message'=>'Item not registered','uid'=>$itemUID]);
}

// ── 3. Determine action ────────────────────────────────────────────────────
$action  = ($unit['status'] === 'available') ? 'borrow' : 'return';
$now     = date('Y-m-d H:i:s');
$dueDate = date('Y-m-d H:i:s', strtotime('+'.DEFAULT_BORROW_DAYS.' days'));

try {
    $db->beginTransaction();

    if ($action === 'borrow') {
        $db->prepare("
            INSERT INTO transactions
              (item_unit_id, item_type_id, user_id, action, borrow_date, due_date, status)
            VALUES (?, ?, ?, 'borrow', ?, ?, 'active')
        ")->execute([$unit['id'], $unit['item_type_id'], $user['id'], $now, $dueDate]);

        $db->prepare("UPDATE item_units SET status='borrowed', updated_at=? WHERE id=?")
           ->execute([$now, $unit['id']]);

        $db->commit();
        logAction($db, $itemUID, 'borrow', 'success',
            "'{$unit['item_name']}' ({$unit['unit_label']}) borrowed by {$user['full_name']}", $ip);

        jsonResponse([
            'status'     => 'success',
            'action'     => 'borrow',
            'item_name'  => $unit['item_name'],
            'unit_label' => $unit['unit_label'],
            'borrower'   => $user['full_name'],
            'due_date'   => $dueDate,
            'message'    => 'Borrowed successfully'
        ]);

    } else {
        // Find active borrow for this specific unit
        $stmt = $db->prepare("
            SELECT * FROM transactions
            WHERE item_unit_id=? AND action='borrow' AND status='active'
            ORDER BY borrow_date DESC LIMIT 1
        ");
        $stmt->execute([$unit['id']]);
        $txn = $stmt->fetch();

        if ($txn) {
            $note = ($txn['user_id'] != $user['id'])
                ? " [Returned by different user: {$user['full_name']}]" : '';
            $db->prepare("
                UPDATE transactions
                SET status='completed', return_date=?,
                    notes=CONCAT(IFNULL(notes,''),'$note')
                WHERE id=?
            ")->execute([$now, $txn['id']]);
        }

        $db->prepare("UPDATE item_units SET status='available', updated_at=? WHERE id=?")
           ->execute([$now, $unit['id']]);

        $db->commit();
        logAction($db, $itemUID, 'return', 'success',
            "'{$unit['item_name']}' ({$unit['unit_label']}) returned by {$user['full_name']}", $ip);

        jsonResponse([
            'status'     => 'success',
            'action'     => 'return',
            'item_name'  => $unit['item_name'],
            'unit_label' => $unit['unit_label'],
            'borrower'   => $user['full_name'],
            'message'    => 'Returned successfully'
        ]);
    }

} catch (Exception $e) {
    $db->rollBack();
    logAction($db, $itemUID, $action, 'error', $e->getMessage(), $ip);
    jsonResponse(['status'=>'error','message'=>'Transaction failed'], 500);
}

function logAction($db, $uid, $action, $result, $message, $ip) {
    try {
        $db->prepare("INSERT INTO system_logs (rfid_uid,action,result,message,ip_address)
                      VALUES(?,?,?,?,?)")->execute([$uid,$action,$result,$message,$ip]);
    } catch(Exception $e){}
}
