<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit('Este script só pode ser executado via linha de comando (CLI).' . PHP_EOL);
}

$username = $argv[1] ?? null;
$password = $argv[2] ?? null;

if (!$username || !$password) {
    echo 'Uso: php bin/create-admin.php <usuario> <senha>' . PHP_EOL;
    exit(1);
}

$pdo = \DashStatus\Database::connection($config);

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    'INSERT INTO users (username, password_hash) VALUES (:username, :hash)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
);
$stmt->execute(['username' => $username, 'hash' => $hash]);

echo "Usuário '{$username}' criado/atualizado com sucesso." . PHP_EOL;