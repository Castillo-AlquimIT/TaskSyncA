<?php
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("You must be logged in to change your password.");
}

$redirect = false; // define variable
$message = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Fetch user
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        $message = "<p style='color:red;'>Account not found.</p>";
    } elseif (!password_verify($old_password, $user['password_hash'])) {
        $message = "<p style='color:red;'>Incorrect old password.</p>";
    } elseif ($new_password !== $confirm_password) {
        $message = "<p style='color:red;'>New passwords do not match.</p>";
    } else {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $update->execute([$new_hash, $_SESSION['user_id']]);

        // Destroy session to force re-login
        session_destroy();

        $message = "<p style='color:green;'>Password updated successfully! Redirecting to login...</p>";
        $redirect = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Styles adapted for better accessibility and design */
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f7f4ff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background-color: #fff;
            border: 2px solid #cbb5ff;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(128, 0, 128, 0.15); /* Stronger shadow */
            width: 100%;
            max-width: 400px; /* Use max-width for responsiveness */
            padding: 30px 40px;
            text-align: left;
            box-sizing: border-box; /* Include padding in element's total width and height */
        }
        h2 {
            color: #3c1d84;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 700;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #3c1d84;
            font-weight: 600;
            font-size: 14px;
        }
        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #d0b3ff;
            border-radius: 8px; /* Slightly more rounded */
            background: #f9f6ff;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }
        input:focus {
            border-color: #8b4dff;
            box-shadow: 0 0 0 3px rgba(139, 77, 255, 0.2);
        }
        button {
            width: 100%;
            padding: 14px; /* Increased padding */
            background: #8b4dff;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: background 0.3s, transform 0.1s;
        }
        button:hover {
            background: #7029ff;
        }
        button:active {
            transform: translateY(1px);
        }
        .message {
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
            font-weight: 500;
        }
        /* Mobile adjustments */
        @media (max-width: 500px) {
            .container {
                margin: 20px;
                padding: 20px 25px;
            }
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
    <div class="container">
        <form method="POST">
            <button type="button" class="button small" onclick="history.back()">← Back</button>
            <h2>Change Password</h2>
            <div class="message"><?= $message ?></div>
            
            <label for="old_password">Old Password</label>
            <input type="password" id="old_password" name="old_password" required aria-label="Old Password">
            
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" required aria-label="New Password">
            
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required aria-label="Confirm New Password">
            
            <button type="submit">Update Password</button>
        </form>
    </div>

    <?php if ($redirect): ?>
        <script>
            // Automatically redirect to the login page after a delay
            setTimeout(() => {
                window.location.href = 'index.php'; // Assuming index.php is the login/home page
            }, 3000); // 3 seconds delay before redirect
        </script>
    <?php endif; ?>
</body>
</html>