<?php
// taskmanager.php
// Shows group workspace: members (left), add task & group code (center), tasks (right).
// Supports: add task, delete task, assign task, update status/details/deadline, file uploads.

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php'; 
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$user_id   = (int) $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '';
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;


if ($group_id <= 0) die('Group not selected.');

if (!isset($_SESSION['previous_page']) && isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'taskmanager.php') === false) {
    $_SESSION['previous_page'] = $_SERVER['HTTP_REFERER'];
}


// verify membership
$stmt = $pdo->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$group_id, $user_id]);
$member = $stmt->fetch();
if (!$member) die('Access denied. You are not a member of this group.');

// load group info
$stmt = $pdo->prepare('SELECT id, name, code, owner_user_id FROM task_groups WHERE id = ? LIMIT 1');
$stmt->execute([$group_id]);
$group = $stmt->fetch();
if (!$group) die('Group not found.');

$content_type = $_SERVER['CONTENT_TYPE'] ?? '';


/*
==========================================================
 JSON REQUEST HANDLER
==========================================================
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($content_type, 'application/json') !== false) {
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    $action  = $payload['action'] ?? '';

    header('Content-Type: application/json');
    require_once __DIR__ . '/db.php';

    $raw  = file_get_contents("php://input");
    $data = json_decode($raw, true) ?: [];

    $action  = $data['action'] ?? '';
    $task_id = isset($data['task_id']) ? (int)$data['task_id'] : 0;
    switch ($action) {
        case 'create_task':
          header('Content-Type: application/json');
            $title       = trim($payload['title'] ?? '');
            $description = trim($payload['description'] ?? '');
            $feedback    = trim($payload['feedback'] ?? ''); 
            $created_by  = $user_id;

            if ($title === '') {
                echo json_encode(["success" => false, "error" => "Title is required"]);
                exit;
            }
            if (!$canManageTasks) {
                echo json_encode(["success" => false, "error" => "Not allowed to create tasks"]);
                exit;
            }

           $stmt = $pdo->prepare("
              INSERT INTO tasks (group_id, title, description, feedback, created_by, created_at) 
              VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $ok = $stmt->execute([$group_id, $title, $description, $feedback, $created_by]);

            echo json_encode($ok
                ? ["success" => true, "task_id" => $pdo->lastInsertId()]
                : ["success" => false, "error" => "Failed to save task"]
            );
            exit;


        case 'list_members':
            $stmt = $pdo->prepare('
                SELECT u.id, u.name, gm.role
                FROM group_members gm
                JOIN users u ON u.id = gm.user_id
                WHERE gm.group_id = ?
                ORDER BY (gm.role="owner") DESC, (gm.role="teacher") DESC, u.name ASC
            ');
            $stmt->execute([$group_id]);
            echo json_encode(['members'=>$stmt->fetchAll()]);
            exit;

        case 'list_tasks':
            $stmt = $pdo->prepare('
                SELECT t.id, t.title, t.created_by, t.created_at,
                       ta.user_id AS assigned_to, u.name AS assigned_to_name,
                       d.deadline_date, d._status
                FROM tasks t
                LEFT JOIN task_assignments ta ON ta.task_id = t.id
                LEFT JOIN users u ON u.id = ta.user_id
                LEFT JOIN task_deadline d ON d.task_id = t.id
                WHERE t.group_id = ?
                ORDER BY t.created_at DESC
            ');
            $stmt->execute([$group_id]);
            echo json_encode(['tasks'=>$stmt->fetchAll()]);
            exit;

        case 'assign_task_by_name':
            $role    = strtolower($member['role'] ?? 'member');
            $isOwner = ($group['owner_user_id'] == $user_id);
            if (!$isOwner && !in_array($role, ['teacher','mod'])) {
                http_response_code(403);
                echo json_encode(['error'=>'Permission denied']);
                exit;
            }

            $task_id = (int)($payload['task_id'] ?? 0);
            $name    = trim($payload['name'] ?? '');
            if (!$task_id || $name === '') {
                http_response_code(400);
                echo json_encode(['error'=>'task_id and name required']);
                exit;
            }

            // find user by name in this group
            $stmt = $pdo->prepare('
                SELECT u.id FROM users u
                JOIN group_members gm ON gm.user_id = u.id
                WHERE gm.group_id = ? AND u.name = ?
                LIMIT 1
            ');
            $stmt->execute([$group_id, $name]);
            $to_user = $stmt->fetchColumn();
            if (!$to_user) {
                http_response_code(400);
                echo json_encode(['error'=>'User not found in group']);
                exit;
            }

            $stmt = $pdo->prepare('
                INSERT INTO task_assignments (task_id, user_id, assigned_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    user_id = VALUES(user_id),
                    assigned_by = VALUES(assigned_by),
                    assigned_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([$task_id, $to_user, $user_id]);
            echo json_encode(['ok'=>true]);
            exit;

        case 'remove_task_assigned_to':
            // check membership & role
            $role = strtolower($member['role'] ?? 'member');
            $isOwner = ($group['owner_user_id'] == $user_id);
            if (!$isOwner && !in_array($role, ['teacher','mod'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied']);
                exit;
            }
            $task_id = (int)($payload['task_id'] ?? 0);
            if (!$task_id) { echo json_encode(['error'=>'task_id required']); exit; }
            $stmt = $pdo->prepare("DELETE FROM task_assignments WHERE task_id = ?");
            $stmt->execute([$task_id]);
            echo json_encode(['ok'=>true]);
            exit;
 

        case 'assign_task':
            $role    = strtolower($member['role'] ?? 'member');
            $isOwner = ($group['owner_user_id'] == $user_id);
            if (!$isOwner && !in_array($role, ['teacher','mod'])) {
                http_response_code(403);
                echo json_encode(['error'=>'Permission denied']);
                exit;
            }

            $task_id = (int)($payload['task_id'] ?? 0);
            $to_user = (int)($payload['user_id'] ?? 0);
            if (!$task_id || !$to_user) {
                http_response_code(400);
                echo json_encode(['error'=>'task_id and user_id required']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT 1 FROM tasks WHERE id = ? AND group_id = ?');
            $stmt->execute([$task_id, $group_id]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error'=>'Task not in group']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
            $stmt->execute([$group_id, $to_user]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error'=>'User not in group']);
                exit;
            }

            $stmt = $pdo->prepare('
                INSERT INTO task_assignments (task_id, user_id, assigned_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    user_id = VALUES(user_id),
                    assigned_by = VALUES(assigned_by),
                    assigned_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([$task_id, $to_user, $user_id]);
            echo json_encode(['ok'=>true]);
            exit;

        case 'update_status':
            $task_id    = (int)($payload['task_id'] ?? 0);
            $new_status = isset($payload['_status']) ? (int)$payload['_status'] : null;

            if ($task_id <= 0 || $new_status === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'task_id and _status are required']);
                exit;
            }

            // Upsert into task_deadline
            $stmt = $pdo->prepare("SELECT id FROM task_deadline WHERE task_id = ?");
            $stmt->execute([$task_id]);
            $exists = $stmt->fetchColumn();

            if ($exists) {
                $stmt = $pdo->prepare("UPDATE task_deadline SET _status = ? WHERE task_id = ?");
                $stmt->execute([$new_status, $task_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO task_deadline (task_id, _status) VALUES (?, ?)");
                $stmt->execute([$task_id, $new_status]);
            }

            echo json_encode([
                'success'  => true,
                'task_id'  => $task_id,
                '_status'  => $new_status
            ]);
            exit;

        case 'update_details':
            $task_id = (int)$payload['task_id'];
            $details = trim($payload['details'] ?? '');
            $stmt = $pdo->prepare("UPDATE tasks SET details = ? WHERE id = ?");
            $stmt->execute([$details, $task_id]);
            echo json_encode(['success' => true, 'task_id' => $task_id, 'details' => $details]);
            exit;

        case 'update_feedback':
            $task_id = (int)$payload['task_id'];
            $feedback = trim($payload['feedback'] ?? '');
            if ($task_id <= 0) {
                echo json_encode(["success" => false, "error" => "Invalid task ID"]);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE tasks SET feedback = ? WHERE id = ? AND group_id = ?");
            $ok = $stmt->execute([$feedback, $task_id, $group_id]);
            echo json_encode($ok
                ? ["success" => true, "feedback" => $feedback]
                : ["success" => false, "error" => "Failed to update feedback"]
            );
              exit;

        case 'update_deadline':
            $task_id  = (int)$payload['task_id'];
            $deadline = $payload['deadline_date'] ?? null;
            $stmt = $pdo->prepare("
                INSERT INTO task_deadline (task_id, deadline_date) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE deadline_date = VALUES(deadline_date)
            ");
            $stmt->execute([$task_id, $deadline]);
            echo json_encode(['success' => true, 'task_id' => $task_id, 'deadline_date' => $deadline]);
            exit;

        case 'update_description':
            $task_id     = (int)($payload['task_id'] ?? 0);
            $description = trim($payload['description'] ?? '');

            if ($task_id <= 0) {
                echo json_encode(["success" => false, "error" => "Invalid task ID"]);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE tasks SET description = ? WHERE id = ? AND group_id = ?");
            $ok = $stmt->execute([$description, $task_id, $group_id]);

            echo json_encode($ok
                ? ["success" => true, "description" => $description]
                : ["success" => false, "error" => "Failed to update description"]
            );
              exit;

        case 'toggle_lock':
            if ($task_id <= 0) {
                echo json_encode([ 'success' => false, 'error' => 'Invalid task id' ]);
                exit;
            }

            $stmt = $pdo->prepare("SELECT _lock FROM tasks WHERE id = ?");
            $stmt->execute([$task_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode([ 'success' => false, 'error' => 'Task not found' ]);
                exit;
            }

            $currentLock = (int)$row['_lock'];
            $newLock     = $currentLock === 1 ? 0 : 1;

            $stmt = $pdo->prepare("UPDATE tasks SET _lock = ? WHERE id = ?");
            $stmt->execute([$newLock, $task_id]);

            echo json_encode([
                'success' => true,
                'task_id' => $task_id,
                'lock'    => $newLock
            ]);
            exit;

        case 'list_group_users':
            $stmt = $pdo->prepare("
                SELECT u.id, u.name
                FROM users u
                JOIN group_members gm ON gm.user_id = u.id
                WHERE gm.group_id = ?
                ORDER BY u.name ASC
            ");
            $stmt->execute([$group_id]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true, 'users'=>$users]);
            exit;

        case 'report_member':
    $reported_user_id = (int)($payload['reported_user_id'] ?? 0);
    $reason = trim($payload['reason'] ?? '');
    $actionType = $payload['actionType'] ?? ''; // alert, kick, blacklist, contact

    if (!$reported_user_id || $actionType === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields']);
        exit;
    }

    // If reason empty, allow it (JS may auto-generate); but normalize:
    if ($reason === '') {
        $reason = 'No reason provided';
    }

    // --- Actor context (current user)
    $actorUserId = $user_id; // from top of file
    $actorPrivilege = (int)($_SESSION['privilege_mode'] ?? 0);

    // load current user's membership role if needed (safe fallback)
    $stmt = $pdo->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$group_id, $actorUserId]);
    $actorMembership = $stmt->fetch(PDO::FETCH_ASSOC);
    $actorRole = strtolower($actorMembership['role'] ?? 'member');

    // owner check
    $isOwner = ($group['owner_user_id'] == $actorUserId);

    // authorize: only owner OR teacher/mod (role) OR any user with privilege >=1 (owner/teacher/mod) can moderate
    $canModerate = $isOwner || in_array($actorRole, ['teacher', 'mod']) || in_array($actorPrivilege, [1,2,3]);
    if (!$canModerate && in_array($actionType, ['kick','blacklist'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // --- Load target user's privilege/role so we can't act on equal/higher
    $stmt = $pdo->prepare('SELECT u.id, u.privilege_mode, gm.role as group_role
                           FROM users u
                           LEFT JOIN group_members gm ON gm.user_id = u.id AND gm.group_id = ?
                           WHERE u.id = ? LIMIT 1');
    $stmt->execute([$group_id, $reported_user_id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    $targetPrivilege = (int)($target['privilege_mode'] ?? 0);
    $targetRole = strtolower($target['group_role'] ?? 'member');

    // Prevent an actor from kicking/blacklisting someone with equal or higher privilege
    if (in_array($actionType, ['kick','blacklist'])) {
        if ($actorUserId === $reported_user_id) {
            http_response_code(400);
            echo json_encode(['error' => 'You cannot perform this action on yourself.']);
            exit;
        }
        if ($actorPrivilege <= $targetPrivilege && !$isOwner) {
            // Special: owner (group owner_user_id) can still act; but non-owner cannot act on equal/higher
            http_response_code(403);
            echo json_encode(['error' => 'Cannot act on equal or higher privilege user.']);
            exit;
        }
        // You could also include role-based tie breaker e.g., teacher vs mod etc.
    }

    // --- Insert or update report record
    $check = $pdo->prepare("SELECT id FROM member_reports WHERE reported_user_id = ? AND group_id = ?");
    $check->execute([$reported_user_id, $group_id]);
    $existing = $check->fetchColumn();

    if ($existing) {
        $pdo->prepare("
            UPDATE member_reports 
            SET offense_count = offense_count + 1, 
                reason = CONCAT(COALESCE(reason,''), '\n', ?)
            WHERE id = ?
        ")->execute([$reason, $existing]);
    } else {
        $pdo->prepare("
            INSERT INTO member_reports (reported_user_id, reported_by_id, group_id, reason, offense_count) 
            VALUES (?, ?, ?, ?, 1)
        ")->execute([$reported_user_id, $actorUserId, $group_id, $reason]);
    }

    // --- Handle specific actions (only done after checks above)
    switch ($actionType) {
        case 'kick':
            $pdo->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?")
                ->execute([$group_id, $reported_user_id]);
            break;

        case 'blacklist':
            $pdo->prepare("
                INSERT IGNORE INTO group_blacklist (group_id, user_id, reason) 
                VALUES (?, ?, ?)
            ")->execute([$group_id, $reported_user_id, $reason]);

            $pdo->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?")
                ->execute([$group_id, $reported_user_id]);
            break;

        case 'alert':
            // Optional: log or send notification to group mods
            break;

        case 'contact':
            // Optional: escalate or email support
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action type']);
            exit;
    }

    echo json_encode(['success' => true]);
    exit;
    

        default:
            http_response_code(400);
            echo json_encode(['error'=>'Unknown action']);
            exit;
    }
}

$privilege = (int)($_SESSION['privilege_mode'] ?? 0);
$role      = strtolower($member['role'] ?? 'member');
$isOwner   = ($group['owner_user_id'] == $user_id);
/*
==========================================================
 FORM POST HANDLER
==========================================================
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($content_type, 'application/json') === false) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_chat' && !empty($_POST['message'])) {
        $msg = trim($_POST['message']);
        $stmt = $pdo->prepare("INSERT INTO chat_messages (group_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$group_id, $user_id, $msg]);
        header("Location: taskmanager.php?group_id=" . $group_id);
        exit;
    }
    
    if ($action === 'add_task' && !empty($_POST['title'])) {
        $title        = trim($_POST['title']);
        $deadline_date = $_POST['deadline_date'] ?? null;

        $stmt = $pdo->prepare("INSERT INTO tasks (group_id, title, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$group_id, $title, $user_id]);
        $task_id = (int)$pdo->lastInsertId();

        if (!empty($deadline_date)) {
            $stmt = $pdo->prepare("INSERT INTO task_deadline (task_id, deadline_date, _status) VALUES (?, ?, 0)");
            $stmt->execute([$task_id, $deadline_date]);
        }

        header("Location: taskmanager.php?group_id=" . $group_id);
        exit;
    }

    if ($action === 'delete_task' && !empty($_POST['task_id'])) {
        $taskId = (int) $_POST['task_id'];
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND group_id = ?");
        $stmt->execute([$taskId, $group_id]);
        header("Location: taskmanager.php?group_id=" . $group_id);
        exit;
    }

    // === Upload file ===
    if ($action === 'update_task') {
        $task_id     = (int)($_POST['task_id'] ?? 0);
        $status      = isset($_POST['_status']) ? (int)$_POST['_status'] : null;
        $deadline    = $_POST['deadline'] ?? null;
        $description = isset($_POST['description']) ? trim($_POST['description']) : null;
        $feedback    = isset($_POST['feedback']) ? trim($_POST['feedback']) : null;

        if (!$task_id) {
            echo json_encode(['success' => false, 'error' => 'Missing task_id']);
            exit;
        }
        $stmt = $pdo->prepare("
            UPDATE tasks
            SET details = :description
            WHERE id = :task_id
        ");
        $stmt->execute([
            ':description' => $description,
            ':task_id'     => $task_id
        ]);

        // --- Upsert deadline/_status in task_deadline ---
        if ($deadline !== null || $status !== null || $feedback !== null) {
          $stmt = $pdo->prepare("SELECT id FROM task_deadline WHERE task_id = ?");
          $stmt->execute([$task_id]);
          $exists = $stmt->fetchColumn();

            if ($exists) {
                if ($deadline !== null && $status !== null) {
                    $stmt = $pdo->prepare("UPDATE task_deadline SET deadline_date = ?, _status = ? WHERE task_id = ?");
                    $stmt->execute([$deadline, $status, $task_id]);
                } elseif ($deadline !== null) {
                    $stmt = $pdo->prepare("UPDATE task_deadline SET deadline_date = ? WHERE task_id = ?");
                    $stmt->execute([$deadline, $task_id]);
                } elseif ($status !== null) {
                    $stmt = $pdo->prepare("UPDATE task_deadline SET _status = ? WHERE task_id = ?");
                    $stmt->execute([$status, $task_id]);
                } elseif ($feedback !== null) {
                    $stmt = $pdo->prepare("UPDATE tasks SET feedback = ? WHERE id = ?");
                    $stmt->execute([$feedback, $task_id]);
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO task_deadline (task_id, deadline_date, _status) VALUES (?, ?, ?)");
                $stmt->execute([$task_id, $deadline ?? date('Y-m-d'), $status ?? 0]);
            } 
        }
        echo json_encode(["success" => true]);
        exit;
    }
}

// === HTML Rendering continues below ===

// fetch all group members
$stmt = $pdo->prepare("
    SELECT u.id, u.name, gm.role
    FROM group_members gm
    JOIN users u ON gm.user_id = u.id
    WHERE gm.group_id = ?
    ORDER BY u.name ASC
");
$stmt->execute([$group_id]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// fetch tasks for this group
$stmt = $pdo->prepare("
    SELECT t.id, t.title, t.created_by, t.created_at, u.name AS creator_name
    FROM tasks t
    JOIN users u ON t.created_by = u.id
    WHERE t.group_id = ?
    ORDER BY t.created_at DESC
");
$stmt->execute([$group_id]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title><?=htmlspecialchars($group['name'])?> • Workspace</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css">
<style>
/* keep your workspace CSS as before */
.workspace { display:grid; grid-template-columns: 260px 1fr 360px; gap:16px; align-items:start; }
.left, .center, .right { background:white; border-radius:10px; padding:12px; box-shadow:0 2px 10px rgba(0,0,0,.06) }
.member-item { border:1px solid #e6e6e6; padding:8px; border-radius:8px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; }
.task-card { border:1px solid #e6e6e6; padding:10px; border-radius:10px; margin-bottom:10px; cursor:grab; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; }
.ellipsis { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px; display:inline-block; vertical-align:middle; }
.dropzone { min-height:60px; border:2px dashed #e6e9ee; border-radius:8px; padding:8px; }
.invite-pill { display:inline-block; padding:6px 10px; background:#eef2ff; border-radius:8px; border:1px solid #c7d2fe; }
.member-item.drag-over { background:#eef2ff; }
.task-actions { display:flex; gap:6px; align-items:center; }
.delete-btn { background:#ef4444; color:white; border:0; padding:4px 8px; border-radius:6px; cursor:pointer; }

#tasksList {
  max-height: calc(5 * 48px); /* 5 items × row height */
  overflow-y: auto;
  padding-right: 6px; /* optional: space for scrollbar */
}

.task-item {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 24px; /* define a consistent row height */
  margin-bottom: 6px;
}

.close-btn {
  border: none;
  background: transparent;
  font-size: 16px;
  cursor: pointer;
}


.deadline-box {
  position: absolute;
  top: 45px; /* directly under the button */
    right: 0;
    width: 280px;
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 6px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    padding: 12px;
    z-index: 999;
    opacity: 1;
    transform: translateY(0);
    transition: opacity 0.2s ease, transform 0.2s ease;
    text-align: left;
    color: #000; /* ensure text is black */
  }
  .deadline-box.hidden {
    opacity: 0;
    pointer-events: none;
    transform: translateY(-5px);
  }
  .deadline-box h3 {
    color: #000; 
    margin-top: 0;
  }
  .deadline-box p {
    color: #000; 
    font-style: italic;
    margin: 0;
  }
  .deadline-box ul {
    padding-left: 18px;
    color: #000; 
    margin: 0;
  }
  .deadline-box li {
    margin-bottom: 8px;
    color: #000; 
}  

.hidden {
  display: none !important;
}

#filesBox {
  color: black;
  position:absolute; top:50px; right:10px; width:300px; max-height:400px;
  overflow-y:auto; background:#fff; border:1px solid #ccc; border-radius:6px; 
  padding:10px; z-index:1000; text-align:left;  
}
.lock-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: background-color 0.3s ease, transform 0.2s ease;
}

.lock-btn.locked {
  background-color: #f44336; /* red */
  color: #fff;
}

.lock-btn.unlocked {
  background-color: #4caf50; /* green */
  color: #fff;
}

.lock-btn:active {
  transform: scale(0.95);
}

.assigned-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 8px;
  background: #eef2ff;
  border: 1px solid #c7d2fe;
  border-radius: 8px;
  margin-top: 6px;
}
.assigned-pill .remove-btn {
  margin-left: 6px;
  border: none;
  background: transparent;
  cursor: pointer;
  color: #ef4444;
  font-size: 14px;
}

.suggestions {
  position: absolute;
  background: white;
  border: 1px solid #ddd;
  border-radius: 4px;
  max-height: 150px;
  overflow-y: auto;
  z-index: 1000;
  display: none;
  width: 200px;
}

.suggestion-item {
  padding: 6px 10px;
  cursor: pointer;
}

.suggestion-item:hover {
  background: #f3f4f6;
}

.dropdown {
  position: relative;
  display: inline-block; /* keeps button + dropdown inline */
  vertical-align: middle;
}

.dropdown-report {
  position: relative;
}


.dropdown-content {
  display: none;
  position: absolute;
  background: #fff;
  border: 1px solid #ccc;
  padding: 8px;
  z-index: 100;
}

.dropdown-content.show {
  display: block;
}


.dropdown-btn {
  background: #f9f9f9;
  border: 1px solid #ccc;
  padding: 6px 10px;
}

.dropdown-content {
  display: none;
  position: absolute;
  left: 100%;        /* show beside the button instead of below */
  top: 0;            /* align top edges */
  background: #fff;
  border: 1px solid #ccc;
  z-index: 10;
  min-width: 120px;
  max-height: 200px;
  overflow-y: auto;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}


.dropdown-content.show {
  display: block;
  
}

.dropdown-content button {
  display: block;
  width: 100%;
  text-align: left;
  padding: 4px 2px;
  border: none;
  background: none;
  cursor: pointer;
}
.dropdown-content button:hover {
  background: #000000ff;
  color: white ;
}


#reportDropdownBtn {
  background: #5f3dc4;
  color: white;
  border: none;
  padding: 8px 12px;
  border-radius: 5px;
  cursor: pointer;
}
#reportDropdownBtn:hover {
  background: #7b5ae1;
}

