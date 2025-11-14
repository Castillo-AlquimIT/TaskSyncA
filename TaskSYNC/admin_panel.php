<?php
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database credentials for backup
$dbHost = 'localhost';
$dbUser = 'your_username';
$dbPass = 'your_password';
$dbName = 'your_database_name';

// Only allow Moderators (privilege_mode = 3)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user
$stmt = $pdo->prepare("SELECT name, privilege_mode FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['privilege_mode'] != 3) {
    echo "Access Denied.";
    exit;
}

$user_name = $user['name'];

// --- Handle delete or ban actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_user'])) {
        $del_id = (int)$_POST['delete_user'];
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$del_id]);
        header("Location: admin_panel.php");
        exit;
    }
    
    //The toggle is functioning however it's not making the user banned for now.
    if (isset($_POST['ban_user'])) {
        $ban_id = (int)$_POST['ban_user'];

        // Fetch current banned status
        $stmt = $pdo->prepare("SELECT banned FROM users WHERE id = ?");
        $stmt->execute([$ban_id]);
        $current = $stmt->fetchColumn();

        // Toggle banned status
        $new_status = $current ? 0 : 1;

        $pdo->prepare("UPDATE users SET banned = ? WHERE id = ?")->execute([$new_status, $ban_id]);

        header("Location: admin_panel.php");
        exit;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_backup'])) {
    if (!empty($_POST['backup_db'])) {
        $backupDir = __DIR__ . '/backups/';
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

        $backupFile = $backupDir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';

        $command = "\"$mysqldump\" --opt -h{$dbHost} -u{$dbUser} -p{$dbPass} {$dbName} > \"{$backupFile}\"";

        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);

        // Show modal popup instead of echo
        if ($return_var === 0) {
            echo <<<HTML
            <div id="backupModal" style="
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.5); display: flex; justify-content: center;
                align-items: center; z-index: 9999;
            ">
                <div style="
                    background: #fff; padding: 20px; border-radius: 8px;
                    max-width: 400px; text-align: center;
                ">
                    <h2>✅ Database Backup Completed</h2>
                    <p>Database backup created at:<br><strong>{$backupFile}</strong></p>
                    <button id="closeModal" style="
                        margin-top: 15px; padding: 8px 16px; background: #4CAF50; color: #fff;
                        border: none; border-radius: 4px; cursor: pointer;
                    ">Close</button>
                </div>
            </div>
            <script>
                document.getElementById('closeModal').addEventListener('click', function() {
                    document.getElementById('backupModal').style.display = 'none';
                });
            </script>
            HTML;
        } else {
            echo <<<HTML
            <div id="backupModal" style="
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.5); display: flex; justify-content: center;
                align-items: center; z-index: 9999;
            ">
                <div style="
                    background: #fff; padding: 20px; border-radius: 8px;
                    max-width: 400px; text-align: center;
                ">
                    <h2>❌ Database Backup Failed</h2>
                    <p>Please check your credentials or mysqldump path.</p>
                    <button id="closeModal" style="
                        margin-top: 15px; padding: 8px 16px; background: #f44336; color: #fff;
                        border: none; border-radius: 4px; cursor: pointer;
                    ">Close</button>
                </div>
            </div>
            <script>
                document.getElementById('closeModal').addEventListener('click', function() {
                    document.getElementById('backupModal').style.display = 'none';
                });
            </script>
            HTML;
        }
    }




    // --- Uploads backup ---
if (!empty($_POST['backup_uploads'])) {
    $src = __DIR__ . '/uploads';
    $dst = __DIR__ . '/backups/uploads_' . date('Ymd_His');
    mkdir($dst, 0755, true);

    $directory = new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $subPath = $iterator->getSubIterator()->getSubPathname();
        $target = $dst . DIRECTORY_SEPARATOR . $subPath;

        if ($item->isDir()) {
            mkdir($target, 0755, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }

    // Show modal popup
    echo <<<HTML
    <div id="backupModal" style="
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); display: flex;
        justify-content: center; align-items: center; z-index: 9999;
    ">
        <div style="
            background: #fff; padding: 20px; border-radius: 8px;
            max-width: 400px; text-align: center;
        ">
            <h2>✅ Backup Completed</h2>
            <p>Uploads folder backed up to:<br><strong>{$dst}</strong></p>
            <button id="closeModal" style="
                margin-top: 15px; padding: 8px 16px; background: #4CAF50; color: #fff;
                border: none; border-radius: 4px; cursor: pointer;
            ">Close</button>
        </div>
    </div>
    <script>
        document.getElementById('closeModal').addEventListener('click', function() {
            document.getElementById('backupModal').style.display = 'none';
        });
    </script>
    HTML;
  }
}



