<?php
//calendar.php
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
// ✅ Join group by ID (called from the calendar click)
if (isset($_GET['action']) && $_GET['action'] === 'join_group' && isset($_GET['id'])) {
    $group_id = (int)$_GET['id'];
    $user_id = $_SESSION['user_id'];

    // Check if the group actually exists
    $stmt = $pdo->prepare("SELECT id FROM task_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    if ($stmt->rowCount() === 0) {
        echo "<script>alert('Group not found.'); window.location.href='taskmanager.php';</script>";
        exit;
    }

    // Check if already a member
    $stmt = $pdo->prepare("SELECT 1 FROM group_members WHERE user_id = ? AND group_id = ?");
    $stmt->execute([$user_id, $group_id]);

    if ($stmt->rowCount() === 0) {
        $insert = $pdo->prepare("INSERT INTO group_members (user_id, group_id, role) VALUES (?, ?, 'member')");
        $insert->execute([$user_id, $group_id]);
        echo "<script>alert('✅ You have successfully joined the group!');</script>";
    } else {
        echo "<script>alert('⚠️ You are already a member of this group.');</script>";
    }

    echo "<script>window.location.href='taskmanager.php';</script>";
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
    LIMIT 3
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
        LIMIT 5
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Calendar</title>
    <link rel="stylesheet" href="dashboardstyle.css">
    <style>
        .sidebar {  
            width: 220px;
            height: 600px;
        }
        .deadline-completed {
            background: #d1fae5; /* light green */
            color: #047857;      /* dark green */
        }
        .deadline-passed {
            background: #fecaca; /* light red */
            color: #b91c1c;      /* dark red */
        }
        .deadline-dropped {
            background: #111; /* black */
            color: #fff;
        }
        .deadline-delayed {
            background: #fef3c7; /* light yellow */
            color: #92400e;
        }

/* 🔹 Modal overlay */
.modal {
  display: none; 
  position: fixed; 
  z-index: 999; 
  left: 0;
  top: 0;
  width: 100%; 
  height: 100%;
  background-color: rgba(0, 0, 0, 0.6); /* dark background */
  justify-content: center;
  align-items: center;
}

/* 🔹 Modal content box */
.modal-content {
  background-color: #fff;
  margin: auto;
  padding: 20px 30px;
  border-radius: 12px;
  box-shadow: 0 5px 15px rgba(0,0,0,0.3);
  width: 360px;
  text-align: center;
  position: relative;
  animation: fadeIn 0.25s ease;
}

/* 🔹 Close button (X) */
.close {
  position: absolute;
  top: 8px;
  right: 12px;
  font-size: 22px;
  cursor: pointer;
  color: #555;
}
.close:hover {
  color: #000;
}

/* 🔹 Group selection buttons */
.selectGroupBtn {
  background-color: #3b82f6;
  color: white;
  border: none;
  padding: 10px 16px;
  margin: 6px 0;
  width: 100%;
  border-radius: 6px;
  font-size: 15px;
  cursor: pointer;
  transition: background 0.2s ease;
}
.selectGroupBtn:hover {
  background-color: #2563eb;
}

/* 🔹 Confirm join button */
#confirmJoinBtn {
  background-color: #10b981;
  color: white;
  border: none;
  padding: 10px 20px;
  margin-top: 15px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 15px;
}
#confirmJoinBtn:hover {
  background-color: #059669;
}

