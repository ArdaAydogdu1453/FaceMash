<?php
/**
 * FaceMash — Modern Glassmorphic Admin Security Portal
 * ----------------------------------------------------
 * High-end dark theme, brute-force rate-limiting, password visibility toggle,
 * and seamless keyboard accessibility.
 */

if (session_status() === PHP_SESSION_NONE) {
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $params['lifetime'],
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

require 'config.php';

$error = '';

// Redirect if already authenticated
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    header('Location: admin.php');
    exit;
}

// Simple session-based brute-force throttle
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

// Reset attempts if 5 minutes have passed
if (time() - $_SESSION['last_attempt_time'] > 300) {
    $_SESSION['login_attempts'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_SESSION['login_attempts'] >= 5) {
        $error = 'Çok fazla hatalı deneme yapıldı. Lütfen 5 dakika bekleyiniz.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (hash_equals(ADMIN_USERNAME, $username) && hash_equals(ADMIN_PASSWORD, $password)) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
            $_SESSION['admin_ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $_SESSION['login_attempts'] = 0;
            header('Location: admin.php');
            exit;
        } else {
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
            $remaining = 5 - $_SESSION['login_attempts'];
            $error = "Geçersiz kullanıcı adı veya şifre. (Kalan deneme: {$remaining})";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>FaceMash — Yönetici Güvenlik Portalı</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles.css">
  <link rel="stylesheet" href="assets/styles_asb.css">
  <style>
    body.login-body {
      background: var(--asb-ink);
      color: var(--asb-text);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      margin: 0;
      padding: 1.5rem;
      font-family: var(--asb-font-body);
    }
    .login-container {
      width: 100%;
      max-width: 400px;
      padding: 2.75rem 2.25rem;
      background: var(--asb-surface);
      border: 1px solid var(--asb-surface-2);
      border-radius: 20px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
      text-align: center;
      position: relative;
      overflow: hidden;
    }
    .login-container::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--asb-gold), var(--asb-mint));
    }
    .login-shield {
      width: 54px;
      height: 54px;
      background: rgba(232, 169, 60, 0.12);
      border: 1px solid rgba(232, 169, 60, 0.25);
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.6rem;
      margin-bottom: 1.25rem;
    }
    .login-container h1 {
      font-family: var(--asb-font-display);
      font-size: 1.6rem;
      margin: 0 0 0.4rem 0;
      color: var(--asb-text);
    }
    .login-sub {
      color: var(--asb-text-dim);
      font-size: 0.9rem;
      margin-bottom: 2rem;
    }
    .form-group {
      margin-bottom: 1.25rem;
      text-align: left;
    }
    .form-group label {
      display: block;
      font-family: var(--asb-font-display);
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--asb-text-dim);
      margin-bottom: 0.45rem;
    }
    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }
    .form-group input {
      width: 100%;
      padding: 0.8rem 1rem;
      background: var(--asb-surface-2);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 10px;
      font-size: 0.95rem;
      color: var(--asb-text);
      font-family: inherit;
      outline: none;
      transition: all 0.2s ease;
      box-sizing: border-box;
    }
    .form-group input:focus {
      border-color: var(--asb-gold);
      box-shadow: 0 0 0 3px rgba(232, 169, 60, 0.15);
      background: #2a2e3d;
    }
    .toggle-pwd {
      position: absolute;
      right: 12px;
      background: none;
      border: none;
      color: var(--asb-text-dim);
      cursor: pointer;
      font-size: 1.1rem;
      padding: 0;
    }
    .toggle-pwd:hover {
      color: var(--asb-text);
    }
    .login-btn {
      width: 100%;
      padding: 0.85rem;
      background: var(--asb-gold);
      color: var(--asb-ink);
      border: none;
      border-radius: 10px;
      font-family: var(--asb-font-display);
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
      margin-top: 0.75rem;
    }
    .login-btn:hover {
      background: #f2ba52;
      transform: translateY(-2px);
      box-shadow: 0 4px 18px rgba(232, 169, 60, 0.3);
    }
    .error-msg {
      background: rgba(226, 61, 69, 0.12);
      color: #fca5a5;
      border: 1px solid rgba(226, 61, 69, 0.3);
      padding: 0.75rem 1rem;
      border-radius: 10px;
      font-size: 0.85rem;
      margin-top: 1.25rem;
      animation: shake 0.4s ease;
    }
    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      20%, 60% { transform: translateX(-6px); }
      40%, 80% { transform: translateX(6px); }
    }
    .back-link {
      display: inline-block;
      margin-top: 1.75rem;
      color: var(--asb-text-dim);
      text-decoration: none;
      font-size: 0.85rem;
      font-weight: 500;
      transition: color 0.2s ease;
    }
    .back-link:hover {
      color: var(--asb-gold);
    }
  </style>
</head>
<body class="login-body">

<div class="login-container">
  <div class="login-shield"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></div>
  <h1>Yönetici Portalı</h1>
  <div class="login-sub">FaceMash sistem ve galeri kontrolü</div>

  <form action="login.php" method="post">
    <div class="form-group">
      <label for="username">Kullanıcı Adı</label>
      <input type="text" id="username" name="username" required autocomplete="username" autofocus>
    </div>
    <div class="form-group">
      <label for="password">Yönetici Şifresi</label>
      <div class="input-wrapper">
        <input type="password" id="password" name="password" required autocomplete="current-password">
        <button type="button" class="toggle-pwd" id="togglePwd" title="Şifreyi Göster/Gizle" aria-label="Şifreyi Göster/Gizle"><svg id="eyeOpen" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg><svg id="eyeClosed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"></path><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"></path><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"></path><line x1="2" y1="2" x2="22" y2="22"></line></svg></button>
      </div>
    </div>
    <button type="submit" class="login-btn">Güvenli Giriş</button>
  </form>

  <?php if ($error): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <a href="index.php" class="back-link">← Karşılaştırma Arenasına Dön</a>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('togglePwd');
    const pwdInput  = document.getElementById('password');
    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', () => {
            const isPassword = pwdInput.type === 'password';
            pwdInput.type = isPassword ? 'text' : 'password';
            document.getElementById('eyeOpen').style.display = isPassword ? 'none' : 'block';
            document.getElementById('eyeClosed').style.display = isPassword ? 'block' : 'none';
        });
    }
});
</script>

</body>
</html>