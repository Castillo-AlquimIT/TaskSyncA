<?php
// deadlines.php
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['error'=>'Not authenticated']);
  exit;
}
$user_id = (int)$_SESSION['user_id'];

// Fetch deadlines for tasks in groups where the current user is a member,
// and where the task is assigned either to the current user OR to the group's owner.
$stmt = $pdo->prepare("
  SELECT t.id, t.title, tg.name AS group_name, u.name AS assigned_to_name, td.deadline_date
  FROM tasks t
  LEFT JOIN task_deadline td ON td.task_id = t.id
  LEFT JOIN task_assignments ta ON ta.task_id = t.id
  LEFT JOIN users u ON u.id = ta.user_id
  LEFT JOIN task_groups tg ON tg.id = t.group_id
  JOIN group_members gm ON gm.group_id = t.group_id AND gm.user_id = ?
  WHERE td.deadline_date IS NOT NULL
    AND (ta.user_id = ? OR ta.user_id = tg.owner_user_id)
  ORDER BY td.deadline_date ASC
");
$stmt->execute([$user_id, $user_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['deadlines' => $rows]);