#membersList {
  max-height: calc(5 * 48px); /* 5 items × item height */
  overflow-y: auto;
}
.member-item {
  height: 28px; /* or whatever your row height is */
}


:root {
  --theme-base: #a289ec;
  --theme-light: #f1dbff;
  --theme-border: #c7d2fe;
  --theme-text: #3c2a8e;
}

.header, .navbar { background-color: var(--theme-base); }
.sidebar { background-color: var(--theme-light); }
.btn { border-color: var(--theme-base); background: var(--theme-base); }
.invite-pill, .assigned-pill {
  background: var(--theme-light);
  border-color: var(--theme-border);
}
.member-item.drag-over { background: var(--theme-light); }
h1, h2, h3, .text-theme { color: var(--theme-text); }

.progress-box {
  background: transparent;
  box-shadow: none;    
  padding:12px 20px;
}

.logout-btn {
  background: #e74c3c;
  color: #fff;
  display: inline-flex;
  align-items: center;
  gap: 6px; /* space between icon and text */
  padding: 8px 12px;
  border-radius: 4px;
  text-decoration: none;
}

.logout-btn .icon {
  width: 20px;   /* adjust size here */
  height: 20px;
  flex-shrink: 0;
}
</style>
</head>
<body>
  
<header style="margin-bottom:20px;">
  <!-- Deadline & Files Section Wrapper -->
  <div style="display:flex; justify-content:center; gap:20px; flex-wrap:wrap;">
    <!-- Deadlines Box -->
    <div id="deadlineBox" class="deadline-box hidden" aria-hidden="true"
      style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:15px; max-width:400px; width:100%;">
      <h3>Upcoming Deadlines</h3>
      <?php
      $stmt = $pdo->prepare("
          SELECT 
            t.title, 
            d.deadline_date, 
            d._status AS task_status, 
            u.name AS assigned_to
          FROM task_deadline d
          JOIN tasks t ON d.task_id = t.id
          LEFT JOIN task_assignments ta ON ta.task_id = t.id
          LEFT JOIN users u ON ta.user_id = u.id
          WHERE t.group_id = ? AND (t.created_by = ? OR ta.user_id = ?)
          ORDER BY d.deadline_date ASC
          LIMIT 5
      ");
      $stmt->execute([$group_id, $user_id, $user_id]);
      $deadlines = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $statusLabels = [
        0 => 'Not Started',
        1 => 'Not Started',
        2 => 'Ongoing',
        3 => 'Complete',
        4 => 'Delayed',
      ];
      $statusLabels2 = $statusLabels;

      $statusLabels2 = [
        1 => 'Not Started',
        2 => 'Ongoing',
        3 => 'Complete',
        4 => 'Delayed',
      ];
      ?>

      <?php if (empty($deadlines)): ?>
        <p>No deadlines assigned to you.</p>
      <?php else: ?>
        <ul style="list-style:none; padding-left:0;">
          <?php foreach ($deadlines as $d): ?>
            <?php  
              $d_status = isset($d['task_status']) && $d['task_status'] !== null 
                ? ($statusLabels[(int)$d['task_status']] ?? 'Not Started') 
                : 'Not Started';
            ?>
            <li style="margin-bottom:10px;">
              <strong><?= htmlspecialchars($d['title']) ?></strong><br>
              <?= date('M j, Y', strtotime($d['deadline_date'])) ?><br>
              <small>Name: <?= htmlspecialchars($d['assigned_to'] ?? 'Unassigned') ?></small><br>
              <small>Status: <span class="status"><?= $d_status ?></span></small>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- Files Box -->
    <div id="filesBox" class="hidden"
      style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:15px; max-width:400px; width:100%;">
      <h3>Files</h3>
      <?php
      $stmt = $pdo->prepare("
          SELECT f.id, f.file_name, f.file_path, u.name AS uploader, t.title AS task_name
          FROM task_files f
          JOIN users u ON f.user_id = u.id
          JOIN tasks t ON f.task_id = t.id
          WHERE t.group_id = ?
          ORDER BY f.uploaded_at DESC
          LIMIT 10
      ");
      $stmt->execute([$group_id]);
      $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
      ?>
      
      <?php if (empty($files)): ?>
        <p>No files uploaded.</p>
      <?php else: ?>
        <ul style="list-style:none; padding-left:0;">
          <?php foreach ($files as $f): ?>
            <li style="margin-bottom:10px;">
              <a href="<?= htmlspecialchars($f['file_path']) ?>" target="_blank">
                <?= htmlspecialchars($f['task_name']) ?>
              </a><br>
              <small>By: <?= htmlspecialchars($f['uploader']) ?></small>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex; align-items:center; justify-content:space-between;">
    <!-- Back button -->
     <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'dashboard.php') ?>" class="btn">← Back</a>

    <!-- Title -->
    <h2 style="margin:0; text-align:center; flex-grow:1;">
      <?= htmlspecialchars($group['name']) ?> • Workspace
    </h2>

    <!-- Logout -->
    <a href="logout.php" class="btn logout-btn">
  <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="#e3e3e3">
    <path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/>
  </svg>
  Logout
</a>

        <button id="toggleDeadline" class="btn">📅 Deadlines</button>
    <button id="toggleFiles" class="btn" style="background:#2ecc71; color:#fff;">📂 Files</button>
  </div>

  <!-- Progress Bar -->
  <?php
  $stmt = $pdo->prepare("
      SELECT COUNT(t.id) AS total,
             SUM(CASE WHEN d._status = 3 THEN 1 ELSE 0 END) AS completed
      FROM tasks t
      LEFT JOIN task_deadline d ON d.task_id = t.id
      WHERE t.group_id = ?
  ");
  $stmt->execute([$group_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  $total = (int)($row['total'] ?? 0);
  $completed = (int)($row['completed'] ?? 0);
  $progress = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
  ?>
  <div style="margin:20px auto; max-width:900px; text-align:center;">
    <div class="progress-box">
      <strong>Progress</strong>
      <div style="margin-top:6px; background:#eee; border-radius:4px; overflow:hidden;">
        <div style="width:<?= $progress ?>%; background:#4caf50; height:14px;"></div>
      </div>
      <small><?= $completed ?>/<?= $total ?> tasks complete (<?= $progress ?>%)</small>
    </div>
  </div>

  <!-- Action Buttons -->
 <div style="display:flex; justify-content:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
  </div>
</header>

<?php
// Determine current role in this group
$stmt = $pdo->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
$stmt->execute([$group_id, $user_id]);
$membership = $stmt->fetch(PDO::FETCH_ASSOC);

if ((int)$group['owner_user_id'] === $user_id) {
    $currentRole = 'owner';
} elseif ($membership) {
    $currentRole = strtolower($membership['role']); // 'member', 'teacher', 'mod'
} else {
    $currentRole = 'viewer'; // not a member
}

// Role → numeric mapping
$roleLabels = [
    'viewer'  => 0,
    'member'  => 0,
    'owner'   => 1,
    'teacher' => 2,
    'mod'   => 3
];
$currentRoleNumeric = $roleLabels[$currentRole] ?? 0;

// Now only owner (1) and above can manage tasks
$canManageTasks = ($currentRoleNumeric >= 1);

$user_id = $_SESSION['user_id'] ?? 0;
$role    = $_SESSION['privilege'] ?? 0;

// define flag once role is known
$canManageTasks = ($role >= 1);

$canManageTasks = (
    $currentRoleNumeric >= 1 // group owner/teacher/mod
);

?>



<div class="container">
  <div class="workspace">
    <!-- Members (left) -->
    <div class="left">
      <h3>Members</h3>
      <div id="membersList" style="cursor:pointer; max-height:300px;">
  <?php if (empty($members)): ?>
    <div class="card">No members yet.</div>
  <?php else: foreach ($members as $m): ?>
    <div class="member-item" 
         data-user-id="<?= (int)$m['id'] ?>" 
         style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px;">
      
      <!-- Left side: name -->
      <div class="member-name" style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
        <?= htmlspecialchars($m['name']) ?>
      </div>

      <!-- Right side: role + (maybe dropdown) -->
      <div class="member-actions" style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
        <div class="pill"><?= htmlspecialchars($m['role']) ?></div>

        <?php if ($isOwner || $currentRole === 'mod' || $currentPrivilege > 1): ?>
          <div class="dropdown-report">
            <button class="dropdown-btn"> ⋮</button>
            <div class="dropdown-content">
              <a href="view_profile.php?user_id=<?= (int)$m['id'] ?>" class="profile">
                <button class="reportAction">View Profile</button>
              </a>
              <button class="reportAction" data-action="kick">Kick Member</button>
              <button class="reportAction" data-action="blacklist">Blacklist User</button>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>
  <?php endforeach; endif; ?>
</div> 
    <div style="margin-top:10px; color:#6b7280">Members are blank until others join using the invite code.</div>
          <div class="card" style="margin-top:20px;">
        <h3>Group Code</h3>
        <div style="display: flex; align-items: center; gap: 8px;">
        <p style="font-size:18px; font-weight:bold;" id="groupCode"><?= htmlspecialchars($group['code']) ?></p>
         <button onclick="copyGroupCode()" style="padding:5px 10px; border: none; cursor: pointer;">📋</button>
        </div>
         <small>Share this code with others to join your group</small>
      </div>
    </div>

    <!-- Center Group -->
    <div class = "center">
      <h3>Group Chat</h3>
      <div id="chatBox" style="border:1px solid #e6e6e6; height:300px; overflow-y:scroll; padding:8px; background:#f9fafb; border-radius:8px; margin-bottom:10px;">
        <?php
        // Load last 30 messages
        $stmt = $pdo->prepare('
          SELECT c.message, c.created_at, u.name 
          FROM chat_messages c
          JOIN users u ON u.id = c.user_id
          WHERE c.group_id = ?
          ORDER BY c.created_at DESC
        ');
        $stmt->execute([$group_id]);
        $messages = array_reverse($stmt->fetchAll());
        if (!$messages) {
          echo "<div style='color:#6b7280'>No messages yet.</div>";
        } else {
          foreach ($messages as $msg) {
            echo "<div style='margin-bottom:6px;'>
                    <strong>".htmlspecialchars($msg['name']).":</strong> 
                    ".htmlspecialchars($msg['message'])."
                    <span style='color:#9ca3af; font-size:11px;'>(".htmlspecialchars($msg['created_at']).")</span>
                  </div>";
          }
        }
        ?>
      </div>

      <form method="post" style="display:flex; gap:6px;">
        <input type="hidden" name="action" value="add_chat">
        <input type="text" name="message" placeholder="Type a message..." required style="flex:1;">
        <button type="submit">Send</button>
      </form>
    </div>
    <!-- Tasks (right) -->
    <div class="right">
      <h3>Tasks</h3>
      <div id="tasksList">
        <?php if (empty($tasks)): ?>
          <div class="card">No tasks yet.</div>
        <?php else: foreach ($tasks as $t): ?>
          <div class="task-card task-item" data-task-id="<?= (int)$t['id'] ?>">
          <span class="ellipsis" title="<?= htmlspecialchars($t['title']) ?>"></span>
          <span class="task-title"><?= htmlspecialchars($t['title']) ?></span>
          <span class="task-member"><?= htmlspecialchars($t['member_name'] ?? '') ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <div style="margin-top:10px; color:#6b7280">Double click task and assigned a member.</div>
    <div class="card">            
      <?php if ($canManageTasks): ?>
        <h3>Add Assignment/Task</h3>
        <form method="post" style="display:flex; gap:10px;">
          <input type="hidden" name="action" value="add_task">
          <input type="text" name="title" placeholder="Enter task..." required style="flex:1;">
          <button type="submit" class="btn">Add</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<!-- <button id="lockToggleBtn" class="lock-btn" 
        style="border:none; background:none; cursor:pointer; font-size:18px;">
  🔒
</button> -->


<!-- Task Details Modal -->
<div id="taskModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; 
     background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">

  <div style="background:#fff; padding:20px; border-radius:8px; width:400px; max-width:90%;">
    <h3 id="modalTitle">Task Details</h3>


    <p><strong>Assigned to:</strong> 
    <div id="assignedUserBox">
      <select id="modalAssignName">
        <option value="">-- Select member --</option>
        <!-- options will be populated dynamically -->
      </select>
    </div>


    <p><strong>Deadline:</strong> <input type="date" id="modalDeadline"></p>
    <p><strong>Description:</strong></p>
    <textarea id="modalDescription" style="width:100%; height:80px;"></textarea>

    <!-- NEW Status Dropdown -->
  <p style="margin-top:10px;">

        <p><strong>Feedback:</strong></p>
        <textarea id="modalFeedback" style="width:100%; height:60px;"
          <?php if (!($canManageTasks || in_array($canManageTasks, ['mod','teacher']))) : ?>
            disabled style="opacity:0.5; cursor:not-allowed;"
          <?php endif; ?>
        ></textarea>

          <div class="dropdown">
            <button type="button" class="dropdown-btn" id="statusDropdownBtn">▼ Status</button>
            <div class="dropdown-content" id="statusDropdownContent">
              <?php foreach ($statusLabels2 as $key => $label): ?>
                <button 
                  data-value="<?= $key ?>" 
                  <?php if (!($canManageTasks || in_array($canManageTasks, ['mod','teacher']))) : ?>
                    disabled style="opacity:0.5; cursor:not-allowed;"
                  <?php endif; ?>
                >
                  <?= htmlspecialchars($label) ?? 'Not Started' ?>
                </button>
              <?php endforeach; ?>
            </div>

  <!-- Keep your original select (hidden but still functional for backend form submission) -->
  <select id="modalStatus" name="modalStatus" style="display:none;">
    <?php foreach ($statusLabels2 as $key => $label): ?>
      <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
    <?php endforeach; ?>
  </select>
</div>

      </p>


      <div>
        <form id="uploadForm" enctype="multipart/form-data" method="POST" style="display:inline;">
          <input type="hidden" name="task_id" id="uploadTaskId">
          <input type="hidden" name="group_id" value="<?= (int)$group_id ?>">
        </form>
        
          <!-- File Upload (no <form>, just inputs) -->
          <div style="margin-top:10px;">
            <strong>File:</strong>
            <input type="file" id="taskFile">
            <div id="fileSection" style="margin-top:5px;">
              <span id="uploadedFileName"></span>
              <button id="deleteFileBtn" style="display:none;">X</button>
            </div>
          </div>
    <div style="margin-top:10px; text-align:center;">
      <button id="modalSave">Save</button>
      <button id="modalClose">Close</button>
    </div>
  </div>
</div>
</div>


<script>
const GROUP_ID = <?= json_encode($group_id) ?>;
const WORKER_PATH = 'taskmanager.php?group_id=' + GROUP_ID;
const CURRENT_PRIVILEGE = <?= json_encode((int)($privilege ?? 0)) ?>; // 0,1,2,3
const CURRENT_ROLE = <?= json_encode(strtolower($member['role'] ?? 'member')) ?>; // "owner","mod","member"
const IS_OWNER = <?= json_encode($group['owner_user_id'] == $user_id) ?>; // true or false
/* ---------------------------
   API helper
---------------------------- */
async function apiJson(action, payload = {}) {
  const res = await fetch(WORKER_PATH, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, ...payload })
  });

  let data;
  try {
    data = await res.json();
  } catch (err) {
    const txt = await res.text();
    throw new Error('Invalid JSON: ' + txt);
  }

  data._status = res.status;
  data._ok = res.ok;
  return data;
}


/* ---------------------------
   Save Task (with refresh)
---------------------------- */
async function saveTask(taskId, data) {
  const res = await apiJson('save_task', { task_id: taskId, ...data });
  if (res.success) {
    // ✅ auto-refresh page after save
    location.reload();
  } else {
    alert('Failed to save task.');
  }
}

/* ---------------------------
   Upcoming Deadline Toggle
---------------------------- */
document.addEventListener("DOMContentLoaded", () => {
  const toggleBtn = document.getElementById("toggleDeadline");
  const box = document.getElementById("deadlineBox");

  toggleBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    const isHidden = box.classList.toggle("hidden");
    toggleBtn.setAttribute("aria-expanded", !isHidden);
    box.setAttribute("aria-hidden", isHidden);
  });

  document.addEventListener("click", (e) => {
    if (!box.contains(e.target) && !toggleBtn.contains(e.target)) {
      box.classList.add("hidden");
      toggleBtn.setAttribute("aria-expanded", false);
      box.setAttribute("aria-hidden", true);
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      box.classList.add("hidden");
      toggleBtn.setAttribute("aria-expanded", false);
      box.setAttribute("aria-hidden", true);
    }
  });
});


