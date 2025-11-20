<?php
// Projects.php
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Unknown';
$privilege = (int)($_SESSION['privilege_mode'] ?? 0); // 0=member, 1=owner, 2=teacher, 3=moderator



// Fetch user info
$stmt = $pdo->prepare("SELECT name, privilege_mode, banned FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // No user found
    session_destroy();
    header("Location: login.php");
    exit;
}

$user_name = $user['name'];
$privilege = $user['privilege_mode'];
$banned    = $user['banned'];

// 🚫 If banned, force logout
if ($banned == 1) {
    session_destroy();
    echo "Your account has been banned. Please contact support.";
    exit;
}
// ✅ Continue if allowed




// create group handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $gname = trim($_POST['group_name'] ?? '');
    if ($gname !== '') {
        // generate code
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i=0;$i<6;$i++) $code .= $chars[random_int(0, strlen($chars)-1)];
        // insert group
        $stmt = $pdo->prepare('INSERT INTO task_groups (name, code, owner_user_id) VALUES (?, ?, ?)');
        $stmt->execute([$gname, $code, $user_id]);
        $gid = (int)$pdo->lastInsertId();
        // add owner as member
        $stmt = $pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
        $stmt->execute([$gid, $user_id, 'owner']);
        header("Location: taskmanager.php?group_id={$gid}");
        exit;
    }
}


// Map privilege codes to role names
$privilegeMap = [
    0 => 'member',
    1 => 'owner',
    2 => 'teacher',
    3 => 'mod'
];

// Derive role from privilege
$role = $privilegeMap[$privilege] ?? 'view';

$currentRole = $role;