// --- CHAT LOGS ---
$chatLogs = $pdo->query("
    SELECT c.id, c.message, c.created_at,
           u.name AS user_name, g.name AS group_name
    FROM chat_messages c
    JOIN users u ON c.user_id = u.id
    JOIN task_groups g ON c.group_id = g.id
    ORDER BY c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// --- GROUP LOGS ---
$groupLogs = $pdo->query("
    SELECT g.id, g.name, g.code, u.name AS owner_name
    FROM task_groups g
    JOIN users u ON g.owner_user_id = u.id
    ORDER BY g.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// --- USERS ---
$userList = $pdo->query("
    SELECT id, name, email, privilege_mode, banned, created_at
    FROM users
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);



$privilege_mode = [
    'member'  => 0,
    'owner'   => 1,
    'teacher' => 2,
    'mod'   => 3
];

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Admin Panel</title>
  <link rel="stylesheet" href="dashboardstyle.css">
  <style>
    .container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px; }
    .box { max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #ccc; padding: 15px; }
    .box h2 { margin-top: 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
    .chat-log { font-size: 14px; background: #fafafa; padding: 10px; border-radius: 6px; }
    .chat-item { margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px dashed #ddd; }
  </style>
</head>
<body>

  <div class="sidebar">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
      <h1 style="margin:20px;">Welcome <?= htmlspecialchars($user_name) ?></h1>
    </div>
  <h2>TaskSync</h2>
  <ul>
    <li><a href="dashboard.php">📚Dashboard</a></li>
    <li><a href="calendar.php">📅Calendar</a></li>
    <li><a href = "profile.php">👤Profile</a></li>
    <li> <a href= "Projects.php">📘Projects</a></li>
    <li><a href="logout.php" class="logout">➜]Logout</a></li>
    <li><a href="admin_panel.php"class="active">🔧Moderator Panel</a></li>
    </ul>
</div>

<div class="main">

  <div class="container">
    <!-- Chat Logs -->
    <div class="box">
      <h2>Chat Logs</h2>
      <div class="chat-log">
        <?php foreach ($chatLogs as $log): ?>
          <div class="chat-item">
            <strong>[<?= htmlspecialchars($log['group_name']) ?>]</strong>
            <?= htmlspecialchars($log['user_name']) ?>:
            <?= htmlspecialchars($log['message']) ?>
            <em>(<?= $log['created_at'] ?>)</em>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Groups -->
    <div class="box">
      <h2>Groups</h2>
      <table>
        <tr><th>ID</th><th>Group Name</th><th>Share Code</th><th>Owner</th></tr>
        <?php foreach ($groupLogs as $group): ?>
          <tr>
            <td><?= htmlspecialchars($group['id']) ?></td>
            <td><?= htmlspecialchars($group['name']) ?></td>
            <td><?= htmlspecialchars(string: $group['code']) ?></td>
            <td><?= htmlspecialchars($group['owner_name']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
   </div> 
<div class="box">
  <div style="display: flex; align-items: center; justify-content: space-between;">
      <h2>Find Users</h2>
      <form method="get" action="admin_panel.php" style="margin: 0; display: flex; gap: 5px;">
          <input type="text" name="search_user" placeholder="Search by ID or Name" 
                 value="<?= isset($_GET['search_user']) ? htmlspecialchars($_GET['search_user']) : '' ?>">
          <button type="submit">Find</button>
      </form>
  </div>

  <?php
  // ✅ Search and fetch users
  $search_user = isset($_GET['search_user']) ? trim($_GET['search_user']) : '';
  if ($search_user !== '') {
      $stmt = $pdo->prepare("SELECT id, name, email, privilege_mode, banned, created_at 
                             FROM users WHERE id = ? OR name LIKE ?");
      $stmt->execute([$search_user, "%$search_user%"]);
  } else {
      $stmt = $pdo->query("SELECT id, name, email, privilege_mode, banned, created_at FROM users");
  }
  $userList = $stmt->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <table id="userTable" style="width: 100%; margin-top: 15px; border-collapse: collapse;">
    <thead>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Email</th>
        <th>Privilege</th>
        <th>Status</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($userList): ?>
        <?php foreach ($userList as $usr): ?>
          <?php $role_name = array_search($usr['privilege_mode'], $privilege_mode); ?>
          <tr>
            <td><?= htmlspecialchars($usr['id']) ?></td>
            <td><?= htmlspecialchars($usr['name']) ?></td>
            <td><?= htmlspecialchars($usr['email']) ?></td>
            <td><?= htmlspecialchars($role_name) ?></td>
            <td><?= $usr['banned'] ? 'Banned' : 'Active' ?></td>
            <td><?= htmlspecialchars($usr['created_at']) ?></td>
            <td>
              <form method="post" style="display:inline;">
                  <button type="submit" name="ban_user" value="<?= $usr['id'] ?>">
                      <?= $usr['banned'] ? 'Unban' : 'Ban' ?>
                  </button>
              </form>
              <form method="post" style="display:inline;">
                  <button type="submit" name="delete_user" value="<?= $usr['id'] ?>">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="7" style="text-align:center;">No users found</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
  </div>
  <div class="box">
  <h2>Backup System</h2>
  <form method="post" style="margin-bottom:10px;">
    <label>
      <input type="checkbox" name="backup_db" value="1"> Database
    </label>
    <label>
      <input type="checkbox" name="backup_uploads" value="1"> Uploads Folder
    </label>
    <button type="submit" name="run_backup">Run Backup Now</button>
  </form>

  <form method="post">
    <label for="interval">Set Backup Interval (days):</label>
    <input type="number" name="interval_days" id="interval" min="1" value="7">
    <button type="submit" name="set_interval">Save Interval</button>
  </form>
    <form method="post">
    <label for="interval">Set Backup Interval (Week):</label>
    <input type="number" name="interval_Week" id="interval" min="1" value="4">
    <button type="submit" name="set_interval">Save Interval</button>
  </form>
</div>
</body>
<script>
// 🔎 Live search filter
document.getElementById('userSearch').addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let rows = document.querySelectorAll('#userTable tbody tr');
    rows.forEach(row => {
        let id = row.cells[0].textContent.toLowerCase();
        let name = row.cells[1].textContent.toLowerCase();
        if (id.includes(filter) || name.includes(filter)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});
</script>
</html>