// Global members cache
window.groupUsers = [];

// Load group users once at startup
async function fetchGroupUsers() {
  try {
    const res = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "list_group_users" })
    });
    const data = await res.json();
    if (data.ok && Array.isArray(data.users)) {
      window.groupUsers = data.users;
    } else {
      window.groupUsers = [];
    }
  } catch (e) {
    console.error("fetchGroupUsers failed:", e);
    window.groupUsers = [];
  }
}

// Ensure group users are available before modals open
document.addEventListener("DOMContentLoaded", () => {
  fetchGroupUsers();
});

// Drop down logic for status selection
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('statusDropdownBtn');
  const dropdown = document.getElementById('statusDropdownContent');
  const select = document.getElementById('modalStatus');

  // Toggle dropdown visibility
  btn.addEventListener('click', () => {
    dropdown.classList.toggle('show');
  });

  // Set selected option when clicking a button
  dropdown.querySelectorAll('button').forEach(item => {
    item.addEventListener('click', () => {
      if (item.disabled) return;
      const value = item.getAttribute('data-value');
      const text = item.textContent;

      btn.textContent = text;
      select.value = value;
      dropdown.classList.remove('show');
    });
  });

  // Close dropdown when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.dropdown')) dropdown.classList.remove('show');
  });
});
/* ---------------------------
   Task Modal (open + save)
---------------------------- */