/* 🔹 Animation */
@keyframes fadeIn {
  from { transform: scale(0.9); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
        .task-tooltip {
            position: absolute;
            background: #333;
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 13px;
            z-index: 9999;
            pointer-events: none;
        }
    </style>
</head>
<body>
<?php
require_once __DIR__ . '/db.php';

$user_id = (int)$_SESSION['user_id'];

// ✅ Calendar deadline data
$deadlineQuery = "
SELECT 
    DATE(td.deadline_date) AS deadline_date,
    t.title AS task_title,
    g.id AS group_id,
    g.name AS group_name,
    g.code AS group_code,
    td._status
FROM task_groups g
JOIN group_members gm ON gm.group_id = g.id
JOIN tasks t ON t.group_id = g.id
JOIN task_deadline td ON td.task_id = t.id
WHERE gm.user_id = :user_id
";
$stmt_deadlines = $pdo->prepare($deadlineQuery);
$stmt_deadlines->execute(['user_id' => $user_id]);
$deadlines = $stmt_deadlines->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="sidebar">
  <h2>TaskSync</h2>
  <ul>
    <li><a href="dashboard.php">📚 Dashboard</a></li>
    <li><a href="calendar.php" class="active">📅 Calendar</a></li>
    <li><a href="profile.php">👤 Profile</a></li>
    <li><a href="Projects.php">📘 Projects</a></li>
    <li><a href="logout.php" class="logout">➜ Logout</a></li>
    <?php if ($privilege === 3): ?>
       <li><a href="admin_panel.php">🔧Moderator Panel</a></li>
    <?php endif; ?>
  </ul>
</div>

<div class="main">
<div class="calendar-container">
    <h1>Calendar</h1>  
    <div class="header">
        <button id="prevMonth">&lt;</button>
        <h2 id="monthYear"></h2>
        <button id="nextMonth">&gt;</button>
    </div>
    <div class="weekdays">
        <div>Sun</div>
        <div>Mon</div>
        <div>Tue</div>
        <div>Wed</div>
        <div>Thu</div>
        <div>Fri</div>
        <div>Sat</div>
    </div>
    <div class="days" id="calendarDays"></div>
</div>
</div>

<!-- ✅ Modal -->
<div id="joinModal" class="modal">
    <div class="modal-content">
        <span id="closeModal" class="close">&times;</span>
        <h3 id="modalTitle"></h3>
        <p id="modalMessage"></p>
        <button id="confirmJoinBtn">Join</button>
    </div>
</div>
</body>
<script>
const monthYearDisplay = document.getElementById('monthYear');
const calendarDays = document.getElementById('calendarDays');
const prevMonthBtn = document.getElementById('prevMonth');
const nextMonthBtn = document.getElementById('nextMonth');
const modal = document.getElementById('joinModal');
const modalTitle = document.getElementById('modalTitle');
const modalMessage = document.getElementById('modalMessage');
const confirmJoinBtn = document.getElementById('confirmJoinBtn');
const closeModal = document.getElementById('closeModal');

let selectedGroup = null;
let currentDate = new Date();
const deadlines = <?php echo json_encode($deadlines); ?>;

// Render calendar
function renderCalendar() {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    const firstDayOfMonth = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    monthYearDisplay.textContent = new Date(year, month).toLocaleString('en-US', {
        month: 'long',
        year: 'numeric'
    });

    calendarDays.innerHTML = '';

    for (let i = 0; i < firstDayOfMonth; i++) {
        const emptyDiv = document.createElement('div');
        emptyDiv.classList.add('empty');
        calendarDays.appendChild(emptyDiv);
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const dayDiv = document.createElement('div');
        dayDiv.textContent = day;

        const nowPH = new Date(new Date().toLocaleString("en-US", { timeZone: "Asia/Manila" }));
        if (day === nowPH.getDate() && month === nowPH.getMonth() && year === nowPH.getFullYear()) {
            dayDiv.classList.add('current-day');
        }

        const phDate = new Date(Date.UTC(year, month, day, 0, 0, 0));
        phDate.setUTCHours(phDate.getUTCHours() + 8);
        const yyyy = phDate.getFullYear();
        const mm = String(phDate.getMonth() + 1).padStart(2, '0');
        const dd = String(phDate.getDate()).padStart(2, '0');
        const currentDateStr = `${yyyy}-${mm}-${dd}`;

        const matchedTasks = deadlines.filter(d => d.deadline_date === currentDateStr);

        if (matchedTasks.length > 0) {
            dayDiv.classList.add('has-deadline');

            const taskDetails = matchedTasks.map(t => `${t.task_title} (${t.group_name})`).join('\n');
            dayDiv.setAttribute('data-tasks', taskDetails);

            const uniqueGroups = [];
            matchedTasks.forEach(t => {
                if (!uniqueGroups.some(g => g.id === t.group_id)) {
                    uniqueGroups.push({ id: t.group_id, name: t.group_name });
                }
            });
            dayDiv.dataset.groups = JSON.stringify(uniqueGroups);

            const dayPH = new Date(Date.UTC(year, month, day, 0, 0, 0));
            dayPH.setUTCHours(dayPH.getUTCHours() + 8);
            const todayPH = new Date(new Date().toLocaleString("en-US", { timeZone: "Asia/Manila" }));
            todayPH.setHours(0, 0, 0, 0);

            if (dayPH < todayPH) {
                const allCompleted = matchedTasks.every(t => t._status == 3);
                const allDropped = matchedTasks.every(t => t._status == 5);
                const allDelayed = matchedTasks.every(t => t._status == 4);

                if (allCompleted) dayDiv.classList.add('deadline-completed');
                else if (allDropped) dayDiv.classList.add('deadline-dropped');
                else if (allDelayed) dayDiv.classList.add('deadline-delayed');
                else dayDiv.classList.add('deadline-passed');
            }
        }

        calendarDays.appendChild(dayDiv);
    }
}

// Tooltip
let tooltip;
function showTooltip(e, text) {
    tooltip = document.createElement('div');
    tooltip.className = 'task-tooltip';
    tooltip.innerHTML = text.replace(/\n/g, '<br>');
    document.body.appendChild(tooltip);
    const rect = e.target.getBoundingClientRect();
    tooltip.style.left = `${rect.left + window.scrollX + 10}px`;
    tooltip.style.top = `${rect.top + window.scrollY - 35}px`;
}
function hideTooltip() {
    if (tooltip) {
        tooltip.remove();
        tooltip = null;
    }
}

// Hover tooltip events
calendarDays.addEventListener('mouseover', e => {
    const target = e.target.closest('.has-deadline');
    if (!target) return;
    showTooltip(e, target.getAttribute('data-tasks'));
});
calendarDays.addEventListener('mouseout', e => {
    hideTooltip();
});

// Modal events
closeModal.addEventListener('click', () => modal.style.display = 'none');

// When user clicks a date
calendarDays.addEventListener('click', e => {
    const target = e.target.closest('.has-deadline');
    if (!target) return;

    const groups = JSON.parse(target.dataset.groups || '[]');
    if (groups.length === 0) return;

    // Multiple groups → show selection list inside modal
    if (groups.length > 1) {
        modalTitle.textContent = 'Select a group to join';
        modalMessage.innerHTML = groups
            .map(g => `<button class="selectGroupBtn" data-id="${g.id}" data-name="${g.name}">${g.name}</button>`)
            .join('<br>');
        confirmJoinBtn.style.display = 'none';
        modal.style.display = 'block';

        document.querySelectorAll('.selectGroupBtn').forEach(btn => {
            btn.addEventListener('click', () => {
                selectedGroup = { id: btn.dataset.id, name: btn.dataset.name };
                modalTitle.textContent = 'Join Group';
                modalMessage.textContent = `Do you want to join ${selectedGroup.name}?`;
                confirmJoinBtn.style.display = 'inline-block';
            });
        });

    } else {
        openJoinModal(groups[0]);
    }
});

confirmJoinBtn.addEventListener('click', () => {
    if (selectedGroup) {
        window.location.href = `taskmanager.php?group_id=${encodeURIComponent(selectedGroup.id)}`;
    }
});

function openJoinModal(group) {
    selectedGroup = group;
    modalTitle.textContent = 'Join Group';
    modalMessage.textContent = `Do you want to join ${group.name}?`;
    confirmJoinBtn.style.display = 'inline-block';
    modal.style.display = 'block';
}

// Navigation
prevMonthBtn.addEventListener('click', () => {
    currentDate.setMonth(currentDate.getMonth() - 1);
    renderCalendar();
});
nextMonthBtn.addEventListener('click', () => {
    currentDate.setMonth(currentDate.getMonth() + 1);
    renderCalendar();
});

// Initialize
renderCalendar();

</script>
</html>