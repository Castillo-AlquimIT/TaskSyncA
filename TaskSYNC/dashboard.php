<?php
// dashboard.php
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Unknown';
$privilege = (int)($_SESSION['privilege_mode'] ?? 0); // 0=member, 1=owner, 2=teacher, 3=admin



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
    3 => 'admin'
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
        $stmt = $pdo->prepare('SELECT id FROM task_groups WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $g = $stmt->fetch();
        if (!$g) {
            $join_err = 'Invalid code.';
        } else {
            $gid = (int)$g['id'];
            // insert member if not exists
            $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
            $stmt->execute([$gid, $user_id]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
                $stmt->execute([$gid, $user_id, $role]); // use mapped role
            }
            header("Location: taskmanager.php?group_id={$gid}");
            exit;
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
    header("Location: dashboard.php");
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
    3 => 'Admin'
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
        limit 5
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

$user_id = (int)$_SESSION['user_id'];

$sql = "
SELECT 
  SUM(CASE WHEN type = 'task' AND seen = 0 THEN 1 ELSE 0 END) AS new_tasks,
  SUM(CASE WHEN type = 'chat' AND seen = 0 THEN 1 ELSE 0 END) AS new_chats,
  SUM(CASE WHEN type = 'join' AND seen = 0 THEN 1 ELSE 0 END) AS new_joins
FROM notifications
WHERE user_id = :user_id
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$notif = $stmt->fetch(PDO::FETCH_ASSOC);

$totalNotif = $notif['new_tasks'] + $notif['new_chats'] + $notif['new_joins'];


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dashboard</title>
  <link rel="stylesheet" href="dashboardstyle.css">
  <style>
    .card {
      border: 2px solid #5a189a;
    }
  </style>
</head>
<body>
  
    <?php
$user_id = (int)$_SESSION['user_id']; // your logged-in user id

$sql = "
SELECT 
    COUNT(td.id) AS total,
    SUM(CASE WHEN td._status = 5 THEN 1 ELSE 0 END) AS dropped,
    SUM(CASE WHEN td._status = 4 THEN 1 ELSE 0 END) AS 'delayed',
    SUM(CASE WHEN td._status = 3 THEN 1 ELSE 0 END) AS completed,
    SUM(CASE WHEN td._status = 2 THEN 1 ELSE 0 END) AS ongoing,
    SUM(CASE WHEN td._status = 1 THEN 1 ELSE 0 END) AS pending
FROM task_groups g
JOIN group_members gm ON gm.group_id = g.id
LEFT JOIN tasks t ON t.group_id = g.id
LEFT JOIN task_deadline td ON td.task_id = t.id
WHERE gm.user_id = :user_id
";

$stmt2 = $pdo->prepare($sql);
$stmt2->execute(['user_id' => $user_id]);
$stats = $stmt2->fetch(PDO::FETCH_ASSOC);

$total = (int)$stats['total'];
$completed = (int)$stats['completed'];
$delayed = (int)$stats['delayed'];
$ongoing = (int)$stats['ongoing'];
$pending = (int)$stats['pending'];
$dropped = (int)$stats['dropped'];
$percent = $total > 0 ? round(($completed / $total) * 100) : 0;

  ?>
  
<div class="sidebar">
  <h2>TaskSync</h2>
  <ul>
    <li><a href="dashboard.php" class="active">📚Dashboard</a></li>
    <li><a href="calendar.php">📅Calendar</a></li>
    <li><a href = "profile.php">👤Profile</a></li>
    <li> <a href= "Projects.php">📘Projects</a></li>
    <li><a href="logout.php" class ="logout">➜]Logout</a></li>
    <?php if ($privilege === 3): ?>
       <li><a href="admin_panel.php">🔧Admin Panel</a></li>
      <?php endif; ?>
    </ul>
</div>
<?php
$user_id = (int)$_SESSION['user_id'];

$sql = "
SELECT group_id,
       SUM(CASE WHEN type = 'task' AND seen = 0 THEN 1 ELSE 0 END) AS new_tasks,
       SUM(CASE WHEN type = 'chat' AND seen = 0 THEN 1 ELSE 0 END) AS new_chats,
       SUM(CASE WHEN type = 'join' AND seen = 0 THEN 1 ELSE 0 END) AS new_joins,
       COUNT(*) - SUM(seen) AS total_unseen
FROM notifications
WHERE user_id = :user_id
GROUP BY group_id
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$groupNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
  ?>
<div class="main">
    <h1>Dashboard</h1>
<div class="overall-progress">
  <h3>Overall Progress</h3>

  <div class="gauge" style="--percent: <?= $percent ?>;">
    <div class="gauge-arc"></div>
    <div class="needle"></div>
    <div class="center-text">
      <span><?= $percent ?>%</span><br>
      <small><?= $percent ?>% Completed</small>
    </div>
  </div>

  <div class="stats">
    <span><strong><?= $total ?></strong> Total</span>
    <span style="color:#047857;"><strong><?= $completed ?></strong> Completed</span>
    <span style="color:#ca8a04;"><strong><?= $delayed ?></strong> Delayed</span>
    <span style="color:#1d4ed8;"><strong><?= $ongoing ?></strong> Ongoing</span>
    <span style="color:#6b7280;"><strong><?= $pending ?></strong> Pending</span>
  </div>
</div>

<button class="scroll-btn left" style="display:none"></button>
<button class="scroll-btn right" style="display:none"></button>


<div class="tasks">
  <div class="task-header">
    <h3>Today Tasks</h3>
    <div class="task-tabs task-class">
      <button class="filter-btn active" data-filter="all">All</button>
      <button class="filter-btn" data-filter="important">Important</button>
      <button class="filter-btn" data-filter="completed">Completed</button>
    </div>
  </div>

  <?php foreach ($tasks as $t): ?>
    <?php
      $statusClass = "ongoing";
      $statusText = "Ongoing";
      if ($t['_status'] == 3) { $statusClass = "completed"; $statusText = "Completed"; }
      elseif ($t['_status'] == 4) { $statusClass = "delayed"; $statusText = "Delayed"; }
      elseif ($t['_status'] == 2) { $statusClass = "ongoing"; $statusText = "Ongoing"; }
      elseif ($t['_status'] == 1) { $statusClass = "pending"; $statusText = "Pending"; }
      elseif ($t['_status'] == 5) { $statusClass = "dropped"; $statusText = "Dropped"; }
    ?>
    
    <div class="task-item <?= $statusClass ?>">
      <div class="task-dot <?= $statusClass ?>"></div>
      <span class="task-title"><?= htmlspecialchars($t['title']) ?></span>
      <span class="status <?= $statusClass ?>"><?= $statusText ?></span>
    </div>
  <?php endforeach; ?>
</div>
</body>
<script>

const groupList = document.querySelector('.group-list');
const cardWidth = 280 + 20; // card width + gap

document.querySelector('.scroll-btn.left').addEventListener('click', () => {
  groupList.scrollBy({ left: -cardWidth * 3, behavior: 'smooth' });
});

document.querySelector('.scroll-btn.right').addEventListener('click', () => {
  groupList.scrollBy({ left: cardWidth * 3, behavior: 'smooth' });
});

document.querySelectorAll('.filter-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const filter = btn.getAttribute('data-filter');
    const tasks = document.querySelectorAll('.task-item');

    tasks.forEach(task => {
      const isOngoing = task.classList.contains('ongoing');
      const isDelayed = task.classList.contains('delayed');
      const isCompleted = task.classList.contains('completed');

      if (filter === 'all') {
        task.style.display = '';
      } 
      else if (filter === 'important') {
        // show only ongoing or delayed
        task.style.display = (isOngoing || isDelayed) ? '' : 'none';
      } 
      else if (filter === 'completed') {
        // optionally handle 'groups' filter if you want
        task.style.display = (isCompleted) ? '' : 'none';
      }
    });
  });
});


