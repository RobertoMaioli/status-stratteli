<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use DashStatus\ActivityLog;
use DashStatus\Auth\AuthService;
use DashStatus\Database;

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Sessão expirada, tente novamente.';
    } else {
        $auth = new AuthService(Database::connection($config));
        $usernameAttempt = (string) ($_POST['username'] ?? '');
        if ($auth->attempt($usernameAttempt, (string) ($_POST['password'] ?? ''))) {
            (new ActivityLog(__DIR__ . '/data/activity-log.json'))
                ->log('sys', sprintf("Login: usuário '%s' autenticado.", $usernameAttempt));
            header('Location: index.php');
            exit;
        }
        $error = 'Usuário ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sentinel · Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/login.css">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body class="login-body">
  <form class="login-card" method="post" action="login.php">
    <div class="brand brand-login">
      <img class="brand-logo" src="assets/img/logo-gray.png" alt="Stratelli">
      <div class="brand-text">
        <p>ACESSO RESTRITO</p>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <label>
      Usuário
      <input type="text" name="username" autocomplete="username" required autofocus>
    </label>
    <label>
      Senha
      <div class="password-field">
        <input type="password" id="login-password" name="password" autocomplete="current-password" required>
        <button type="button" class="password-toggle" id="password-toggle" aria-label="Mostrar senha" aria-pressed="false">
          <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.94 10.94 0 0112 19c-7 0-11-7-11-7a21.6 21.6 0 015.06-6.06M9.9 4.24A10.4 10.4 0 0112 4c7 0 11 7 11 7a21.7 21.7 0 01-2.29 3.36M14.12 14.12a3 3 0 11-4.24-4.24"/><path d="M1 1l22 22"/></svg>
        </button>
      </div>
    </label>

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <button type="submit">Entrar</button>
  </form>
<script src="assets/js/login.js?v=<?= filemtime(__DIR__ . '/assets/js/login.js') ?>"></script>
</body>
</html>