function openTaskModal(taskId) {
  fetch('task_details.php?task_id=' + taskId)
    .then(r => r.json())
    .then(async data => {
      if (data.error) { alert(data.error); return; }

      document.getElementById('modalTitle').textContent = data.title;
      document.getElementById('modalDeadline').value = data.deadline || '';
      document.getElementById('modalDescription').value = data.description || '';
      document.getElementById('modalStatus').value = data._status || 1;
      document.getElementById('modalFeedback').value = data.feedback || '';
      document.getElementById('taskModal').dataset.taskId = taskId;

      const assignedBox = document.getElementById("assignedUserBox");

      if (data.assigned_to_name) {
        assignedBox.innerHTML = `
          <span class="assigned-pill">
            ${escapeHtml(data.assigned_to_name)}
            <button class="remove-btn" onclick="removeAssignment(${taskId})">✖</button>
          </span>
        `;
      } else {
        // Ensure groupUsers is populated; if not, fetch once
        if (!Array.isArray(window.groupUsers) || window.groupUsers.length === 0) {
          await fetchGroupUsers();
        }

        const options = (window.groupUsers || []).map(u =>
          `<option value="${u.id}">${escapeHtml(u.name)}</option>`
        ).join('');

        assignedBox.innerHTML = `
          <select id="modalAssignUser">
            <option value="">-- Select member --</option>
            ${options}
          </select>
        `;
      }

      document.getElementById('taskModal').style.display = 'flex';
    })
    .catch(err => {
      console.error(err);
      alert("Failed to load task details");
    });
}