document.addEventListener('DOMContentLoaded', () => {
  // Use 6-digit hex or rgba — more compatible than 8-digit hex
  const colors = ['#a289ec', '#51cf66', '#ffd43b']; // violet, green, yellow

  // The container / selector for your project cards
  const cards = Array.from(document.querySelectorAll('.group-card'));
  if (!cards.length) {
    console.warn('No .group-card elements found — check selector or render timing.');
    return;
  }

  // Try to re-use existing mapping (projectId -> color) saved in localStorage
  const mappingKey = 'projectColorMap_v1';
  const stored = JSON.parse(localStorage.getItem(mappingKey) || '{}');

  // Build a deterministic mapping by index for new projectIds
  cards.forEach((card, index) => {
    const pid = card.dataset.projectId || ('auto-' + index);
    // prefer stored color if exists
    let color = stored[pid];
    if (!color) {
      // assign by index if not present
      color = colors[index % colors.length];
      stored[pid] = color;
    }
    // persist mapping
    localStorage.setItem(mappingKey, JSON.stringify(stored));

    // apply color with important to overcome strong CSS rules
    card.style.setProperty('background-color', color, 'important');
    // optional: set a CSS variable on the card for children to read
    card.style.setProperty('--card-theme-color', color);
  });


  (function assignNewProjectColor(newProjectId){
  const colors = ['#a289ec', '#51cf66', '#ffd43b'];
  const mappingKey = 'projectColorMap_v1';
  const idxKey = 'nextProjectColorIndex_v1';
  const map = JSON.parse(localStorage.getItem(mappingKey) || '{}');
  let idx = parseInt(localStorage.getItem(idxKey) || '0', 10);
  map[newProjectId] = colors[idx % colors.length];
  localStorage.setItem(mappingKey, JSON.stringify(map));
  localStorage.setItem(idxKey, ((idx + 1) % colors.length).toString());
})(NEW_PROJECT_ID);


  // If new cards are added dynamically, re-run assignment
  const container = document.querySelector('.group-list') || document.body;
  const observer = new MutationObserver(mutations => {
    // small debounce
    clearTimeout(window.__colorAssignTimeout);
    window.__colorAssignTimeout = setTimeout(() => {
      Array.from(document.querySelectorAll('.group-card')).forEach((card, index) => {
        const pid = card.dataset.projectId || ('auto-' + index);
        const stored = JSON.parse(localStorage.getItem(mappingKey) || '{}');
        const color = stored[pid] || colors[index % colors.length];
        card.style.setProperty('background-color', color, 'important');
        card.style.setProperty('--card-theme-color', color);
      });
      // persist any new mappings again
      localStorage.setItem(mappingKey, JSON.stringify(JSON.parse(localStorage.getItem(mappingKey) || '{}')));
    }, 50);
  });
  observer.observe(container, { childList: true, subtree: true });
});

