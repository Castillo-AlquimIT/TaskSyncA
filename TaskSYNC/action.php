<?php
include 'db_connect.php'; // Make sure you have this connection

$action = $_GET['action'] ?? '';
$userId = intval($_GET['user'] ?? 0);
$groupId = intval($_GET['group'] ?? 0);
$reporterId = intval($_GET['by'] ?? 0);

if (!$userId || !$groupId || !$reporterId) {
  echo "Missing parameters.";
  exit;
}

switch ($action) {
  case 'alert':
    // Insert or update offense record
    $stmt = $db->get("INSERT INTO member_reports (reported_user_id, reported_by_id, group_id, reason)
                          VALUES (?, ?, ?, 'Misconduct')
                          ON DUPLICATE KEY UPDATE offense_count = offense_count + 1");
    $stmt->bind_param('iii', $userId, $reporterId, $groupId);
    $stmt->execute();

    $result = $db->query("SELECT SUM(offense_count) AS total FROM member_reports WHERE reported_user_id = $userId AND group_id = $groupId");
    $row = $result->fetch_assoc();
    echo "Alert sent. Total offenses: " . ($row['total'] ?? 1);
    break;

  case 'kick':
    $stmt = $db->prepare("DELETE FROM group_members WHERE user_id = ? AND group_id = ?");
    $stmt->bind_param('ii', $userId, $groupId);
    $stmt->execute();
    echo "Member removed from the group.";
    break;

  case 'blacklist':
    // Add to blacklist
    $stmt = $db->prepare("INSERT IGNORE INTO group_blacklist (group_id, user_id, reason)
                          VALUES (?, ?, 'Violation of group rules')");
    $stmt->bind_param('ii', $groupId, $userId);
    $stmt->execute();

    // Also remove from group if still present
    $stmt = $db->prepare("DELETE FROM group_members WHERE user_id = ? AND group_id = ?");
    $stmt->bind_param('ii', $userId, $groupId);
    $stmt->execute();

    echo "User has been blacklisted and removed from the group.";
    break;

  default:
    echo "Invalid action.";
}
?>
