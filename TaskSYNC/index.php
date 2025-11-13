<?php
// index.php
require_once __DIR__ . '/db.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['register'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        if ($name === '' || $email === '' || $pass === '') {
            $err = 'All fields required.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?,?,?)');
                $stmt->execute([$name, $email, $hash]);
                $_SESSION['user_id'] = (int)$pdo->lastInsertId();
                $_SESSION['user_name'] = $name;
                $_SESSION['privilege_mode'] = 0;
                header('Location: dashboard.php'); exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') $err = 'Email already registered.';
                else $err = 'Registration error.';
            }
        }
    } elseif (isset($_POST['login'])) {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        if ($email === '' || $pass === '') {
            $err = 'All fields required.';
        } else {
            $stmt = $pdo->prepare('SELECT id, name, password_hash, privilege_mode FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $u = $stmt->fetch();
            if ($u && password_verify($pass, $u['password_hash'])) {
                $_SESSION['user_id'] = (int)$u['id'];
                $_SESSION['user_name'] = $u['name'];
                $_SESSION['privilege_mode'] = (int)$u['privilege_mode'];
                header('Location: dashboard.php'); exit;
            } else {
                $err = 'Invalid credentials. Sign up or Forget password';
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title>Task Sync — Login / Register</title>
<style>
body {
  font-family: "Poppins", sans-serif;
  background-color: #F5EBD3;;
  color: #2e2e2e;
  margin: 0;
  padding: 0;
  height: 100vh;
  display: flex;
}

/* Split layout */
.left-half {
  flex: 1;
  background-color: #ece6ff;
  display: flex;
  align-items: center;
  justify-content: center;
  /* You can add a background image later like this:
     background-image: url('your-image.jpg');
     background-size: cover;
     background-position: center;
  */
}

.right-half {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  background-color: #F5EBD3;;
  padding: 40px;
  overflow-y: auto;
}

h1 {
  color: #3a2c6e;
  font-weight: 700;
  margin-bottom: 40px;
  text-align: center;
}

.card-grid {
  display: flex;
  justify-content: center;
  gap: 40px;
  flex-wrap: wrap;
}

.card {
  background-color: #fff;
  border: 2px solid #cbb5ff;
  box-shadow: 0 4px 8px rgba(128, 0, 128, 0.1);
  padding: 30px;
  width: 320px;
  text-align: left;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 14px rgba(128, 0, 128, 0.2);
}

h3 {
  text-align: center;
  color: #2e1065;
  font-size: 1.3rem;
  margin-bottom: 20px;
}

input {
  display: block;
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #b794f4;
  background-color: #f9f5ff;
  outline: none;
  font-size: 0.95rem;
  box-sizing: border-box;
  margin-bottom: 15px;
}

input:focus {
  border-color: #7c3aed;
  box-shadow: 0 0 4px #c4b5fd;
}

button.btn {
  background-color: #7c3aed;
  color: white;
  border: none;
  padding: 10px 20px;
  cursor: pointer;
  font-weight: 600;
  width: 100%;
  transition: background-color 0.2s;
}

button.btn:hover {
  background-color: #5b21b6;
}

a {
  color: #7c3aed;
  text-decoration: none;
}

a:hover {
  text-decoration: underline;
}

small {
  font-size: 0.85rem;
}

@media (max-width: 900px) {
  body {
    flex-direction: column;
  }
  .left-half {
    height: 40vh;
  }
  .right-half {
    height: 60vh;
  }
}
</style>
</head>
<body>
  <div class="left-half">
    <!-- You can later put your image or logo here -->
    <h2 style="color:#4c1d95;">Your Image / Branding Area</h2>
  </div>

  <div class="right-half">
    <h1>Tasksync</h1>
    <div class="card-grid">
      <div class="card" id="formCard">
        <h3 id="formTitle">Login</h3>
        <?php if (!empty($err)): ?>
        <div style="color: red; margin-bottom: 10px;">
          <?= htmlspecialchars($err) ?>
        </div>
      <?php endif; ?>

        <form method="post" id="authForm">
          <input name="email" type="email" placeholder="Email" required>
          <input name="password" type="password" placeholder="Password" required>
          <a href="forgetpassword.php"><small>Forget Password</small></a><br><br>
          <button name="login" class="btn">Login</button>
          <a href="#" id="toggleForm">sign up</a>
        </form>
      </div>
    </div>
  </div>
</body>
<script>
const formCard = document.getElementById('formCard');
const formTitle = document.getElementById('formTitle');
const authForm = document.getElementById('authForm');

authForm.addEventListener('click', function(e) {
  if (e.target && e.target.id === 'toggleForm') {
    e.preventDefault();
    if (formTitle.textContent === 'Login') {
      // Switch to Sign Up form
      formTitle.textContent = 'Sign up';
      authForm.innerHTML = `
        <input name="name" placeholder="Your name" required>
        <input name="email" type="email" placeholder="Email" required>
        <input name="password" type="password" placeholder="Password" required>
        <button name="register" class="btn">Create account</button>
        <a href="#" id="toggleForm">Login</a>
      `;
    } else {
      // Switch back to Login form
      formTitle.textContent = 'Login';
      authForm.innerHTML = `
        <input name="email" type="email" placeholder="Email" required>
        <input name="password" type="password" placeholder="Password" required>
        <a href="forgetpassword.php"><small>Forget Password</small></a><br><br>
        <button name="login" class="btn">Login</button>
        <a href="#" id="toggleForm">sign up</a>
      `;
    }
  }
});
</script>
</html>
