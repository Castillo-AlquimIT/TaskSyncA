<?php
//task_deadline.php
require_once __DIR__ . '/db.php';
session_start();

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? null;

if (!$action) {
    echo json_encode(['error' => 'No action']);
    exit;
}

try {
    if ($action === 'set_deadline') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        $deadline = $_POST['deadline'] ?? null;

        if (!$task_id || !$deadline) {
            echo json_encode(['error' => 'Missing task_id or deadline']);
            exit;
        }

        // Insert or update
        $stmt = $pdo->prepare("INSERT INTO task_deadline (task_id, deadline)
                               VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE deadline = VALUES(deadline)");
        $stmt->execute([$task_id, $deadline]);

        echo json_encode(['success' => true]);
    }

    elseif ($action === 'get_deadline') {
        $task_id = (int)($_GET['task_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT deadline FROM task_deadline WHERE task_id=? LIMIT 1");
        $stmt->execute([$task_id]);
        $row = $stmt->fetch();

        echo json_encode(['deadline' => $row['deadline'] ?? null]);
    }

    else {
        echo json_encode(['error' => 'Unknown action']);
    }

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