async function saveTaskModal() {
  const taskId = document.getElementById("taskModal").dataset.taskId;
  const payload = {
    action: "update_status",
    task_id: taskId,
    _status: document.getElementById("modalStatus").value,
    deadline: document.getElementById("modalDeadline").value,
    description: document.getElementById("modalDescription").value,
    feedback: document.getElementById("modalFeedback").value
  };

  try {
    // Save details
    const res = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const result = await res.json();
    if (!res.ok || !result.success) {
      throw new Error(result.error || "Failed to save task details.");
    }

    // File upload (unchanged)
    const fileInput = document.getElementById("taskFile");
    if (fileInput && fileInput.files.length > 0) {
      const formData = new FormData();
      formData.append("task_file", fileInput.files[0]);
      formData.append("task_id", taskId);
      formData.append("group_id", GROUP_ID);
      const uploadRes = await fetch("upload_file.php", { method: "POST", body: formData });
      const uploadResult = await uploadRes.json();
      if (!uploadRes.ok || !uploadResult.success) {
        throw new Error(uploadResult.error || "Unknown file upload error.");
      }
      document.getElementById("uploadedFileName").innerHTML =
        `<a href="uploads/${uploadResult.stored_name}" download>${uploadResult.file}</a>`;
      document.getElementById("deleteFileBtn").style.display = "inline";
    }

    // Assign if dropdown selected
    const select = document.getElementById("modalAssignUser");
    if (select) {
      const userId = parseInt(select.value, 10);
      if (userId) {
        const aRes = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "assign_task", task_id: taskId, user_id: userId })
        });
        const aData = await aRes.json();
        if (!aRes.ok || !aData.ok) {
          alert("Assign failed: " + (aData.error || "Unknown"));
        }
      }
    }

    // Save deadline
    await fetch("save_deadline.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        task_id: taskId,
        deadline_date: document.getElementById("modalDeadline").value
      })
    });

    // Save description
    await fetch("taskmanager.php?group_id=" + GROUP_ID, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "update_description",
        task_id: taskId,
        description: document.getElementById("modalDescription").value
      })
    });

    // Save feedback
    await fetch("taskmanager.php?group_id=" + GROUP_ID, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "update_feedback",
        task_id: taskId,
        feedback: document.getElementById("modalFeedback").value
      })
    });

    alert("Save successful");
    document.getElementById("taskModal").style.display = "none";
    refreshTasks();

  } catch (err) {
    alert("Save failed: " + err.message);
  }
}
{async function removeAssignment(taskId) {
  const res = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "remove_task_assigned_to", task_id: taskId })
  });
  const data = await res.json();

  if (data.ok) {
    // Ensure groupUsers is available
    if (!Array.isArray(window.groupUsers) || window.groupUsers.length === 0) {
      await fetchGroupUsers(); // your helper that populates window.groupUsers
    }

    const optionsHtml = (window.groupUsers || []).map(u =>
      `<option value="${u.id}">${escapeHtml(u.name)}</option>`
    ).join('');

    document.getElementById("assignedUserBox").innerHTML = `
      <select id="modalAssignUser">
        <option value="">-- Select member --</option>
        ${optionsHtml}
      </select>
    `;
  } else {
    alert("Remove failed: " + (data.error || "Unknown"));
  }
}



