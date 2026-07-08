<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use DashStatus\ActivityLog;

$username = (string) ($_SESSION['username'] ?? '');

auth_logout();

if ($username !== '') {
    (new ActivityLog(__DIR__ . '/data/activity-log.json'))
        ->log('sys', sprintf("Logout: usuário '%s' encerrou a sessão.", $username));
}

header('Location: login.php');
exit;