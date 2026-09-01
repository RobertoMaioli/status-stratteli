<?php
declare(strict_types=1);

/**
 * API REST para integração externa com o Painel de Procurados.
 *
 * Autenticação por token (server-to-server, não usa a sessão de login do
 * painel): header `X-Api-Key: <token>`, comparado contra
 * config.php > services.procurados.api_key.
 *
 * Parâmetros opcionais (query string):
 *   risco      alta | media | baixa | altissima | sem
 *   categoria  valor exato de "categoria" (ver assets/data ou a ficha do painel)
 *   todos      1 = inclui registros sem foto cadastrada (por padrão só os com foto,
 *              mesmo comportamento do painel visual)
 *   page       página (padrão 1)
 *   per_page   itens por página (padrão 100, máximo 500)
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$apiKey = $config['services']['procurados']['api_key'] ?? '';
$provided = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($apiKey === '' || !hash_equals($apiKey, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido ou ausente. Envie o header X-Api-Key.']);
    exit;
}

$dataFile = __DIR__ . '/../data/procurados.json';
$dados = is_file($dataFile) ? json_decode((string) file_get_contents($dataFile), true) : [];
if (!is_array($dados)) {
    $dados = [];
}

if (($_GET['todos'] ?? '') !== '1') {
    $dados = array_values(array_filter($dados, fn ($p) => !empty($p['foto'])));
}

$risco = $_GET['risco'] ?? null;
if ($risco !== null) {
    $dados = array_values(array_filter($dados, fn ($p) => ($p['risco'] ?? null) === $risco));
}

$categoria = $_GET['categoria'] ?? null;
if ($categoria !== null) {
    $dados = array_values(array_filter($dados, fn ($p) => ($p['categoria'] ?? null) === $categoria));
}

$total = count($dados);
$perPage = max(1, min(500, (int) ($_GET['per_page'] ?? 100)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$pagina = array_slice($dados, $offset, $perPage);

// Foto vem como caminho relativo no JSON interno — vira URL absoluta aqui
// pra quem consome a API de fora não precisar adivinhar o domínio.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
$apiPos = strrpos($scriptPath, '/api/');
$appRoot = $apiPos !== false ? rtrim(substr($scriptPath, 0, $apiPos), '/') : '';
$baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $appRoot;
foreach ($pagina as &$registro) {
    if (!empty($registro['foto'])) {
        $registro['foto'] = $baseUrl . '/' . $registro['foto'];
    }
}
unset($registro);

echo json_encode([
    'ok' => true,
    'total' => $total,
    'pagina' => $page,
    'por_pagina' => $perPage,
    'total_paginas' => (int) max(1, ceil($total / $perPage)),
    'atualizado_em' => is_file($dataFile) ? date('c', filemtime($dataFile)) : null,
    'dados' => $pagina,
], JSON_UNESCAPED_UNICODE);
