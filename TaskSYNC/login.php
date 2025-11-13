<?php
// login.php
require __DIR__ . '/db.php';
session_start();

$email = trim($_POST['email'] ?? '');
$pass = $_POST['password'] ?? '';

if ($email === '' || $pass === '') {
  header('Location: /index.php');
  exit;
}


$stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE username = ? AND password = ?");
$stmt->execute([$username, $password]);
$user = $stmt->fetch();

if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role']; // ✅ Store role here
    header("Location: dashboard.php");
    exit;
}


$stmt = $mysqli->prepare('SELECT id, name, password_hash FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->bind_result($uid, $name, $hash);
if ($stmt->fetch() && password_verify($pass, $hash)) {
  $_SESSION['user_id'] = $uid;
  $_SESSION['user_name'] = $name;
  header('Location: /dashboard.php');
} else {
  $_SESSION['err'] = 'Invalid email or password.';
  header('Location: /index.php');
}
?>