// Open modal + load task details
async function openTaskModal(taskId) {
    currentTaskId = taskId;

    const res = await fetch("taskmanager.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "get_task", task_id: taskId })
    });
    const data = await res.json();

    if (!data.success) {
        alert(data.error || "Failed to load task");
        return;
    }

    // Fill modal
    document.getElementById("modalTitle").textContent = data.task.title;
    document.getElementById("modalDescription").value = data.task.description;
    document.getElementById("modalFeedback").value = data.task.feedback || '';

    // Update lock button state
    const lockBtn = document.getElementById("lockBtn");

    // Initial state update
    if (data.task._lock == 1) {
        lockBtn.textContent = "🔓 Unlock";
    } else {
        lockBtn.textContent = "🔒 Lock";
    }

    // Optional: Toggle button label on click (visual only)
    lockBtn.addEventListener("click", () => {
        if (lockBtn.textContent.includes("Unlock")) {
            lockBtn.textContent = "🔒 Lock";
        } else {
            lockBtn.textContent = "🔓 Unlock";
        }
    })

    // Show modal
    document.getElementById("taskModal").style.display = "block";

    let currentTaskId = null;

/* ---------------------------
   Task Modal (open + save)
---------------------------- */
function openTaskModal(taskId) {
  fetch('task_details.php?task_id=' + taskId)
    .then(r => r.json())
    .then(data => {
      if (data.error) return alert(data.error);

      document.getElementById('modalTitle').textContent = data.title;
      document.getElementById('modalAssigned').textContent = data.assigned_to_name || 'Unassigned';
      document.getElementById('modalDeadline').value = data.deadline || '';
      document.getElementById('modalDescription').value = data.description || '';
      document.getElementById('modalFeedback').value = data.feedback || '';
      document.getElementById('modalStatus').value = data._status || 1;
      document.getElementById('taskModal').dataset.taskId = taskId;

      loadFiles(taskId); // load files into modal
      document.getElementById('taskModal').style.display = 'flex';
    })
    .catch(err => {
      console.error(err);
      alert("Failed to load task details");
    });
}

let groupUsers = [];

// Fetch all group users once
async function fetchGroupUsers() {
  const res = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "list_group_users" })
  });
  const data = await res.json();
  if (data.ok) {
    groupUsers = data.users;
  }
}
fetchGroupUsers(); // load at startup

// Delegate input listener so it works for dynamically inserted fields
document.addEventListener("input", function (e) {
  if (e.target && e.target.id === "modalAssignName") {
    showUserSuggestions(e.target);
  }
});

function showUserSuggestions(inputEl) {
  const suggestions = document.getElementById("userSuggestions");
  if (!suggestions) return;

  const input = inputEl.value.toLowerCase();
  suggestions.innerHTML = "";

  if (input.length === 0) {
    suggestions.style.display = "none";
    return;
  }

  const matches = groupUsers.filter(u => u.name.toLowerCase().includes(input));
  if (matches.length === 0) {
    suggestions.style.display = "none";
    return;
  }

  matches.forEach(u => {
    const div = document.createElement("div");
    div.className = "suggestion-item";
    div.textContent = u.name;
    div.addEventListener("click", () => {
      inputEl.value = u.name;
      suggestions.innerHTML = "";
      suggestions.style.display = "none";
    });
    suggestions.appendChild(div);
  });

  suggestions.style.display = "block";
}


async function saveTaskModal() {
  const taskId = document.getElementById("taskModal").dataset.taskId;
  const deadlineValue = document.getElementById("modalDeadline").value;

  const payload = {
    action: "update_status",
    task_id: taskId,
    _status: document.getElementById("modalStatus").value,
    deadline: deadlineValue,
    description: document.getElementById("modalDescription").value,
    feedback: document.getElementById("modalFeedback").value
  };

  try {
    // ✅ Deadline validation
    if (deadlineValue) {
      const deadlineDate = new Date(deadlineValue);
      const today = new Date();
      today.setHours(0, 0, 0, 0); // normalize to midnight
      deadlineDate.setHours(0, 0, 0, 0);

      if (deadlineDate < today) {
        alert("❌ Error: The deadline you entered has already passed.");
        return; // stop execution
      }
    }

    // 1) Save description/deadline/status/feedback
    const res = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });

    if (!res.ok) {
      throw new Error(`Server error while saving task details (HTTP ${res.status})`);
    }

    const result = await res.json();
    if (!result.success) {
      throw new Error(result.error || "Failed to save task details (no reason provided).");
    }

    // 2) Upload files (if any)
    const fileInput = document.getElementById("taskFile");
    if (fileInput.files.length > 0) {
      const formData = new FormData();
      formData.append("task_file", fileInput.files[0]);
      formData.append("task_id", taskId);
      formData.append("group_id", GROUP_ID);

      const uploadRes = await fetch("upload_file.php", { method: "POST", body: formData });

      if (!uploadRes.ok) {
        throw new Error(`Server error during file upload (HTTP ${uploadRes.status})`);
      }

      const uploadResult = await uploadRes.json();

      if (!uploadResult.success) {
        throw new Error(uploadResult.error || "Unknown file upload error.");
      }

      document.getElementById("uploadedFileName").innerHTML =
        `<a href="uploads/${uploadResult.stored_name}" download>${uploadResult.file}</a>`;
      document.getElementById("deleteFileBtn").style.display = "inline";
    }

    // Get name entered
    const assignName = document.getElementById("modalAssignName").value.trim();

    if (assignName) {
      const res = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "assign_task_by_name", task_id: taskId, name: assignName })
      });
      const result = await res.json();
      if (!result.ok) {
        alert("Assign failed: " + (result.error || "Unknown error"));
      }
    }

    // ✅ Success
    alert("Save successful");

    // 3) Close + refresh
    document.getElementById("taskModal").style.display = "none";
    refreshTasks();

    // Save deadline 
    await fetch("save_deadline.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        task_id: taskId,
        deadline_date: deadlineValue // YYYY-MM-DD if <input type="date">
      })
    });

    // Save description
    await fetch("taskmanager.php?group_id=" + GROUP_ID, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "update_description",
        task_id: taskId,
        description: document.getElementById("modalDescription").value
      })
    }).then(r => r.json()).then(res => {
      if (!res.success) {
        alert("Description save failed: " + (res.error || "Unknown error"));
      }
    });

    await fetch("taskmanager.php?group_id=" + GROUP_ID, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "update_feedback",
        task_id: taskId,
        feedback: document.getElementById("modalFeedback").value
      })
    }).then(r => r.json()).then(res => {
      if (!res.success) {
        alert("Feedback save failed: " + (res.error || "Unknown error"));
      }
    });

  } catch (err) {
    // ❌ Show *why* it failed
    alert("Save failed: " + err.message);
  }
}



