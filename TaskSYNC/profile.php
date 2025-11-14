<?php
// profile.php
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Unknown';
$privilege = (int)($_SESSION['privilege_mode'] ?? 0); // 0=member, 1=owner, 2=teacher, 3=mod

// ✅ Fetch user profile data FIRST — before using $user
$stmt = $pdo->prepare("
    SELECT 
        id, name, email, privilege_mode, banned,
        banner_color, banner_image, profile_image, created_at
    FROM users 
    WHERE id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Safety: stop if no user found
if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// ✅ Prepare display values
$bannerColor  = htmlspecialchars($user['banner_color'] ?? '#FCEADC', ENT_QUOTES);
$bannerImage  = htmlspecialchars($user['banner_image'] ?? '', ENT_QUOTES);
$profileImage = htmlspecialchars($user['profile_image'] ?? '', ENT_QUOTES);

$bannerStyle = "background-color: {$bannerColor};";
if (!empty($bannerImage)) {
    $bannerStyle .= " background-image: url('{$bannerImage}'); background-size: cover; background-position: center;";
}

// 🚫 If banned, force logout
if ((int)$user['banned'] === 1) {
    session_destroy();
    echo "Your account has been banned. Please contact support.";
    exit;
}

/**
 * --- Profile Image Upload Handling ---
 */


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_image'])) {
    $file = $_FILES['profile_image'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif'];

        if (in_array($ext, $allowed)) {
            $newName = 'profile_' . $_SESSION['user_id'] . '.' . $ext;
            $uploadDir = __DIR__ . '/uploads/profile/';
            $uploadPath = $uploadDir . $newName;

            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $dbPath = '/uploads/profile/' . $newName;
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$dbPath, $_SESSION['user_id']]);
                $user['profile_image'] = $dbPath; // Refresh immediately
            } else {
                echo "⚠️ Failed to move uploaded file.";
            }
        } else {
            echo "⚠️ Invalid file type. Allowed types: jpg, jpeg, png, gif.";
        }
    } else {
        echo "⚠️ Upload error code: " . $file['error'];
    }
}

/**
 * --- Banner Image + Color Handling ---
 */
if (isset($_POST['save_banner'])) {
    $banner_color = $_POST['banner_color'] ?? '#FCEADC';
    $banner_image = null;

    if (!empty($_FILES['banner_image']['name']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
        $targetDir = __DIR__ . "/uploads/banner/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

        $fileExt = pathinfo($_FILES["banner_image"]["name"], PATHINFO_EXTENSION);
        $fileName = 'banner_' . $_SESSION['user_id'] . '_' . time() . '.' . $fileExt;
        $targetFile = $targetDir . $fileName;

        if (move_uploaded_file($_FILES["banner_image"]["tmp_name"], $targetFile)) {
            $banner_image = '/uploads/banner/' . $fileName;
        } else {
            echo "⚠️ Failed to move banner image.";
        }
    }

    $sql = "UPDATE users SET banner_color = :color";
    $params = ['color' => $banner_color, 'id' => $_SESSION['user_id']];

    if ($banner_image) {
        $sql .= ", banner_image = :image";
        $params['image'] = $banner_image;
    }

    $sql .= " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute($params);
        $_SESSION['flash'] = "✅ Banner settings updated!";
        header("Location: profile.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['flash'] = "❌ Database error updating banner: " . $e->getMessage();
        header("Location: profile.php");
        exit;
    }
}

/**
 * --- Reset Banner Settings ---
 */
if (isset($_POST['reset_banner'])) {
    $stmt = $pdo->prepare("UPDATE users SET banner_color = '#FCEADC', banner_image = NULL WHERE id = :id");
    try {
        $stmt->execute(['id' => $_SESSION['user_id']]);
        echo "✅ Banner settings reset to default.";
    } catch (PDOException $e) {
        echo "❌ Database error resetting banner: " . $e->getMessage();
    }
}

/**
 * --- Role Mapping ---
 */
$privilegeMap = [
    0 => 'member',
    1 => 'owner',
    2 => 'teacher',
    3 => 'mod'
];
$role = $privilegeMap[$privilege] ?? 'view';
$currentRole = $role;

/**
 * --- Join Group by Code ---
 */
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
            $stmt = $pdo->prepare('SELECT 1 FROM group_blacklist WHERE group_id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$gid, $user_id]);

            if ($stmt->fetch()) {
                $join_err = 'You are blacklisted from this group and cannot join.';
            } else {
                $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
                $stmt->execute([$gid, $user_id]);
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
                    $stmt->execute([$gid, $user_id, $role]);
                }
                header("Location: taskmanager.php?group_id={$gid}");
                exit;
            }
        }
    }
}

