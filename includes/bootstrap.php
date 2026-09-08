<?php
declare(strict_types=1);

// Servidor roda com timezone padrao (UTC) — sem isso, date()/time() batem
// hora certa mas exibem o dia errado a partir das ~21h no horario de Brasilia.
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/auth.php';

$configFile = __DIR__ . '/../config/config.php';
$configExample = __DIR__ . '/../config/config.example.php';

/** @var array $config */
$config = is_file($configFile) ? require $configFile : require $configExample;

if ($config['debug'] ?? true) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
