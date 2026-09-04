<?php
// ============================================================
//  Items API - items.php (stack-aware)
//  GET              → list all item_types with their units
//  POST action=add_type    → create a new item type
//  POST action=add_unit    → add a unit (RFID) to a type
//  POST action=update_type → edit an item type
//  POST action=update_unit → edit a single unit
//  POST action=delete_type → delete type + all its units
//  POST action=delete_unit → remove one unit from a stack
// ============================================================
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list all types with unit breakdown ────────────────────────────────
if ($method === 'GET') {
    $types = $db->query("
        SELECT it.*,
          GROUP_CONCAT(
            JSON_OBJECT(
              'id',         iu.id,
              'rfid_uid',   iu.rfid_uid,
              'unit_label', iu.unit_label,
              'status',     iu.status
            )
            ORDER BY iu.id
          ) AS units_json
        FROM item_types it
        LEFT JOIN item_units iu ON iu.item_type_id = it.id
        GROUP BY it.id
        ORDER BY it.item_name
    ")->fetchAll();

    // Parse units_json string into real arrays
    foreach ($types as &$t) {
        $t['units'] = $t['units_json']
            ? array_map('json_decode', array_map(fn($j) => $j, explode(',{', str_replace('[{','',$t['units_json']))))
            : [];
        // Simpler: just re-query units per type
        $u = $db->prepare("SELECT * FROM item_units WHERE item_type_id=? ORDER BY id");
        $u->execute([$t['id']]);
        $t['units'] = $u->fetchAll();
        unset($t['units_json']);
    }

    // Stats
    $total     = $db->query("SELECT COUNT(*) FROM item_units")->fetchColumn();
    $available = $db->query("SELECT COUNT(*) FROM item_units WHERE status='available'")->fetchColumn();
    $borrowed  = $db->query("SELECT COUNT(*) FROM item_units WHERE status='borrowed'")->fetchColumn();
    $overdue   = $db->query("SELECT COUNT(*) FROM transactions WHERE status='overdue'")->fetchColumn();

    jsonResponse([
        'types' => $types,
        'stats' => [
            'total'     => (int)$total,
            'available' => (int)$available,
            'borrowed'  => (int)$borrowed,
            'overdue'   => (int)$overdue,
        ]
    ]);
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD new item type ──────────────────────────────────────────────────
    if ($action === 'add_type') {
        $name = trim($_POST['item_name'] ?? '');
        if (!$name) jsonResponse(['status'=>'error','message'=>'Item name required'], 400);

        $db->prepare("INSERT INTO item_types (item_name,description,category,location) VALUES(?,?,?,?)")
           ->execute([
               $name,
               trim($_POST['description'] ?? ''),
               trim($_POST['category']    ?? 'General'),
               trim($_POST['location']    ?? 'Storage'),
           ]);
        jsonResponse(['status'=>'success','id'=>$db->lastInsertId(),'message'=>'Item type created']);
    }

    // ── ADD unit (RFID card) to existing type ──────────────────────────────
    if ($action === 'add_unit') {
        $uid    = strtoupper(trim($_POST['rfid_uid']     ?? ''));
        $typeId = (int)($_POST['item_type_id'] ?? 0);
        $label  = trim($_POST['unit_label']    ?? '');

        if (!$uid || !$typeId) jsonResponse(['status'=>'error','message'=>'RFID UID and item type required'], 400);

        // Duplicate UID check
        $chk = $db->prepare("SELECT id FROM item_units WHERE rfid_uid=?");
        $chk->execute([$uid]);
        if ($chk->fetch()) jsonResponse(['status'=>'error','message'=>'This UID is already registered'], 409);

        // Auto-generate label if blank
        if (!$label) {
            $count = $db->prepare("SELECT COUNT(*)+1 FROM item_units WHERE item_type_id=?");
            $count->execute([$typeId]);
            $n = $count->fetchColumn();
            $typeName = $db->prepare("SELECT item_name FROM item_types WHERE id=?");
            $typeName->execute([$typeId]);
            $label = ($typeName->fetchColumn() ?: 'Unit') . " #$n";
        }

        $db->prepare("INSERT INTO item_units (item_type_id,rfid_uid,unit_label) VALUES(?,?,?)")
           ->execute([$typeId, $uid, $label]);
        jsonResponse(['status'=>'success','id'=>$db->lastInsertId(),'message'=>'Unit added to stack']);
    }

    // ── UPDATE item type ───────────────────────────────────────────────────
    if ($action === 'update_type') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['status'=>'error','message'=>'Invalid ID'], 400);
        $db->prepare("UPDATE item_types SET item_name=?,description=?,category=?,location=? WHERE id=?")
           ->execute([
               trim($_POST['item_name']    ?? ''),
               trim($_POST['description']  ?? ''),
               trim($_POST['category']     ?? 'General'),
               trim($_POST['location']     ?? 'Storage'),
               $id
           ]);
        jsonResponse(['status'=>'success','message'=>'Item type updated']);
    }

    // ── UPDATE a single unit ───────────────────────────────────────────────
    if ($action === 'update_unit') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['status'=>'error','message'=>'Invalid ID'], 400);
        $db->prepare("UPDATE item_units SET unit_label=?,status=? WHERE id=?")
           ->execute([
               trim($_POST['unit_label'] ?? ''),
               $_POST['status']          ?? 'available',
               $id
           ]);
        jsonResponse(['status'=>'success','message'=>'Unit updated']);
    }

    // ── DELETE entire type + all its units ────────────────────────────────
    if ($action === 'delete_type') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['status'=>'error','message'=>'Invalid ID'], 400);

        $active = $db->prepare("
            SELECT COUNT(*) FROM item_units u
            JOIN transactions t ON t.item_unit_id = u.id
            WHERE u.item_type_id=? AND t.status='active'");
        $active->execute([$id]);
        if ((int)$active->fetchColumn() > 0)
            jsonResponse(['status'=>'error','message'=>'Cannot delete: some units are currently borrowed'], 409);

        // ON DELETE CASCADE handles item_units automatically
        $db->prepare("DELETE FROM item_types WHERE id=?")->execute([$id]);
        jsonResponse(['status'=>'success','message'=>'Item type and all units deleted']);
    }

    // ── DELETE single unit ────────────────────────────────────────────────
    if ($action === 'delete_unit') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['status'=>'error','message'=>'Invalid ID'], 400);

        $unit = $db->prepare("SELECT * FROM item_units WHERE id=?");
        $unit->execute([$id]);
        $u = $unit->fetch();
        if (!$u) jsonResponse(['status'=>'error','message'=>'Unit not found'], 404);
        if ($u['status'] === 'borrowed')
            jsonResponse(['status'=>'error','message'=>'Cannot remove: unit is currently borrowed'], 409);

        $db->prepare("DELETE FROM transactions WHERE item_unit_id=?")->execute([$id]);
        $db->prepare("DELETE FROM item_units WHERE id=?")->execute([$id]);
        jsonResponse(['status'=>'success','message'=>'Unit removed from stack']);
    }

    jsonResponse(['status'=>'error','message'=>'Unknown action'], 400);
}
