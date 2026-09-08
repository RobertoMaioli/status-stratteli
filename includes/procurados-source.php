<?php
declare(strict_types=1);

/**
 * Fonte de dados do Painel de Procurados: consome ao vivo o
 * /api/publico/procurados do mandados-system (que já roda no mesmo servidor
 * do MySQL de origem, sem depender de túnel SSH nem de gerar um JSON à
 * mão). Um cache curto em arquivo evita bater na API a cada carregamento de
 * página; se a API cair, serve o último cache bom (mesmo vencido) em vez de
 * mostrar o painel vazio.
 */

require_once __DIR__ . '/procurados-transform.php';

function procuradosCarregar(array $config): array
{
    $servico = $config['services']['mandados'] ?? [];
    $baseUrl = rtrim((string) ($servico['base_url'] ?? ''), '/');
    $apiKey = (string) ($servico['api_key'] ?? '');
    $cliente = (string) ($servico['cliente'] ?? 'MGR');
    $ttl = (int) ($servico['cache_ttl_seconds'] ?? 60);

    $cacheFile = __DIR__ . '/../data/procurados-cache.json';
    $cache = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    $cache = is_array($cache) ? $cache : null;
    $cacheValido = $cache !== null && (time() - (int) ($cache['gerado_em'] ?? 0)) < $ttl;

    if ($cacheValido) {
        return $cache;
    }

    if ($baseUrl === '' || $apiKey === '') {
        return $cache ?? ['registros' => [], 'gerado_em' => 0, 'fonte' => 'nao_configurado'];
    }

    $resposta = procuradosBuscarDaApi($baseUrl, $apiKey, $cliente);
    if ($resposta === null) {
        // API fora do ar — serve o último cache bom (mesmo vencido) em vez de painel vazio.
        return $cache ?? ['registros' => [], 'gerado_em' => 0, 'fonte' => 'indisponivel'];
    }

    $registros = array_map('procuradosMontarRegistro', $resposta['mandados'] ?? []);
    $novoCache = ['registros' => $registros, 'gerado_em' => time(), 'fonte' => 'api'];

    @file_put_contents($cacheFile, json_encode($novoCache, JSON_UNESCAPED_UNICODE));
    return $novoCache;
}

function procuradosBuscarDaApi(string $baseUrl, string $apiKey, string $cliente): ?array
{
    $url = $baseUrl . '/api/publico/procurados?cliente=' . urlencode($cliente);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "X-Api-Key: {$apiKey}\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s(\d{3})\s/', $statusLine, $m) || $m[1] !== '200') {
        return null;
    }
    $dados = json_decode($body, true);
    return is_array($dados) ? $dados : null;
}
