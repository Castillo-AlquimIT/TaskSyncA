<?php
require_once __DIR__ . '/db.php';
session_start();

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) { http_response_code(401); exit; }

$data = json_decode(file_get_contents("php://input"), true);
$task_id = (int)($data['task_id'] ?? 0);
$minutes = (int)($data['minutes'] ?? 0);
$datetime = $data['datetime'] ?? null;

if (!$task_id) {
    http_response_code(400);
    echo json_encode(["error"=>"No task_id"]);
    exit;
}

if ($datetime) {
    $endTime = date("Y-m-d H:i:s", strtotime($datetime));
} elseif ($minutes > 0) {
    $endTime = date("Y-m-d H:i:s", time() + ($minutes * 60));
} else {
    http_response_code(400);
    echo json_encode(["error"=>"No valid timer"]);
    exit;
}

$stmt = $pdo->prepare("UPDATE tasks SET timer_end = ?, timer_set_by = ? WHERE id = ?");
$stmt->execute([$endTime, $user_id, $task_id]);

echo json_encode(["ok"=>true, "end"=>$endTime]);

?>