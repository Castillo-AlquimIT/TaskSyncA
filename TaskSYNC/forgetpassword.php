<?php
// forgot_password.php
require_once 'db.php'; // uses your PDO connection

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email']);

    // Check if email exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // Generate secure token and expiration (1 hour)
        $token = bin2hex(random_bytes(16));
        $expires = date("Y-m-d H:i:s", strtotime("+1 hour"));

        // Save to DB
        $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, token_expiration = ? WHERE email = ?");
        $stmt->execute([$token, $expires, $email]);

        $reset_link = "http://localhost/reset_password.php?token=$token";

        // Automatically redirect to reset link
        header("Location: $reset_link");
        exit;

    } else {
        echo "<p style='color:red;'>No account found with that email address.</p>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Forgot Password</title>
  <style>
    body {
      font-family: Arial, sans-serif; 
      background-color: #f7f4ff;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      margin: 0;
    }

    .card {
      background-color: #fff;
      border: 2px solid #cbb5ff;
      border-radius: 12px;
      box-shadow: 0 4px 8px rgba(128, 0, 128, 0.1);
      width: 400px;
      padding: 30px 40px;
      text-align: left;
    }

    h2 {
      color: #2e1065;
      font-size: 1.4rem;
      margin-bottom: 10px;
    }

    p {
      color: #4b5563;
      font-size: 0.95rem;
      margin-bottom: 20px;
    }

    input[type="email"] {
      width: 100%;
      padding: 10px 12px;
      border-radius: 6px;
      border: 1px solid #b794f4;
      background-color: #f9f5ff;
      outline: none;
      font-size: 0.95rem;
      box-sizing: border-box;
      margin-bottom: 20px;
    }

    input[type="email"]:focus {
      border-color: #7c3aed;
      box-shadow: 0 0 4px #c4b5fd;
    }

    button {
      background-color: #7c3aed;
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 12px;
      font-weight: 600;
      cursor: pointer;
      width: 100%;
      font-size: 0.95rem;
      transition: background-color 0.2s ease;
    }

    button:hover {
      background-color: #5b21b6;
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
  <form method="POST" class="card">
<button type="button" class="button small" onclick="history.back()">← Back</button>
    <h2>Forgot Password</h2>
    <p>Enter your registered email address to receive a reset link.</p>
    <input type="email" name="email" placeholder="Email" required>
    <button type="submit">Send Reset Link</button>
  </form>
</body>
</html>