/**
 * --- Delete Group ---
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group'])) {
    $gid = (int)$_POST['delete_group'];
    $stmt = $pdo->prepare("SELECT role, group_id FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$gid, $user_id]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($membership) {
        $role = strtolower($membership['role']);
        if ($privilege === 3 || $role === 'owner' || $role === 'teacher') {
            $stmt = $pdo->prepare("DELETE FROM task_groups WHERE id = ?");
            $stmt->execute([$gid]);
        }
    }
    header("Location: Projects.php");
    exit;
}

/**
 * --- Fetch Group & Task Info ---
 */
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
        SUM(CASE WHEN td._status = 3 THEN 1 ELSE 0 END) AS 'delayed',
        SUM(CASE WHEN td._status = 2 THEN 1 ELSE 0 END) AS ongoing,
        SUM(CASE WHEN td._status = 1 THEN 1 ELSE 0 END) AS pending
    FROM tasks t
    LEFT JOIN task_deadline td ON t.id = td.task_id
");
$progress = $stmt->fetch(PDO::FETCH_ASSOC);

$total     = (int)$progress['total'];
$completed = (int)$progress['completed'];
$delayed   = (int)$progress['delayed'];
$ongoing   = (int)$progress['ongoing'];
$pending   = (int)$progress['pending'];
$percent   = $total > 0 ? round(($completed / $total) * 100) : 0;


?>

  
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dashboard</title>
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
$user_id = (int)$_SESSION['user_id'];

$sql = "
SELECT 
    COUNT(td.id) AS total,
    SUM(CASE WHEN td._status = 5 THEN 1 ELSE 0 END) AS dropped,
    SUM(CASE WHEN td._status = 4 THEN 1 ELSE 0 END) AS 'delayed',
    SUM(CASE WHEN td._status = 3 THEN 1 ELSE 0 END) AS completed,
    SUM(CASE WHEN td._status = 2 THEN 1 ELSE 0 END) AS ongoing,
    SUM(CASE WHEN td._status = 1 THEN 1 ELSE 0 END) AS 'Not Started'
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
$pending = (int)$stats['Not Started'];
$dropped = (int)$stats['dropped'];
$percent = $total > 0 ? round(($completed / $total) * 100) : 0;


