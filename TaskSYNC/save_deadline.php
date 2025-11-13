<?php
// save_deadline.php
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$task_id = isset($input['task_id']) ? (int)$input['task_id'] : 0;
$deadline_date = $input['deadline_date'] ?? null;

if (!$task_id || !$deadline_date) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Missing task_id or deadline_date'
    ]);
    exit;
}

// --- Normalize deadline format ---
$deadlineObj = date_create($deadline_date);
if (!$deadlineObj) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid deadline format. Expected YYYY-MM-DD'
    ]);
    exit;
}
$deadline_date = $deadlineObj->format("Y-m-d"); // force YYYY-MM-DD

try {
    $stmt = $pdo->prepare("
        INSERT INTO task_deadline (task_id, deadline_date)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE deadline_date = VALUES(deadline_date)
    ");

    if (!$stmt->execute([$task_id, $deadline_date])) {
        $err = $stmt->errorInfo();
        throw new Exception($err[2] ?? 'Unknown SQL error');
    }

    echo json_encode(['success' => true, 'deadline' => $deadline_date]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
