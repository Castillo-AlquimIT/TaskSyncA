<?php
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { http_response_code(403); exit; }

$user_id = (int)$_SESSION['user_id'];
$file_id = (int)($_POST['file_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT f.file_path, f.uploaded_by, g.owner_user_id, gm.role
    FROM task_files f
    JOIN tasks t ON f.task_id = t.id
    JOIN task_groups g ON t.group_id = g.id
    JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
    WHERE f.id = ?
");
$stmt->execute([$user_id, $file_id]);
$f = $stmt->fetch();

if ($f && ($f['uploaded_by'] == $user_id || $f['owner_user_id'] == $user_id || in_array($f['role'], ['teacher','admin']))) {
    @unlink(__DIR__ . "/" . $f['file_path']);
    $pdo->prepare("DELETE FROM task_files WHERE id = ?")->execute([$file_id]);
    echo json_encode(["success" => true]);
} else {
    http_response_code(403);
    echo json_encode(["error" => "Permission denied"]);
}
