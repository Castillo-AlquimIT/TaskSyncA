<?php
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { 
    http_response_code(403); 
    exit(json_encode(["error" => "Not logged in"])); 
}

$user_id  = (int)$_SESSION['user_id'];
$task_id  = (int)($_POST['task_id'] ?? 0);
$group_id = (int)($_POST['group_id'] ?? 0);

if ($task_id <= 0 || $group_id <= 0 || empty($_FILES['task_file'])) {
    http_response_code(400);
    exit(json_encode(["error" => "Invalid request"]));
}

// Check if task belongs to group
$stmt = $pdo->prepare("SELECT 1 FROM tasks WHERE id = ? AND group_id = ?");
$stmt->execute([$task_id, $group_id]);
if (!$stmt->fetch()) {
    http_response_code(403);
    exit(json_encode(["error" => "Task not in group"]));
}

// Remove old file for this specific user + task
$stmt = $pdo->prepare("SELECT id, file_path FROM task_files WHERE task_id = ? AND user_id = ?");
$stmt->execute([$task_id, $user_id]);
$old = $stmt->fetch();
if ($old) {
    @unlink(__DIR__ . "/" . $old['file_path']);
    $pdo->prepare("DELETE FROM task_files WHERE id = ?")->execute([$old['id']]);
}

// Upload new file
$uploadDir = __DIR__ . "/uploads/";
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$fileName   = basename($_FILES['task_file']['name']);
$storedName = time() . "_" . preg_replace("/[^a-zA-Z0-9_\.-]/", "_", $fileName);
$filePath   = $uploadDir . $storedName;

if (move_uploaded_file($_FILES['task_file']['tmp_name'], $filePath)) {
    $stmt = $pdo->prepare("
        INSERT INTO task_files (task_id, user_id, file_name, file_path)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            file_name = VALUES(file_name), 
            file_path = VALUES(file_path),
            uploaded_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$task_id, $user_id, $fileName, "uploads/" . $storedName]);

    echo json_encode([
        "success" => true,
        "file" => $fileName,
        "stored_name" => $storedName
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Upload failed"]);
}
