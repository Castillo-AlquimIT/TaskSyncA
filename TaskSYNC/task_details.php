<?php
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$user_id = $_SESSION['user_id'] ?? 0;
$user_privilege = $_SESSION['privilege'] ?? 0;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $task_id = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
    if (!$task_id) { echo json_encode(['error' => 'Missing task_id']); exit; }

    $stmt = $pdo->prepare("
        SELECT t.id, t.title, t.description, t.feedback,
               u.name AS assigned_to_name,
               d.deadline_date AS deadline,
               d._status
        FROM tasks t
        LEFT JOIN task_assignments ta ON ta.task_id = t.id
        LEFT JOIN users u ON ta.user_id = u.id
        LEFT JOIN task_deadline d ON d.task_id = t.id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo json_encode(['error' => 'Task not found']);
        exit;
    }

    echo json_encode($task);
    exit;
}

if ($method === 'POST') {
    $payload     = json_decode(file_get_contents('php://input'), true);
    $task_id     = (int)($payload['task_id'] ?? 0);
    $deadline    = $payload['deadline'] ?? null;
    $description = $payload['description'] ?? '';
    $feedback    = $payload['feedback'] ?? '';
    $status      = isset($payload['_status']) ? (int)$payload['_status'] : null;

    if (!$task_id) {
        echo json_encode(['error'=>'Missing task_id']);
        exit;
    }

    // --- Check permissions ---
    $stmt = $pdo->prepare("
        SELECT g.owner_user_id, ta.user_id AS assigned_to, t._lock
        FROM tasks t
        JOIN task_groups g ON t.group_id = g.id
        LEFT JOIN task_assignments ta ON ta.task_id = t.id
        WHERE t.id = ?
    ");
    $stmt->execute([$task_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['error' => 'Task not found']);
        exit;
    }

    // if locked and user < privilege 1 → block
    if ((int)$row['_lock'] === 1 && $user_privilege < 1) {
        echo json_encode(['error' => 'Access denied (task is locked)']);
        exit;
    }

    $canUpdateStatus = ($row['owner_user_id'] == $user_id || $row['assigned_to'] == $user_id);

    // --- Update description ---
    $stmt = $pdo->prepare("UPDATE tasks SET description = ? WHERE id = ?");
    $stmt->execute([$description, $task_id]);

    // --- Update deadline (upsert) ---
    if ($deadline) {
        $stmt = $pdo->prepare("
            INSERT INTO task_deadline (task_id, deadline_date)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE deadline_date = VALUES(deadline_date)
        ");
        $stmt->execute([$task_id, $deadline]);
    }

    // --- Update status if allowed ---
    if ($canUpdateStatus && $status !== null) {
        $stmt = $pdo->prepare("UPDATE task_deadline SET _status = ? WHERE task_id = ?");
        $stmt->execute([$status, $task_id]);
    }

    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['error'=>'Invalid request']);
