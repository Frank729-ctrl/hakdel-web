<?php
require_once __DIR__ . '/../config/app.php';

// Redirect if already logged in as admin
if (isset($_SESSION['admin_id'])) redirect('/admin/dashboard.php');

$error = '';

if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Invalid form submission.';
    } else {
        $login    = trim($_POST['login']    ?? '');
        $password = $_POST['password']      ?? '';

        $stmt = db()->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) AND role = "admin"');
        $stmt->execute([$login, $login]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_id']       = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            redirect('/admin/dashboard.php');
        } else {
            $error = 'Invalid credentials or insufficient privileges.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel Admin</title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="../assets/auth.css">
</head>
<body class="auth-page">
<div class="auth-shell">
  <div class="auth-brand">
    <span class="logo-dot"></span>
    <span class="logo-text">HAK<span class="logo-accent">DEL</span> <span style="color:var(--danger);font-size:11px;letter-spacing:2px">ADMIN</span></span>
  </div>
  <div class="auth-card">
    <div class="auth-heading">Admin Access</div>
    <div class="auth-sub">Restricted area. Authorized personnel only.</div>
    <?php if ($error): ?>
    <div class="auth-errors"><div class="auth-error-item"><?php echo h($error); ?></div></div>
    <?php endif; ?>
    <form method="POST" class="auth-form">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <div class="form-field">
        <label class="form-label">Username or Email</label>
        <input type="text" name="login" class="form-input" placeholder="admin username" required>
      </div>
      <div class="form-field">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-input" placeholder="password" required>
      </div>
      <button type="submit" class="btn-auth">Enter Admin Panel</button>
    </form>
  </div>
</div>
</body>
</html>