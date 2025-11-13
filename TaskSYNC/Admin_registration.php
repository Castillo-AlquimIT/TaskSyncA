<?php
require 'db.php'; // your PDO connection

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if (!empty($name) && !empty($email) && !empty($password)) {
        // Hash password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, privilege_mode) VALUES (?, ?, ?, 3)");
            $stmt->execute([$name, $email, $password_hash]);

            echo "Administrator account created successfully!";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // duplicate email
                echo "Email already registered.";
            } else {
                echo "Error: " . $e->getMessage();
            }
        }
    } else {
        echo "All fields are required.";
    }
}
?>

<!DOCTYPE html>
<html>
    <link rel="stylesheet" href="style.css">
<head>
    <title>Admin Registration</title>
</head>
<body>
<div class="container">
    <h2>Admin Registration</h2>
    <div class="card-grid">
    <form method="post">
        <label>Name:</label><br>
        <input type="text" name="name" required><br><br>

        <label>Email:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit">Register as Admin</button>
    </form>
    </div>
    </div>
</body>
</html>