$tasks = [];
if (!empty($groupIds)) {
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt2 = $pdo->prepare("
        SELECT 
            t.id, 
            t.title, 
            t.group_id, 
            ta.user_id AS assigned_user_id, 
            d._status
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
    <li><a href = "profile.php" class="active" >👤Profile</a></li>
    <li> <a href= "Projects.php">📘Projects</a></li>
    <?php if ($privilege === 3): ?>
       <li><a href="admin_panel.php">🔧Moderator Panel</a></li>
      <?php endif; ?>
    <li><a href="logout.php" class ="logout">➜]Logout</a></li>
    </ul>
</div>
<div class="main">
<div class="user-profile-card">

<?php
// PHP
$bannerImage = !empty($user['banner_image']) ? 'uploads/' . htmlspecialchars($user['banner_image'], ENT_QUOTES) : '';
// 🛑 FIX: Use the path directly from the database without prepending 'uploads/'
$bannerImage = !empty($user['banner_image']) ? htmlspecialchars($user['banner_image'], ENT_QUOTES) : ''; 

// Build style safely
$bannerStyle = "background-color: {$bannerColor};";
if ($bannerImage) {
    // Check if the path includes a leading slash if necessary, otherwise use it as is.
    $bannerStyle .= " background-image: url('{$bannerImage}'); background-size: cover; background-position: center;";
}
?>

<div class="profile-banner" id="profileBanner" style="<?= $bannerStyle ?>">
    <button class="edit-banner-btn" id="openBannerModal">Edit Banner</button>
</div>
<!-- 🪄 Modal -->
<div class="banner-modal" id="bannerModal">
  <div class="banner-modal-content">
    <span class="close-modal" id="closeBannerModal">&times;</span>
    <h3>Customize Profile Banner</h3>

    <form method="POST" enctype="multipart/form-data">
      <label>Pick a Banner Color:</label>
      <input type="color" name="banner_color" value="<?= htmlspecialchars($user['banner_color'] ?? '#FCEADC') ?>"><br><br>

      <label>Or Upload a Banner Image:</label>
      <input type="file" name="banner_image" accept="image/*"><br><br>

      <button type="submit" name="save_banner" class="save-banner-btn">Save</button>
      <button type="submit" name="reset_banner" class="reset-banner-btn">Reset to Default</button>
    </form>
  </div>
</div>

  <div class="profile-header">
    
<div class="profile-container" onclick="document.getElementById('profileUpload').click()">
  <img 
    src="<?= htmlspecialchars($user['profile_image'] ?? 'default-avatar.gif', ENT_QUOTES) ?>" 
    alt="Profile Picture">
</div>

<form action="profile.php" method="post" enctype="multipart/form-data" id="profileForm">
  <input type="file" name="profile_image" id="profileUpload" accept="image/*" onchange="document.getElementById('profileForm').submit()">
</form>

<div class="profile-details">
  <h2><?= htmlspecialchars($user['name'] ?? 'Guest') ?></h2>
  <p><?= htmlspecialchars($user['email'] ?? '') ?></p>
  <span><?= htmlspecialchars($user['created_at'] ?? 'No date') ?></span>
</div>
<a href="change_password.php"><small>Change Password</small></a><br><br>
  </div>

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
            <?php 
              $userTasks = array_filter($tasks, function($t) use ($g, $user_id) {
                return $t['group_id'] == $g['id'] && $t['assigned_user_id'] == $user_id;
              });
            ?>

            <?php if (empty($userTasks)): ?>
              <p style="color:#777; font-size:14px;">No tasks assigned to you in this group.</p>
            <?php else: ?>
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
</body>
<script>

document.getElementById("openBannerModal").addEventListener("click", () => {
  document.getElementById("bannerModal").style.display = "block";
});

document.getElementById("closeBannerModal").addEventListener("click", () => {
  document.getElementById("bannerModal").style.display = "none";
});

window.addEventListener("click", (e) => {
  if (e.target == document.getElementById("bannerModal")) {
    document.getElementById("bannerModal").style.display = "none";
  }
});

const colors = ["#a289ecff", "#51cf66", "#ffd43b"]; // violet green, yellow, 

function updateGroupCardColors() {
  const cards = document.querySelectorAll(".group-card");
  cards.forEach((card, index) => {
    card.style.backgroundColor = colors[index % colors.length];
  });
}

// run once
updateGroupCardColors();

// optional: if you dynamically add/remove/move cards
const observer = new MutationObserver(updateGroupCardColors);
observer.observe(document.querySelector(".group-list"), { childList: true });


const historyList = document.querySelector('.tab-panel .group-list');
const cardWidth = 185 + 20; // card width + gap

document.querySelector('.tab-panel .scroll-btn.left').addEventListener('click', () => {
  historyList.scrollBy({ left: -cardWidth * 3, behavior: 'smooth' });
});

document.querySelector('.tab-panel .scroll-btn.right').addEventListener('click', () => {
  historyList.scrollBy({ left: cardWidth * 3, behavior: 'smooth' });
});
</script>
</html>
