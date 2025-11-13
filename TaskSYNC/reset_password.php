<?php
require_once 'db.php'; // use your PDO connection

if (!isset($_GET['token'])) {
    die("Invalid or missing token.");
}

$token = $_GET['token'];

// Find the user with this reset token
$stmt = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND token_expiration > NOW()");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    die("<p style='color:red; text-align:center;'>Invalid or expired token.</p>");
}

$message = "";
$redirect = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $message = "<p style='color:red;'>Passwords do not match.</p>";
    } else {
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, token_expiration = NULL WHERE id = ?");
        $update->execute([$password_hash, $user['id']]);

        $message = "<p style='color:green;'>Password reset successful! Redirecting to login...</p>";
        $redirect = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <style>
        body {
            font-family: "Poppins", sans-serif;
            background-color: #f7f4ff;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background-color: #fff;
            border: 2px solid #cbb5ff;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(128, 0, 128, 0.1);
            width: 400px;
            padding: 30px 40px;
            text-align: left;
        }
        h2 {
            color: #3c1d84;
            margin-bottom: 10px;
        }
        p {
            color: #555;
            font-size: 14px;
            margin-bottom: 20px;
        }
        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #d0b3ff;
            border-radius: 6px;
            background: #f9f6ff;
            font-size: 14px;
            outline: none;
        }
        input:focus {
            border-color: #8b4dff;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #8b4dff;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        button:hover {
            background: #7029ff;
        }
        .message {
            margin-bottom: 10px;
            font-size: 14px;
        }
                .button.small {
        width: 80px;
        padding: 4px 6px;
        font-size: 12px;
        border-radius: 5px;
        border: none;
        background-color: #8b4dff;
        color: #3c1d84;
        cursor: pointer;
        transition: background-color 0.2s ease;
        }

        .button.small:hover {
            background-color: #7029ff;
        }

    </style>
</head>
<body>
    <form class="container" method="POST">
       <button type="button" class="button small" onclick="history.back()">← Back</button>


        <h2>Reset Password</h2>
        <p>Enter your new password below.</p>
        <div class="message"><?= $message ?></div>
        <input type="password" name="new_password" placeholder="New Password" required>
        <input type="password" name="confirm_password" placeholder="Confirm Password" required>
        <button type="submit">Update Password</button>
    </form>

    <?php if ($redirect): ?>
        <script>
            setTimeout(() => { window.location.href = 'login.php'; }, 2500);
        </script>
    <?php endif; ?>
</body>
</html>