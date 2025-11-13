<?php
// api.php
require __DIR__ . '/db.php';
require_login();
header('Content-Type: application/json');

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $payload['action'] ?? '';

$user_id = $_SESSION['user_id'];

function json_ok($data = []) { echo json_encode($data); exit; }
function json_err($msg, $code=400){ http_response_code($code); echo json_encode(['error'=>$msg]); exit; }

function rand_code($length = 8) {
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $out = '';
  for ($i=0; $i<$length; $i++) $out .= $chars[random_int(0, strlen($chars)-1)];
  return $out;
}

function is_member(mysqli $db, int $group_id, int $user_id): bool {
  $stmt = $db->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?');
  $stmt->bind_param('ii', $group_id, $user_id);
  $stmt->execute();
  return (bool)$stmt->get_result()->fetch_row();
}

switch ($action) {
  case 'create_group': {
    $name = trim($payload['name'] ?? '');
    if ($name === '') json_err('Name required');
    // create unique code
    $code = rand_code(6);
    // ensure uniqueness
    $tries = 0;
    do {
      $stmt = $mysqli->prepare('SELECT id FROM groups WHERE code=?');
      $stmt->bind_param('s', $code);
      $stmt->execute();
      $exists = $stmt->get_result()->fetch_row();
      if ($exists) $code = rand_code(6);
    } while ($exists && ++$tries < 5);

    $stmt = $mysqli->prepare('INSERT INTO groups (name, code, owner_user_id) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $name, $code, $user_id);
    $stmt->execute();
    $group_id = $stmt->insert_id;

    // owner becomes member (role owner)
    $role = 'owner';
    $stmt = $mysqli->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $group_id, $user_id, $role);
    $stmt->execute();

    json_ok(['group'=>['id'=>$group_id,'name'=>$name,'code'=>$code]]);
  }

  case 'join_group': {
    $code = strtoupper(trim($payload['code'] ?? ''));
    if ($code === '') json_err('Code required');

    // code can be group code or invite code; prefer invites, then direct group code
    // Check invite first
    $stmt = $mysqli->prepare('SELECT group_id FROM invites WHERE code=? AND (expires_at IS NULL OR expires_at > NOW())');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res) {
      $group_id = (int)$res['group_id'];
    } else {
      // Try direct group code
      $stmt = $mysqli->prepare('SELECT id FROM groups WHERE code=?');
      $stmt->bind_param('s', $code);
      $stmt->execute();
      $res2 = $stmt->get_result()->fetch_assoc();
      if (!$res2) json_err('Invalid code', 404);
      $group_id = (int)$res2['id'];
    }

    // add if not already
    if (!is_member($mysqli, $group_id, $user_id)) {
      $role = 'member';
      $stmt = $mysqli->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
      $stmt->bind_param('iis', $group_id, $user_id, $role);
      $stmt->execute();
    }

    // load group
    $stmt = $mysqli->prepare('SELECT id, name, code, owner_user_id FROM groups WHERE id=?');
    $stmt->bind_param('i', $group_id);
    $stmt->execute();
    $g = $stmt->get_result()->fetch_assoc();

    $role = ($g['owner_user_id'] == $user_id) ? 'owner' : 'member';

    json_ok(['group'=>['id'=>$g['id'],'name'=>$g['name'],'code'=>$g['code']], 'role'=>$role]);
  }

  case 'generate_invite': {
    $group_id = (int)($payload['group_id'] ?? 0);
    if (!$group_id) json_err('group_id required');

    // only members can generate; optionally restrict to owner if you want
    if (!is_member($mysqli, $group_id, $user_id)) json_err('Not a member', 403);

    $code = rand_code(8);
    $stmt = $mysqli->prepare('INSERT INTO invites (group_id, code, created_by, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
    $stmt->bind_param('isi', $group_id, $code, $user_id);
    $stmt->execute();

    json_ok(['code'=>$code]);
  }

  case 'list_members': {
    $group_id = (int)($payload['group_id'] ?? 0);
    if (!$group_id) json_err('group_id required');
    if (!is_member($mysqli, $group_id, $user_id)) json_err('Not a member', 403);

    $stmt = $mysqli->prepare('
      SELECT u.id, u.name, gm.role
      FROM group_members gm
      JOIN users u ON u.id = gm.user_id
      WHERE gm.group_id = ?
      ORDER BY (gm.role="owner") DESC, u.name ASC
    ');
    $stmt->bind_param('i', $group_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    json_ok(['members'=>$rows]);
  }

  case 'add_task': {
    $group_id = (int)($payload['group_id'] ?? 0);
    $title = trim($payload['title'] ?? '');
    if (!$group_id || $title === '') json_err('group_id and title required');
    if (!is_member($mysqli, $group_id, $user_id)) json_err('Not a member', 403);

    $stmt = $mysqli->prepare('INSERT INTO tasks (group_id, title, created_by) VALUES (?, ?, ?)');
    $stmt->bind_param('isi', $group_id, $title, $user_id);
    $stmt->execute();

    json_ok(['task_id'=>$stmt->insert_id]);
  }

  case 'list_tasks': {
    $group_id = (int)($payload['group_id'] ?? 0);
    if (!$group_id) json_err('group_id required');
    if (!is_member($mysqli, $group_id, $user_id)) json_err('Not a member', 403);

    $stmt = $mysqli->prepare('
      SELECT t.id, t.title, ta.user_id AS assigned_to, u.name AS assigned_to_name
      FROM tasks t
      LEFT JOIN task_assignments ta ON ta.task_id = t.id
      LEFT JOIN users u ON u.id = ta.user_id
      WHERE t.group_id = ?
      ORDER BY t.created_at DESC, t.id DESC
    ');
    $stmt->bind_param('i', $group_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    json_ok(['tasks'=>$rows]);
  }

  case 'assign_task': {
    $group_id = (int)($payload['group_id'] ?? 0);
    $task_id  = (int)($payload['task_id'] ?? 0);
    $to_user  = (int)($payload['user_id'] ?? 0);
    if (!$group_id || !$task_id || !$to_user) json_err('group_id, task_id, user_id required');

    // must be member; assignee must be in group; task must belong to group
    if (!is_member($mysqli, $group_id, $user_id)) json_err('Not a member', 403);
    if (!is_member($mysqli, $group_id, $to_user)) json_err('User not in group', 400);

    $stmt = $mysqli->prepare('SELECT group_id FROM tasks WHERE id=?');
    $stmt->bind_param('i', $task_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || (int)$row['group_id'] !== $group_id) json_err('Task not in group', 400);

    // upsert assignment (task_id is PK in task_assignments)
    $stmt = $mysqli->prepare('
      INSERT INTO task_assignments (task_id, user_id, assigned_by)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), assigned_by = VALUES(assigned_by), assigned_at = CURRENT_TIMESTAMP
    ');
    $stmt->bind_param('iii', $task_id, $to_user, $user_id);
    $stmt->execute();

    json_ok(['ok'=>true]);
  }
  case 'update_status': {
    $taskId    = (int)($payload['task_id'] ?? 0);
    $status    = (int)($payload['_status'] ?? 0);
    $deadline  = $payload['deadline'] ?? null;
    $desc      = trim($payload['description'] ?? '');
    $feedback  = trim($payload['feedback'] ?? '');

    if (!$taskId) json_err('task_id required');

    // verify task belongs to a group the user is in
    $stmt = $mysqli->prepare("SELECT group_id FROM tasks WHERE id=?");
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) json_err('Task not found', 404);
    if (!is_member($mysqli, (int)$row['group_id'], $user_id)) json_err('Not a member', 403);

    // update task fields
    $stmt = $mysqli->prepare("UPDATE tasks SET _status=?, deadline=?, description=?, feedback=? WHERE id=?");
    $stmt->bind_param('issi', $status, $deadline, $desc, $taskId, $feedback);
    $stmt->execute();

    json_ok(['success' => true]);
}

case 'save_deadline': {
    $taskId   = (int)($payload['task_id'] ?? 0);
    $deadline = $payload['deadline'] ?? null;
    if (!$taskId || !$deadline) json_err('task_id and deadline required');

    $stmt = $mysqli->prepare("SELECT group_id FROM tasks WHERE id=?");
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) json_err('Task not found', 404);
    if (!is_member($mysqli, (int)$row['group_id'], $user_id)) json_err('Not a member', 403);

    $stmt = $mysqli->prepare("
      INSERT INTO task_deadline (task_id, deadline_date)
      VALUES (?, ?)
      ON DUPLICATE KEY UPDATE deadline_date = VALUES(deadline_date)
    ");
    $stmt->bind_param('is', $taskId, $deadline);
    $stmt->execute();

    json_ok(['success' => true]);
}

case 'list_files': {
    $taskId = (int)($payload['task_id'] ?? 0);
    if (!$taskId) json_err('task_id required');

    $stmt = $mysqli->prepare("SELECT group_id FROM tasks WHERE id=?");
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) json_err('Task not found', 404);
    if (!is_member($mysqli, (int)$row['group_id'], $user_id)) json_err('Not a member', 403);

    $stmt = $mysqli->prepare("SELECT id, filename, uploaded_at FROM task_files WHERE task_id=? ORDER BY uploaded_at DESC");
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    json_ok(['files' => $files]);
}

case 'delete_file': {
    $fileId = (int)($payload['file_id'] ?? 0);
    if (!$fileId) json_err('file_id required');

    $stmt = $mysqli->prepare("SELECT tf.task_id, t.group_id, tf.filename
                              FROM task_files tf
                              JOIN tasks t ON t.id = tf.task_id
                              WHERE tf.id=?");
    $stmt->bind_param('i', $fileId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) json_err('File not found', 404);
    if (!is_member($mysqli, (int)$row['group_id'], $user_id)) json_err('Not a member', 403);

    // delete DB record
    $stmt = $mysqli->prepare("DELETE FROM task_files WHERE id=?");
    $stmt->bind_param('i', $fileId);
    $stmt->execute();

    // optionally unlink physical file if stored on disk
    $path = __DIR__ . '/uploads/' . $row['filename'];
    if (is_file($path)) unlink($path);

    json_ok(['success' => true]);
  }
  
  default:
    json_err('Unknown action', 404);
}