// ✅ only one set of listeners
document.addEventListener("DOMContentLoaded", () => {
  const saveBtn = document.getElementById("modalSave");
  const closeBtn = document.getElementById("modalClose");

  if (saveBtn) {
    saveBtn.addEventListener("click", saveTaskModal);
  }
  if (closeBtn) {
    closeBtn.addEventListener("click", () => {
      document.getElementById("taskModal").style.display = "none";
    });
  }
});

/* ---------------------------
   Files
---------------------------- */
async function loadFiles(taskId) {
  const res = await fetch("list_files.php?task_id=" + taskId);
  const data = await res.json();
  const fileList = document.getElementById("fileList");

  if (!data.files || data.files.length === 0) {
    fileList.innerHTML = "<em>Unknown</em>";
    document.getElementById('uploadedFileName').textContent = "Unknown";
    document.getElementById('deleteFileBtn').style.display = "none";
    return;
  }

  fileList.innerHTML = data.files.map(f => `
    <div>
      <a href="${f.file_path}" download>${f.file_name}</a>
      ${f.can_delete ? `<button onclick="deleteFile(${f.id}, ${taskId})">✖</button>` : ""}
    </div>
  `).join("");

  document.getElementById('uploadedFileName').innerHTML =
    `<a href="${data.files[0].file_path}" download>${data.files[0].file_name}</a>`;
  document.getElementById('deleteFileBtn').style.display = "inline";
  document.getElementById('deleteFileBtn').onclick = () => deleteFile(data.files[0].id, taskId);
}

async function deleteFile(fileId, taskId) {
  const res = await fetch("delete_file.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ file_id: fileId, task_id: taskId, group_id: GROUP_ID })
  });
  const data = await res.json();
  if (data.success) {
    loadFiles(taskId);
  } else {
    alert("Delete failed: " + (data.error || "unknown"));
  }
}

/* ---------------------------
   Refresh members modals & tasks
---------------------------- */
async function refreshMembers() {
  const data = await apiJson('list_members');
  const container = document.getElementById('membersList');
  container.innerHTML = '';

  if (!data.members || data.members.length === 0) {
    container.innerHTML = '<div class="card">No members yet.</div>';
    return;
  }

  for (const m of data.members) {
    const div = document.createElement('div');
    div.className = 'member-item';
    div.dataset.userId = m.id;
    div.dataset.privilege = m.privilege; // keep privilege for later checks
    div.style.cssText = `
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      margin-bottom:8px;
    `;

    let html = `
      <!-- Left side: name -->
      <div class="member-name" style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
        ${escapeHtml(m.name)}
      </div>
      <!-- Right side: role + (maybe dropdown) -->
      <div class="member-actions" style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
        <div class="pill">${escapeHtml(m.role)}</div>
      </div>
    `;

    // conditionally add dropdown
    if (IS_OWNER || CURRENT_ROLE === 'mod' || CURRENT_PRIVILEGE > 1) {
      html += `
        <div class="dropdown-report">
          <button class="dropdown-btn">⋮</button>
          <div class="dropdown-content">
            <a href="view_profile.php?user_id=<?= (int)$m['id'] ?>" class="profile">
              <button class="reportAction">View Profile</button>
            </a>
            <button class="reportAction" data-action="kick">Kick Member</button>
            <button class="reportAction" data-action="blacklist">Blacklist User</button>
          </div>
      `;
    }

    html += `</div>`; // close .member-actions
    div.innerHTML = html;
    container.appendChild(div);
  }
}

// Auto-submit on select
// Toggle dropdown
document.getElementById('membersList').addEventListener('click', e => {
  if (e.target.classList.contains('dropdown-btn')) {
    e.stopPropagation();
    const content = e.target.nextElementSibling;
    content.classList.toggle('show');
    return;
  }

  if (e.target.classList.contains('reportAction')) {
    const dropdown  = e.target.closest('.dropdown-content');
    const userId    = dropdown.closest('.member-item').dataset.userId;
    const privilege = Number(dropdown.closest('.member-item').dataset.privilege || 0);
    const action    = e.target.dataset.action;
    // Confirmation
    const reasonEl  = dropdown.querySelector('.reportReason');
    let reason      = reasonEl ? reasonEl.value.trim() : '';

    // 🔑 Auto-generate reason if empty
    if (!reason) {
      reason = `Auto-generated reason: ${action} performed at ${new Date().toLocaleString()}`;
    }

    // Privilege check
    if (action === 'kick' && privilege > 1) {
      alert("⚠️ Can't kick someone with the privilege level above 1.");
      return;
    }


     fetch(WORKER_PATH, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'report_member',
        reported_user_id: userId,
        reason: reason,          // always defined now
        actionType: action,
        group_id: GROUP_ID
      })
    })
    .then(res => res.json())
    .then(result => {
      if (result.success) {
        alert("✅ Action completed successfully.");
        refreshMembers();
        dropdown.classList.remove('show');
      } else {
        alert("⚠️ Server error: " + (result.error || "Unknown"));
      }
    })
    .catch(err => {
      console.error("Report failed:", err);
      alert("⚠️ Could not send report.");
    });
  }
});

// Close dropdowns when clicking outside
document.addEventListener('click', e => {
  document.querySelectorAll('.dropdown-content.show').forEach(dc => {
    if (!dc.contains(e.target) && !e.target.classList.contains('dropdown-btn')) {
      dc.classList.remove('show');
    }
  });
});



async function refreshTasks() {
  const data = await apiJson('list_tasks');
  const container = document.getElementById('tasksList');
  container.innerHTML = '';

  if (!data.tasks || data.tasks.length === 0) {
    container.innerHTML = '<div class="card">No tasks yet.</div>';
    return;
  }

  for (const t of data.tasks) {
    const div = document.createElement('div');
    div.className = 'task-card task-item';
    div.dataset.taskId = t.id;

    const assigned = t.assigned_to_name 
      ? `<span style="font-size:12px;color:#6b7280; margin-right:6px">${escapeHtml(t.assigned_to_name)}</span>`
      : '';

    div.innerHTML = `
      <span class="ellipsis" title="${escapeHtml(t.title)}">${escapeHtml(t.title)}</span>
      <div class="task-actions">${assigned}
        <form method="post" style="margin:0; display:inline;">
          <input type="hidden" name="action" value="delete_task">
          <input type="hidden" name="task_id" value="${t.id}">
          <button type="submit" class="delete-btn" title="Delete task">✖</button>
        </form>
      </div>
    `;

    // Double-click → open modal with task details
    div.addEventListener('dblclick', () => openTaskModal(t.id));
    container.appendChild(div);
  }
}


function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/[&<>"']/g, (s) => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[s]));
}

/* ---------------------------
   Chat
---------------------------- */
const chatForm = document.getElementById('chatForm');
const chatBox = document.getElementById('chatBox');

if (chatForm && chatBox) {
  chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = chatForm.elements['message'].value.trim();
    if (!message) return;

    const res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'add_chat', group_id: GROUP_ID, message })
    });

    const data = await res.json();
    if (data.error) {
      alert(data.error);
      return;
    }

    const msgDiv = document.createElement('div');
    msgDiv.textContent = message;
    chatBox.appendChild(msgDiv);
    chatBox.scrollTop = chatBox.scrollHeight;
    chatForm.elements['message'].value = '';
  });

  chatBox.scrollTop = chatBox.scrollHeight;
}

/* ---------------------------
   Init
---------------------------- */
document.addEventListener('DOMContentLoaded', () => {
  refreshMembers();
  refreshTasks();
  setInterval(() => { refreshMembers(); refreshTasks(); }, 15000);
});

