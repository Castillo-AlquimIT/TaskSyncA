<?php
// db.php
$DB_HOST = '127.0.0.1';
$DB_NAME = 'taskmanager'; // ✅ your actual database name
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

date_default_timezone_set('Asia/Manila');

// Start session if not already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw exceptions
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // return assoc arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // use real prepares
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
?>
