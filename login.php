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
</head>
<body class="login-body">
  <form class="login-card" method="post" action="login.php">
    <div class="brand">
      <div class="brand-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="1.8"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/></svg>
      </div>
      <div class="brand-text">
        <h1>Sentinel</h1>
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
      <input type="password" name="password" autocomplete="current-password" required>
    </label>

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <button type="submit">Entrar</button>
  </form>
</body>
</html>