document.addEventListener("DOMContentLoaded", () => {
  const colors = ["#a289ecff", "#51cf66", "#ffd43b"]; // violet, green, yellow

  function updateDynamicColors() {
    const cards = document.querySelectorAll(".group-card, .task-item");
    cards.forEach((card, index) => {
      card.style.backgroundColor = colors[index % colors.length];
    });
  }

  // Initial color assignment
  updateDynamicColors();

  // Observe changes in both group list and task section
  const groupList = document.querySelector(".group-list");
  const taskList  = document.querySelector(".tasks");

  const observer = new MutationObserver(updateDynamicColors);

  if (groupList) observer.observe(groupList, { childList: true, subtree: true });
  if (taskList)  observer.observe(taskList,  { childList: true, subtree: true });
});



async function updateGroupBadges() {
  try {
    // Call a PHP endpoint that returns JSON like:
    // { "1": 2, "3": 5, "7": 0 }
    const res = await fetch('get_group_notifications.php');
    const data = await res.json();

    // Loop through each group card and update badge
    document.querySelectorAll('.group-card').forEach(card => {
      const groupId = card.getAttribute('data-group-id'); // add this attribute in PHP
      const badge = card.querySelector('.badge');

      const count = data[groupId] || 0;
      if (count > 0) {
        if (!badge) {
          const span = document.createElement('span');
          span.className = 'badge';
          span.textContent = count;
          card.appendChild(span);
        } else {
          badge.textContent = count;
          badge.style.display = 'inline-block';
        }
      } else if (badge) {
        badge.style.display = 'none';
      }
    });
  } catch (err) {
    console.error('Failed to update badges', err);
  }
}

// Run once on load
updateGroupBadges();

// Optionally refresh every 30s
setInterval(updateGroupBadges, 30000);
</script>