// join group by code
$join_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_group'])) {
    $code = strtoupper(trim($_POST['join_code'] ?? ''));
    if ($code === '') {
        $join_err = 'Enter code.';
    } else {
        // 🔍 Find group by join code
        $stmt = $pdo->prepare('SELECT id FROM task_groups WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $g = $stmt->fetch();

        if (!$g) {
            $join_err = 'Invalid code.';
        } else {
            $gid = (int)$g['id'];

            // 🚫 Check if user is blacklisted from this group
            $stmt = $pdo->prepare('SELECT 1 FROM group_blacklist WHERE group_id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$gid, $user_id]);
            if ($stmt->fetch()) {
                $join_err = 'You are blacklisted from this group and cannot join.';
            } else {
                // ✅ If not blacklisted, check if already a member
                $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
                $stmt->execute([$gid, $user_id]);
                if (!$stmt->fetch()) {
                    // Insert member (use mapped role)
                    $stmt = $pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
                    $stmt->execute([$gid, $user_id, $role]);
                }
                header("Location: taskmanager.php?group_id={$gid}");
                exit;
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group'])) {
    $gid = (int)$_POST['delete_group'];

    // Check role
    $stmt = $pdo->prepare("SELECT role, group_id FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$gid, $user_id]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($membership) {
        $role = strtolower($membership['role']);
        if ($privilege === 3 || $role === 'owner' || $role === 'teacher') {
            // Delete the group
            $stmt = $pdo->prepare("DELETE FROM task_groups WHERE id = ?");
            $stmt->execute([$gid]);
        }
    }
    header("Location: Projects.php");
    exit;
}


// fetch groups with membership + owner info
$stmt = $pdo->prepare("
    SELECT g.id, g.name, g.code, g.owner_user_id, gm.role
    FROM task_groups g
    JOIN group_members gm ON g.id = gm.group_id
    WHERE gm.user_id = ?
    ORDER BY g.created_at DESC
");
$stmt->execute([$user_id]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// role labels
$roleLabels = [
    0 => 'Member',
    1 => 'Owner',
    2 => 'Teacher',
    3 => 'Moderator'
];


// fetch only 3 most recently opened groups

$stmt = $pdo->prepare("
    SELECT g.id, g.name, g.code, g.owner_user_id, gm.role,
           COUNT(t.id) AS total_tasks,
           SUM(CASE WHEN d._status = 3 THEN 1 ELSE 0 END) AS completed_tasks
    FROM task_groups g
    JOIN group_members gm ON g.id = gm.group_id
    LEFT JOIN tasks t ON g.id = t.group_id
    LEFT JOIN task_deadline d ON t.id = d.task_id
    WHERE gm.user_id = ?
    GROUP BY g.id, g.name, g.code, g.owner_user_id, gm.role
    ORDER BY g.id DESC
");
$stmt->execute([$user_id]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);



// Get tasks from those groups
$groupIds = array_column($groups, 'id');
$tasks = [];
if (!empty($groupIds)) {
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt2 = $pdo->prepare("
        SELECT t.id, t.title, t.group_id, d._status
        FROM tasks t
        LEFT JOIN task_deadline d ON d.task_id = t.id
        WHERE t.group_id IN ($placeholders)
        ORDER BY t.created_at DESC
    ");
    $stmt2->execute($groupIds);
    $tasks = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Progress across those groups
    $stmt3 = $pdo->prepare("
        SELECT COUNT(t.id) as total, 
               COALESCE(SUM(CASE WHEN d._status = 4 THEN 1 ELSE 0 END), 0) as completed
        FROM tasks t
        LEFT JOIN task_deadline d ON d.task_id = t.id
        WHERE t.group_id IN ($placeholders)
    ");
    $stmt3->execute($groupIds);
    $progress = $stmt3->fetch(PDO::FETCH_ASSOC);
} else {
    $progress = ['total' => 0, 'completed' => 0];
}

// Fetch progress stats
$stmt = $pdo->query("
    SELECT 
        COUNT(td.id) AS total,
        SUM(CASE WHEN td._status = 4 THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN td._status = 3 THEN 1 ELSE 0 END) AS `delayed`,
        SUM(CASE WHEN td._status = 2 THEN 1 ELSE 0 END) AS `ongoing`,
        SUM(CASE WHEN td._status = 1 THEN 1 ELSE 0 END) AS `pending`
    FROM tasks t
    LEFT JOIN task_deadline td ON t.id = td.task_id
");
$progress = $stmt->fetch(PDO::FETCH_ASSOC);


// avoid divide by zero
$total     = (int)$progress['total'];
$completed = (int)$progress['completed'];
$delayed   = (int)$progress['delayed'];
$ongoing   = (int)$progress['ongoing'];
$pending   = (int)$progress['pending'];


$percent = $total > 0 ? round(($completed / $total) * 100) : 0;





$user_id = $_SESSION['user_id'];

// Fetch all groups the user is in
$stmt = $pdo->prepare("
    SELECT g.id, g.name, g.code, g.owner_user_id, gm.role,
           COUNT(t.id) AS total_tasks,
           SUM(CASE WHEN d._status = 3 THEN 1 ELSE 0 END) AS completed_tasks
    FROM task_groups g
    JOIN group_members gm ON gm.group_id = g.id
    LEFT JOIN tasks t ON g.id = t.group_id
    LEFT JOIN task_deadline d ON d.task_id = t.id
    WHERE gm.user_id = ?
    GROUP BY g.id, g.name, g.code, g.owner_user_id, gm.role
");
$stmt->execute([$user_id]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate into owned groups
$ownedGroups = array_filter($groups, fn($g) => (int)$g['owner_user_id'] === $user_id);
$joinedGroups = array_filter($groups, fn($g) => (int)$g['owner_user_id'] !== $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dashboard</title>
  <link rel="stylesheet" href="dashboardstyle.css">
  <style>
.add-project-card {
  background-color: #fff;
  text-align: center;
  padding: 40px 0;
  cursor: pointer;
  transition: transform 0.2s ease, background 0.3s ease;
  width: 280px;
  min-width: 280px;
  max-width: 280px;   /* ← FIXES WIDTH PERMANENTLY */
  height: 190px; 
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
}

.add-project-card:hover {
  background-color: #d7bdfc;
  transform: scale(1.05);
}

.add-project-card .plus {
  font-size: 32px;
  display: block;
  margin-top: 10px;
}


/* Popup Modal Base */
.modal {
  display: none;
  position: fixed;
  z-index: 999;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.5);
  justify-content: center;
  align-items: center;
  backdrop-filter: blur(4px);
}

/* Popup Box */
.modal-content {
  background: #d9b4e7ff;
  padding: 20px;
  width: 400px;
  box-shadow: 0 5px 15px rgba(0,0,0,0.2);
  animation: fadeIn 0.3s ease;
}

/* Header */
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #f0d9ff;
  padding: 10px;
}

.close-btn {
  font-size: 24px;
  font-weight: bold;
  color: red;
  cursor: pointer;
}

@keyframes fadeIn {
  from { opacity: 0; transform: scale(0.9); }
  to { opacity: 1; transform: scale(1); }
}


/* Responsive fallback */
@media (max-width: 1000px) {
  .group-card.card {
    flex: 0 0 calc(50% - 20px);
    max-width: calc(50% - 20px);
    box-shadow: 4px #000;
  }
}

@media (max-width: 600px) {
  .group-card.card {
    flex: 0 0 100%;
    max-width: 100%;
  }
}

.fake-card {
  opacity: 0.6;
  background: #f9f9f9;
}
.fake-card h3,
.fake-card p,
.fake-card .progress-text {
  color: #000000ff;
}
.fake-card .btn {
  background: #ccc;
  cursor: not-allowed;
}

  </style>
</head>
<body>
    <?php
$user_id = (int)$_SESSION['user_id']; // your logged-in user id

$sql = "
SELECT 
    g.id AS group_id,
    g.name AS group_name,
    COUNT(td.id) AS total,
    SUM(CASE WHEN td._status = 5 THEN 1 ELSE 0 END) AS dropped,
    SUM(CASE WHEN td._status = 4 THEN 1 ELSE 0 END) AS `delayed`,
    SUM(CASE WHEN td._status = 3 THEN 1 ELSE 0 END) AS completed,
    SUM(CASE WHEN td._status = 2 THEN 1 ELSE 0 END) AS ongoing,
    SUM(CASE WHEN td._status = 1 THEN 1 ELSE 0 END) AS pending
FROM task_groups g
JOIN group_members gm ON gm.group_id = g.id
LEFT JOIN tasks t ON t.group_id = g.id
LEFT JOIN task_deadline td ON td.task_id = t.id
WHERE gm.user_id = :user_id
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$groupProgress = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($groupProgress as $gp) {
    $total     = (int)$gp['total'];
    $completed = (int)$gp['completed'];
    $delayed   = (int)$gp['delayed'];
    $ongoing   = (int)$gp['ongoing'];
    $pending   = (int)$gp['pending'];
    $dropped   = (int)$gp['dropped'];

    $percent = $total > 0 ? round(($completed / $total) * 100) : 0;
    
}


  ?>
  
<div class="sidebar">
  <h2>TaskSync</h2>
  <ul>
    <li><a href= "dashboard.php">📚Dashboard</a></li>
    <li><a href="calendar.php">📅Calendar</a></li>
    <li><a href = "profile.php">👤Profile</a></li>
    <li><a href= "Projects.php" class="active" >📕Projects</li></a>
    <?php if ($privilege === 3): ?>
       <li><a href="admin_panel.php">🔧Moderator Panel</a></li>
      <?php endif; ?>
    <li><a href="logout.php" class ="logout">➜]Logout</a></li>
    </ul>

</div>

<div class="main">
  <h1>Hello! <?=htmlspecialchars($user_name)?></h1>
  <!-- Group cards -->
<div class="group-list">
  <!-- Popup Modal -->
  <div id="addProjectModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Add Project</h2>
        <span class="close-btn" id="closeModal">&times;</span>
      </div>
      <div class="modal-body">
        <h3>Create Group</h3>
        <form method="post">
          <input name="group_name" placeholder="Input Group Name" required><br><br>
          <button name="create_group" class="btn small">Create Group</button>
        </form>
        <hr>
        <h3>Join with Code</h3>
        <form method="post">
          <input name="join_code" placeholder="Enter code" required><br><br>
          <button name="join_group" class="btn small">Join Group</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ===== Recently Joined Section ===== -->


<!-- Owned Projects Section -->
<div class="owned-section">
  <h2>Your Owned Projects</h2>
  <div class="scroll-wrapper">
    <button class="scroll-btn left owned-left">&lt;</button>
        
    <div class="group-list owned-list">
      <div class="add-project-card" id="openAddProject">
    <p>Add Project</p>
    <span class="plus">+</span>
  </div>
      <?php
      $owned = array_filter($groups, fn($g) => (int)$g['owner_user_id'] === (int)$user_id);
      ?>
      <?php if (empty($owned)): ?>
        <div class="card">No owned projects yet.</div>
      <?php else: foreach ($owned as $g): ?>
        <?php
          $total = (int)$g['total_tasks'];
          $done = (int)$g['completed_tasks'];
          $progress = $total > 0 ? round(($done / $total) * 100) : 0;
        ?>
        <div class="group-card card">
          <h3><?= htmlspecialchars($g['name']) ?></h3>
          <p>Code: <strong><?= htmlspecialchars($g['code']) ?></strong></p>
          <p>Role: Owner</p>
          <div class="group-progress">
            <div class="progress-bar">
              <div class="progress-fill" style="width: <?= $progress ?>%"></div>
            </div>
            <p class="progress-text"><?= $progress ?>% Completed</p>
          </div>
          <a class="btn small" href="taskmanager.php?group_id=<?= (int)$g['id'] ?>">Manage</a>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <button class="scroll-btn right owned-right">&gt;</button>
  </div>
</div>

<!-- Joined Groups Section -->
<div class="owned-section">
  <h2>Joined Groups</h2>
  <div class="scroll-wrapper">
    <button class="scroll-btn left joined-left">&lt;</button>

    <div class="group-list joined-list">
        <!-- Add Project Card -->
      <?php
      $joined = array_filter($groups, fn($g) => (int)$g['owner_user_id'] !== (int)$user_id);
      ?>
      <?php if (empty($joined)): ?>
        <div class="card">No joined groups yet.</div>
      <?php else: foreach ($joined as $g): ?>
        <?php
          $count = isset($groupNotifs[$g['id']]) ? (int)$groupNotifs[$g['id']]['total_unseen'] : 0;
          $total = (int)$g['total_tasks'];
          $done = (int)$g['completed_tasks'];
          $progress = $total > 0 ? round(($done / $total) * 100) : 0;
        ?>
        
        <div class="group-card card">
          
          <h3>
            <?= htmlspecialchars($g['name']) ?>
            <?php if ($count > 0): ?>
              <span class="badge"><?= $count ?></span>
            <?php endif; ?>
          </h3>
          <p>Code: <strong><?= htmlspecialchars($g['code']) ?></strong></p>
          <div class="group-progress">
            <div class="progress-bar">
              <div class="progress-fill" style="width: <?= $progress ?>%"></div>
            </div>
            <p class="progress-text"><?= $progress ?>% Completed</p>
          </div>
          <p>Role: <?= htmlspecialchars(ucfirst($g['role'])) ?></p>
          <a class="btn small" href="taskmanager.php?group_id=<?= (int)$g['id'] ?>">Open Workspace</a>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <button class="scroll-btn right joined-right">&gt;</button>
  </div>
</div>
</body>
<script>

const cardWidth = 285 + 20; // card width + gap

document.querySelectorAll('.scroll-btn.left').forEach(btn => {
  btn.addEventListener('click', () => {
    const list = btn.closest('.scroll-wrapper').querySelector('.group-list');
    list.scrollBy({ left: -cardWidth * 3, behavior: 'smooth' });
  });
});

document.querySelectorAll('.scroll-btn.right').forEach(btn => {
  btn.addEventListener('click', () => {
    const list = btn.closest('.scroll-wrapper').querySelector('.group-list');
    list.scrollBy({ left: cardWidth * 3, behavior: 'smooth' });
  });
});



document.addEventListener("DOMContentLoaded", function() {
  // ===== Add Project Modal =====
  const openBtn = document.getElementById("openAddProject");
  const modal = document.getElementById("addProjectModal");
  const closeBtn = document.getElementById("closeModal");

  if (openBtn && modal && closeBtn) {
    // Open modal
    openBtn.addEventListener("click", function() {
      modal.style.display = "flex";
    });

    // Close modal
    closeBtn.addEventListener("click", function() {
      modal.style.display = "none";
    });

    // Close modal when clicking outside
    window.addEventListener("click", function(e) {
      if (e.target === modal) {
        modal.style.display = "none";
      }
    });
  }

  // ===== Group Dropdown Menus =====
  const menuButtons = document.querySelectorAll(".menu-btn");

  menuButtons.forEach(button => {
    button.addEventListener("click", function(e) {
      e.stopPropagation(); // Prevent click from bubbling
      const groupId = button.getAttribute("data-group-id");
      const menu = document.getElementById("menu-" + groupId);

      // Close other dropdowns
      document.querySelectorAll(".menu-dropdown").forEach(drop => {
        if (drop !== menu) drop.classList.add("hidden");
      });

      // Toggle current one
      menu.classList.toggle("hidden");
    });
  });

  // Close dropdown when clicking outside
  document.addEventListener("click", function() {
    document.querySelectorAll(".menu-dropdown").forEach(menu => {
      menu.classList.add("hidden");
    });
  });
});

document.addEventListener("DOMContentLoaded", () => {
  const colors = ["#a289ecff", "#51cf66", "#ffd43b"]; // violet, green, yellow

  // Assign colors based on position on each page
  document.querySelectorAll(".group-card").forEach((card, index) => {
    const color = colors[index % colors.length];
    card.style.backgroundColor = color;

    // Store color when clicked (for taskmanager.php)
    card.addEventListener("click", () => {
      localStorage.setItem("activeGroupColor", color);
    });
  });
});

document.addEventListener("DOMContentLoaded", () => {
  const colorKeys = ["violet", "green", "yellow"];

  document.querySelectorAll(".group-card").forEach((card, index) => {
    const themeKey = colorKeys[index % colorKeys.length];
    const color = themeSets[themeKey].base;
    card.style.backgroundColor = themeSets[themeKey].light;
    card.style.borderColor = themeSets[themeKey].border;

    card.addEventListener("click", () => {
      localStorage.setItem("activeTheme", themeKey);
    });
  });
});

function scrollGroups(type, direction) {
  const container = document.getElementById(type === 'owned' ? 'ownedGroups' : 'joinedGroups');
  if (container) {
    container.scrollBy({
      left: direction * 300,
      behavior: 'smooth'
    });
  }
}
</script>
</html>