/* ---------------------------
   Get Files
---------------------------- */
document.addEventListener('DOMContentLoaded', () => {
  const toggleFilesBtn = document.getElementById('toggleFiles');
  const filesBox = document.getElementById('filesBox');

  if (!toggleFilesBtn || !filesBox) return; // safety check

  toggleFilesBtn.addEventListener('click', (e) => {
    e.stopPropagation(); // prevent closing when clicking the button itself
    const isHidden = filesBox.classList.contains('hidden');

    // Optional: close deadlines if open
    const deadlineBox = document.getElementById('deadlineBox');
    if (deadlineBox) deadlineBox.classList.add('hidden');

    if (isHidden) {
      filesBox.classList.remove('hidden');
      toggleFilesBtn.setAttribute('aria-expanded', 'true');
    } else {
      filesBox.classList.add('hidden');
      toggleFilesBtn.setAttribute('aria-expanded', 'false');
    }
  });

  // ✅ Hide filesBox when clicking anywhere else
  document.addEventListener('click', (e) => {
    const clickedOutside =
      !filesBox.contains(e.target) &&
      !toggleFilesBtn.contains(e.target);

    const clickedOtherButton =
      e.target.tagName === 'BUTTON' &&
      e.target !== toggleFilesBtn;

    if (!filesBox.classList.contains('hidden') &&
        (clickedOutside || clickedOtherButton)) {
      filesBox.classList.add('hidden');
      toggleFilesBtn.setAttribute('aria-expanded', 'false');
    }
  });
});



// Example: Load a task into a modal (optional, adjust as you need)
function loadTask(taskId) {
  fetch("task_details.php?task_id=" + taskId)
    .then(res => res.json())
    .then(task => {
      // Fill modal fields with task data
      document.getElementById("taskTitle").textContent = task.title;
      document.getElementById("taskDescription").textContent = task.description || "";
      document.getElementById("taskfeedback").textContent = task.feedback || "";
      document.getElementById("taskCreatedAt").textContent = task.created_at;
      document.getElementById("taskCreatedBy").textContent = task.created_by;
    })
    .catch(err => {
      console.error("Failed to load task", err);
    });
}


document.querySelectorAll(".task-item").forEach(item => {
  item.addEventListener("click", () => {
    const taskId = item.dataset.taskId;
    loadTask(taskId);
  });
});


// ---- Minimal modal lock system (drop this into your existing script) ----

// ensure a global currentTaskId exists (this avoids duplicate let/var problems)
window.currentTaskId = window.currentTaskId || null;

function attachModalLock(task) {
  if (!task || !document) return;

  // set global id for toggle
  window.currentTaskId = task.id || task.task_id || null;

  const btn = document.getElementById('lockToggleBtn');
  if (!btn) return;

  // safe text & dataset update
  const lockVal = (typeof task._lock !== 'undefined') ? Number(task._lock) : Number(task.lock ?? 0);
  renderModalLock(lockVal);

  // replace handler to avoid duplicates
  btn.onclick = (e) => {
    e.stopPropagation();
    toggleLockForCurrentTask();
  };
}

/** update the modal lock button visuals */
  // Render lock button based on server _lock value
function renderLockButton(lockValue) {
  const btn = document.getElementById("lockToggleBtn");
  if (!btn) return;

  // 1 = locked, 0 = unlocked (based on your DB meaning)
  btn.textContent = lockValue == 1 ? "🔒" : "🔓";
  btn.dataset.lock = lockValue;
}


async function toggleLock() {
  const taskId = document.getElementById("taskModal").dataset.taskId;
  if (!taskId) return;

  const res = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "toggle_lock", task_id: taskId })
  });

  const data = await res.json();
  if (!data.success) {
    alert(data.error || "Failed to toggle lock");
    return;
  }

  // Update button icon
  renderLockButton(data.lock);
}

// Attach event ONCE
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("lockToggleBtn");
  if (btn) {
    btn.addEventListener("click", toggleLock);
  }
});


/** call server to toggle lock for window.currentTaskId and refresh button */
async function toggleLockForCurrentTask() {
  const taskId = window.currentTaskId || document.getElementById('taskModal')?.dataset.taskId;
  if (!taskId) return alert('No task selected');

  try {
    const res = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'toggle_lock', task_id: taskId })
    });
    const data = await res.json();

    if (!data.success) throw new Error(data.error || 'Server failed');

    // Update UI after toggle
    const btn = document.getElementById('lockToggleBtn');
    if (btn) {
      const isLocked = Number(data.lock) === 1;
      btn.textContent = isLocked ? '🔒' : '🔓';
      btn.dataset.lock = isLocked ? '1' : '0';
    }
  } catch (err) {
    console.error(err);
    alert('Toggle failed: ' + err.message);
  }
}


document.addEventListener("input", function(e) {
  if (e.target.id === "modalAssignName") {
    const input = e.target.value.toLowerCase();
    const suggestions = document.getElementById("userSuggestions");
    suggestions.innerHTML = "";

    if (input.length === 0) {
      suggestions.style.display = "none";
      return;
    }

    const matches = groupUsers.filter(u => u.name.toLowerCase().includes(input));
    if (matches.length === 0) {
      suggestions.style.display = "none";
      return;
    }

    matches.forEach(u => {
      const div = document.createElement("div");
      div.className = "suggestion-item";
      div.textContent = u.name;
      div.onclick = () => {
        document.getElementById("modalAssignName").value = u.name;
        suggestions.innerHTML = "";
        suggestions.style.display = "none";
      };
      suggestions.appendChild(div);
    });

    suggestions.style.display = "block";
  }
});



window.removeAssignment = async function(taskId) {
  if (!confirm('Remove user from this task?')) return;

  try {
    const res = await fetch("taskmanager.php?group_id=" + GROUP_ID, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "remove_task_assigned_to", task_id: taskId })
    });

    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || 'Unknown server error');
    }

    // Ensure groupUsers is available
    if (!Array.isArray(window.groupUsers) || window.groupUsers.length === 0) {
      if (typeof fetchGroupUsers === "function") {
        await fetchGroupUsers();
      }
    }

    // Build options from groupUsers
    const optionsHtml = (window.groupUsers || []).map(u =>
      `<option value="${u.id}">${escapeHtml(u.name)}</option>`
    ).join('');

    // Reset modal assigned UI back to dropdown
    const assignedBox = document.getElementById("assignedUserBox");
    if (assignedBox) {
      assignedBox.innerHTML = `
        <select id="modalAssignUser">
          <option value="">-- Select member --</option>
          ${optionsHtml}
        </select>
      `;
    }

    // Refresh lists so UI shows the change
    if (typeof refreshTasks === "function") refreshTasks();

  } catch (err) {
    alert("Remove failed: " + err.message);
    console.error(err);
  }
};

function toggleSidebar() {
  document.querySelector('.sidebar').classList.toggle('hidden');
}

function copyGroupCode() {
  const code = document.getElementById('groupCode').innerText;
  navigator.clipboard.writeText(code).then(() => {
    alert('Group code copied to clipboard!');
  }).catch(err => {
    alert('Failed to copy: ' + err);
  });
}

// Toggle dropdown
const reportBtn = document.getElementById('reportDropdownBtn');
const reportDropdown = document.getElementById('reportDropdown');

reportBtn.addEventListener('click', () => {
  const isVisible = reportDropdown.style.display === 'block';
  reportDropdown.style.display = isVisible ? 'none' : 'block';
});

// Optional: close dropdown if clicking outside
window.addEventListener('click', (e) => {
  if (!reportBtn.contains(e.target) && !reportDropdown.contains(e.target)) {
    reportDropdown.style.display = 'none';
  }
});



document.addEventListener("DOMContentLoaded", () => {
  const color = localStorage.getItem("activeGroupColor") || "#a289ecff"; // fallback violet

  // Apply color to header and main elements
  document.querySelector("header")?.style.setProperty("background", color);
  document.body.style.setProperty("--accent-color", color);

  // Optional: tint buttons, progress bars, etc.
  document.querySelectorAll(".btn, .progress-box strong").forEach(el => {
    el.style.backgroundColor = color;
    el.style.borderColor = color;
  });
});

async function loadGroupUsers() {
  const res = await fetch('taskmanager.php?group_id=' + groupId, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'list_group_users' })
  });
  const data = await res.json();
  if (data.ok) {
    const select = document.getElementById('modalAssignName');
    select.innerHTML = '<option value="">-- Select member --</option>';
    data.users.forEach(u => {
      const opt = document.createElement('option');
      opt.value = u.id;
      opt.textContent = u.name;
      select.appendChild(opt);
    });
  }
}

</script>
</body>
</html>

