<?php
// register.php
require __DIR__ . '/db.php';
session_start();

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$pass = $_POST['password'] ?? '';

if ($name === '' || $email === '' || $pass === '') {
  header('Location: /index.php');
  exit;
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

$stmt = $mysqli->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
$stmt->bind_param('sss', $name, $email, $hash);

try {
  $stmt->execute();
  $_SESSION['user_id'] = $stmt->insert_id;
  $_SESSION['user_name'] = $name;
  header('Location: /dashboard.php');
} catch (mysqli_sql_exception $e) {
  // email already used?
  $_SESSION['err'] = 'Registration failed: ' . ($e->getCode() === 1062 ? 'Email already in use.' : 'Please try again.');
  header('Location: /index.php');
}
?>