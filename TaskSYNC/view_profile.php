<?php
require_once __DIR__ . '/db.php';


$viewed_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($viewed_id <= 0) {
  echo "<h2>❌ Invalid profile ID.</h2>";
  exit;
}

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}


$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Unknown';
$privilege = (int)($_SESSION['privilege_mode'] ?? 0); // 0=member, 1=owner, 2=teacher, 3=admin

// Fetch viewed user's info
$stmt = $pdo->prepare("
  SELECT name, email, created_at, banner_color, banner_image, profile_image, privilege_mode
  FROM users WHERE id = ?
");
$stmt->execute([$viewed_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  echo "<h2>❌ Profile not found.</h2>";
  exit;
}
$view_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no ID in URL, show your own profile
if ($view_id <= 0) {
    $view_id = $_SESSION['user_id'];
}

$user_id = $view_id;

$bannerColor  = htmlspecialchars($user['banner_color'] ?? '#FCEADC');
$bannerImage  = htmlspecialchars($user['banner_image'] ?? '');
$profileImage = htmlspecialchars($user['profile_image'] ?? 'default-avatar.png');
$roleMap = [0 => 'Member', 1 => 'Owner', 2 => 'Teacher', 3 => 'Admin'];
$role = $roleMap[$user['privilege_mode']] ?? 'Unknown';

// Fetch groups & progress for the viewed user
$stmt = $pdo->prepare("
  SELECT 
    g.id, g.name, gm.role,
    COUNT(t.id) AS total_tasks,
    SUM(CASE WHEN d._status = 3 THEN 1 ELSE 0 END) AS completed_tasks
  FROM task_groups g
  JOIN group_members gm ON gm.group_id = g.id
  LEFT JOIN tasks t ON g.id = t.group_id
  LEFT JOIN task_deadline d ON t.id = d.task_id
  WHERE gm.user_id = ?
  GROUP BY g.id
");
$stmt->execute([$viewed_id]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Collect all group IDs
$groupIds = array_column($groups, 'id');

// Fetch all tasks of the viewed user
$tasks = [];
if (!empty($groupIds)) {
  $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
  $stmt2 = $pdo->prepare("
    SELECT 
        t.id, t.title, t.group_id, ta.user_id AS assigned_user_id, d._status
    FROM tasks t
    LEFT JOIN task_assignments ta ON ta.task_id = t.id
    LEFT JOIN task_deadline d ON d.task_id = t.id
    WHERE t.group_id IN ($placeholders) AND ta.user_id = ?
    ORDER BY t.created_at DESC
  ");
  $stmt2->execute([...$groupIds, $viewed_id]);
  $tasks = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}



?>

<!DOCTYPE html>
<html>
<head>
  <title>Public Profile</title>
  <link rel="stylesheet" href="dashboardstyle.css">
  <style>
/* --- Global & Font Setup --- */
body {
  font-family: Arial, sans-serif;
}

.main {
  flex: 1;
  padding: 25px 20px;
}

/* This is the main container for the whole component */
.user-profile-card {
  width: 100%;
  max-width: 1200px;   /* more reasonable desktop max */
  margin: 0 auto;
  border: 1px solid #ddd;
  background-color: #fff;
  box-sizing: border-box; /* include padding/border in width */
}


@media (max-width: 1024px) {
  .user-profile-card {
    max-width: 95%;   /* shrink on tablets */
  }
  .tab-panel {
    flex-direction: column; /* stack cards vertically */
  }
}

@media (max-width: 600px) {
  .profile-header {
    flex-direction: column;
    align-items: flex-start;
  }
  .profile-picture {
    margin-top: -40px;
    width: 80px;
    height: 80px;
  }
  .profile-details h2 {
    font-size: 18px;
  }
  .tab-panel {
    padding: 20px;
  }
}


/* --- 1. Profile Banner Styling --- */
.profile-banner {
  height: 120px; /* Set a fixed height */
  background-color: #FCEADC; /* The light orange from your image */
  border-radius: 10px;
  margin-bottom: 20px;
  transition: background-color 0.3s ease;
}


/* --- 2. Profile Info Header --- */
.profile-header {
  display: flex; /* Use Flexbox for alignment */
  align-items: flex-end; /* Align items to the bottom */
  padding: 0 30px 20px 30px; /* Add spacing */
  position: relative; /* Needed for the overlapping avatar */
  
  /* This is the thin black line from your image */
  border-bottom: 2px solid #333; 
}

.profile-picture {
  width: 120px;
  height: 120px;
  background-color: #A0C3FF; /* Placeholder color */
  border-radius: 50%; /* Makes it a perfect circle */
  border: 4px solid #fff; /* White rim */
  
  margin-top: -60px; 
  
  position: relative; 
  z-index: 2;
  
  background-size: cover;
}

.profile-details {
  margin-left: 20px;
}

.profile-details h2 {
  margin: 0 0 5px 0;
  font-size: 24px;
}

.profile-details p {
  margin: 0;
  color: #555;
}

.profile-details span {
  font-size: 0.9em;
  color: #888;
}

.profile-actions {
  margin-left: auto; /* Pushes this to the far right */
}

.profile-actions a {
  color: #D9534F; /* Reddish color */
  text-decoration: none;
  font-size: 0.9em;
}

/* --- 3. Tabbed Content Styling --- */
.profile-content {
  padding: 0 30px 30px 30px; /* Add padding to match the header */
  width: 100%;
  box-sizing: border-box; /* Ensures padding doesn't break layout */
}
.profile-container {
  position: relative;
  display: inline-block;
  width: 150px;
  height: 150px;
  border-radius: 50%;
  overflow: hidden;
  cursor: pointer;
}

.profile-container img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: 0.3s ease;
}

/* Hover overlay */
.profile-container::before {
  content: "Change";
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: rgba(0,0,0,0.5);
  color: white;
  text-align: center;
  font-size: 14px;
  padding: 5px 0;
  opacity: 0;
  transition: 0.3s ease;
}

/* Hover effects */
.profile-container:hover img {
  filter: brightness(70%);
}
.profile-container:hover::before {
  opacity: 1;
}

/* Hide the input */
#profileUpload {
  display: none;
}

.tab-navigation {
  list-style-type: none;
  padding-left: 20px;
  margin: 0;
  
  /* === THE TAB TRICK === */
  /* Pulls the content box UP to overlap */
  margin-bottom: -2px; 
  
  position: relative;
  z-index: 1; /* Sits on top of the content box border */
  
  /* Push tab down from the header's border */
  margin-top: 20px; 
}

.tab-item.active {
  display: inline-block;
  padding: 10px 20px;
  font-weight: bold;
  background-color: #F9A88C;
  border: 2px solid #333;
  
  /* The key: no bottom border */
  border-bottom: none; 
  border-radius: 6px 6px 0 0;
}

.tab-panel {
  border: 2px solid #333; /* The main black border */
  padding: 30px 20px 20px 20px; /* Extra padding at the top */
  min-height: 200px;
  display: flex;
  gap: 20px;
  /* We remove the rounded top corners from the box itself */
  border-radius: 0 6px 6px 6px; 
}

/* --- Project Card Styling (Inside) --- */
.project-card {
  background-color: #FDFBFB;
  border: 1px solid #E0D8D7;
  border-radius: 8px;
  padding: 15px;
  width: 100px;
  font-size: 0.9em;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.project-card strong {
  display: block;
  margin-bottom: 5px;
}

.project-card p {
  font-size: 0.9em;
  color: #555;
  margin: 10px 0 0 0;
}

.status-ongoing { color: #D9822B; }
.status-completed { color: #3A9E3E; }

.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0; top: 0;
  width: 100%; height: 100%;
  background: rgba(0,0,0,0.5);
  justify-content: center; align-items: center;
}
.modal-content {
  background: white; padding: 20px;
  border-radius: 10px; width: 300px;
}

.scroll-wrapper {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: calc(2 * 380px + 2 * 10px); /* 3 cards + gaps */
  margin: 0 auto;
}

.group-list {
  display: flex;
  flex-wrap: nowrap;
  overflow-x: hidden;
  gap: 20px;
  scroll-behavior: smooth;
}

.group-list .group-card {
  flex: 0 0 10px;
  border: 2px solid #000;
  padding: 15px;
}

.group-card {
  flex: 0 0 210px;
  border: 2px solid #000;
  padding: 15px;
  display: flex;
  flex-direction: column;
}

/* make the task list scrollable inside the card */
.group-card .task-list {
  max-height: 150px;   /* adjust to show ~2 tasks */
  overflow-y: auto;
  margin-top: 10px;
  padding-right: 5px;  /* space for scrollbar */
}

.scroll-btn:hover {
  background: #555;
}

.scroll-btn.left {
  margin-right: 40px; /* push away from the cards */
}

.scroll-btn.right {
  margin-left: 40px; /* push away from the cards */
}
  </style>
</head>
<body>
<?php
// Use the viewed user's ID for stats, not the logged-in user's
$user_id = $viewed_id;

// Fetch overall task stats
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
LEFT JOIN (
    SELECT * FROM tasks
    ORDER BY created_at DESC
) AS t ON t.group_id = g.id
LEFT JOIN task_assignments ta ON ta.task_id = t.id
LEFT JOIN task_deadline td ON td.task_id = t.id
WHERE gm.user_id = :gm_user_id
  AND ta.user_id = :ta_user_id;
";

$stmt2 = $pdo->prepare($sql);
$stmt2->execute([
  'gm_user_id' => $user_id,
  'ta_user_id' => $user_id
]);

$stats = $stmt2->fetch(PDO::FETCH_ASSOC);

$total = (int)$stats['total'];
$completed = (int)$stats['completed'];
$delayed = (int)$stats['delayed'];
$ongoing = (int)$stats['ongoing'];
$pending = (int)$stats['pending'];
$dropped = (int)$stats['dropped'];
$percent = $total > 0 ? round(($completed / $total) * 100) : 0;

// Fetch group/task history
$stmt = $pdo->prepare("
  SELECT g.id, g.name, gm.role,
         COUNT(t.id) AS total_tasks,
         SUM(CASE WHEN d._status = 3 THEN 1 ELSE 0 END) AS completed_tasks
  FROM task_groups g
  JOIN group_members gm ON gm.group_id = g.id
  LEFT JOIN tasks t ON g.id = t.group_id
  LEFT JOIN task_deadline d ON t.id = d.task_id
  WHERE gm.user_id = ?
  GROUP BY g.id
");
$stmt->execute([$user_id]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user tasks (if needed for details)
if (!empty($groupIds)) {
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt2 = $pdo->prepare("
        SELECT 
            t.id, t.title, t.group_id, ta.user_id AS assigned_user_id, d._status
        FROM tasks t
        LEFT JOIN task_assignments ta ON ta.task_id = t.id
        LEFT JOIN task_deadline d ON d.task_id = t.id
        WHERE t.group_id IN ($placeholders)
        ORDER BY t.created_at DESC
    ");
    $stmt2->execute($groupIds);
    $tasks = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="sidebar">
  <h2>TaskSync</h2>
  <ul>
    <li><a href="dashboard.php">📚Dashboard</a></li>
    <li><a href="calendar.php">📅Calendar</a></li>
    <li><a href="profile.php" class="active">👤Profile</a></li>
    <li><a href="Projects.php">📘Projects</a></li>
    <li><a href="logout.php">➜ Logout</a></li>
    <?php if ($privilege === 3): ?>
       <li><a href="admin_panel.php">🔧Moderator Panel</a></li>
    <?php endif; ?>
  </ul>
</div>

<div class="main">
<div class="user-profile-card">

<?php
$bannerColor  = htmlspecialchars($user['banner_color'] ?? '#FCEADC', ENT_QUOTES);
$bannerImage  = !empty($user['banner_image']) ? htmlspecialchars($user['banner_image'], ENT_QUOTES) : '';
$bannerStyle  = "background-color: {$bannerColor};";
if ($bannerImage) {
    $bannerStyle .= " background-image: url('{$bannerImage}'); background-size: cover; background-position: center;";
}
?>

<!-- Banner (no edit button) -->
<div class="profile-banner" id="profileBanner" style="<?= $bannerStyle ?>"></div>

<!-- Profile Header -->
<div class="profile-header">
  <div class="profile-container">
    <img src="<?= htmlspecialchars($user['profile_image'] ?? 'default-avatar.gif', ENT_QUOTES) ?>" alt="Profile Picture">
  </div>

  <div class="profile-details">
    <h2><?= htmlspecialchars($user['name'] ?? 'Guest') ?></h2>
    <p><?= htmlspecialchars($user['email'] ?? '') ?></p>
    <span>Joined: <?= htmlspecialchars($user['created_at'] ?? 'N/A') ?></span>
  </div>
</div>

<!-- Profile Content -->
<div class="profile-content">
  <ul class="tab-navigation">
    <li class="tab-item active">History</li>
  </ul>

  <div class="tab-panel">
    <div class="scroll-wrapper">
      <button class="scroll-btn left">&lt;</button>

      <div class="group-list">
        <?php foreach ($groups as $g): ?>
          <div class="group-card card">
            <h3><?= htmlspecialchars($g['name']) ?></h3>
            <p>Role: <?= htmlspecialchars($g['role']) ?></p>
            <p>Progress: <?= (int)$g['completed_tasks'] ?>/<?= (int)$g['total_tasks'] ?> tasks completed</p>

            <?php 
              $userTasks = array_filter($tasks ?? [], function($t) use ($g, $user_id) {
                return $t['group_id'] == $g['id'] && $t['assigned_user_id'] == $user_id;
              });
            ?>

            <?php if (!empty($userTasks)): ?>
              <div class="task-list">
                <?php foreach ($userTasks as $t): ?>
                  <?php
                    $statusClass = "ongoing"; $statusText = "Ongoing";
                    if ($t['_status'] == 3) { $statusClass = "completed"; $statusText = "Completed"; }
                    elseif ($t['_status'] == 4) { $statusClass = "delayed"; $statusText = "Delayed"; }
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
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <button class="scroll-btn right">&gt;</button>
    </div>
  </div>
</div>

</div>
</div>

<script>
const colors = ["#a289ecff", "#51cf66", "#ffd43b"]; 
function updateGroupCardColors() {
  const cards = document.querySelectorAll(".group-card");
  cards.forEach((card, index) => {
    card.style.backgroundColor = colors[index % colors.length];
  });
}
updateGroupCardColors();

const historyList = document.querySelector('.tab-panel .group-list');
const cardWidth = 185 + 20; 

document.querySelector('.tab-panel .scroll-btn.left').addEventListener('click', () => {
  historyList.scrollBy({ left: -cardWidth * 3, behavior: 'smooth' });
});
document.querySelector('.tab-panel .scroll-btn.right').addEventListener('click', () => {
  historyList.scrollBy({ left: cardWidth * 3, behavior: 'smooth' });
});
</script>
</body>

</html>
