<?php
declare(strict_types=1);

/**
 * Gera manualmente data/procurados.json a partir do MySQL de produção do
 * mandados-system (via túnel SSH) — mantido como alternativa offline.
 *
 * Desde a integração ao vivo (includes/procurados-source.php), o Painel de
 * Procurados não depende mais deste JSON: ele consome
 * GET /api/publico/procurados direto do mandados-system, com cache curto.
 * Este script só é útil se essa API ficar fora do ar por muito tempo (o
 * cache então some) ou pra gerar um snapshot manual pontual.
 *
 * Roda localmente. O MySQL de produção não é exposto direto na internet —
 * conecta através de um túnel SSH (PuTTY: Connection > SSH > Tunnels,
 * Source port 3307 -> Destination 127.0.0.1:3306), que precisa estar aberto
 * antes de rodar este script. As fotos continuam vindo da pasta local do
 * mandados-system (só existem no disco de quem processa os PDFs; o
 * mandados-system em produção recebe cópia delas por scp separadamente,
 * mas esse script sempre lê da origem local). O resultado (JSON + fotos
 * copiadas) é versionado no git e chega em produção pelo `git pull` normal.
 *
 * Uso:
 *   php bin/import-procurados.php --user=mandados_app --password=SENHA
 *   php bin/import-procurados.php --user=... --password=... --host=127.0.0.1 --port=3307 --database=mandados_mgr --photos="C:\outro\caminho\mgr"
 *
 * Não copia CPF nem RG para o JSON público — esses dados nunca saem do MySQL de origem.
 */

$options = getopt('', ['host:', 'port:', 'user:', 'password:', 'database:', 'photos:']);

$dbHost = $options['host'] ?? '127.0.0.1';
$dbPort = $options['port'] ?? '3307'; // porta local do túnel SSH -> 3306 do servidor
$dbUser = $options['user'] ?? null;
$dbPassword = $options['password'] ?? null;
$dbName = $options['database'] ?? 'mandados_mgr';
$photosDir = $options['photos'] ?? 'E:\\Workspace\\S\\Stratelli\\2025\\Automatizador\\mandados-system\\uploads\\procurados\\mgr';

if ($dbUser === null || $dbPassword === null) {
    fwrite(STDERR, "Uso: php bin/import-procurados.php --user=SEU_USUARIO --password=SUA_SENHA\n");
    fwrite(STDERR, "Opcionais: --host=127.0.0.1 --port=3307 --database=mandados_mgr --photos=CAMINHO\n");
    fwrite(STDERR, "Lembre de abrir o túnel SSH (PuTTY) antes de rodar — sem ele, a conexão recusa (ECONNREFUSED).\n");
    exit(1);
}
if (!is_dir($photosDir)) {
    fwrite(STDERR, "Pasta de fotos não encontrada: {$photosDir}\n");
    exit(1);
}

require_once __DIR__ . '/../includes/procurados-transform.php';

$outputJson = __DIR__ . '/../data/procurados.json';
$outputPhotosDir = __DIR__ . '/../assets/img/procurados';
if (!is_dir($outputPhotosDir)) {
    mkdir($outputPhotosDir, 0775, true);
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPassword
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Falha ao conectar no MySQL ({$dbHost}:{$dbPort}): " . $e->getMessage() . "\n");
    fwrite(STDERR, "Confirme que o túnel SSH (PuTTY) está aberto e as credenciais estão certas.\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->query("
    SELECT
        m.MANDADO_NR, m.PESSOA_NOME, m.PESSOA_RJI, m.MANDADO_SITUACAO,
        m.CLASSIFIC_CRIMINOSO, m.TIPIFICACAO_PENAL, m.ORGAO_JUDICIAL,
        m.ESPECIE_PRISAO, m.MANDADO_DT_EXPED,
        p.PESSOA_DT_NASC, p.PESSOA_FOTO
    FROM BD_MANDADOS m
    LEFT JOIN PESSOA p ON p.PESSOA_RJI = m.PESSOA_RJI
    WHERE m.MANDADO_SITUACAO = 'Pendente de Cumprimento'
    ORDER BY m.MANDADO_DT_EXPED DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$alcunhaStmt = $pdo->prepare("SELECT ALCUNHA FROM PESSOA_ALCUNHA WHERE PESSOA_RJI = ? LIMIT 5");
$enderecoStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TP_TIT_NOME_NR_LOGRADOURO, ''), TP_TIT_NOME_LOGRADOURO) as ENDERECO FROM PESSOA_ENDERECO WHERE PESSOA_RJI = ? LIMIT 5");

$registros = [];
$comFoto = 0;
$semFoto = 0;

foreach ($rows as $row) {
    $rji = $row['PESSOA_RJI'];

    $alcunhas = [];
    $enderecos = [];
    if ($rji) {
        $alcunhaStmt->execute([$rji]);
        $alcunhas = $alcunhaStmt->fetchAll(PDO::FETCH_COLUMN);
        $enderecoStmt->execute([$rji]);
        $enderecos = $enderecoStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $foto = null;
    $fotoRel = $row['PESSOA_FOTO'] ?? null;
    if ($fotoRel) {
        $arquivo = basename(str_replace('\\', '/', $fotoRel));
        $origem = $photosDir . DIRECTORY_SEPARATOR . $arquivo;
        if (is_file($origem)) {
            $destino = $outputPhotosDir . DIRECTORY_SEPARATOR . $arquivo;
            if (!is_file($destino)) {
                copy($origem, $destino);
            }
            $foto = 'assets/img/procurados/' . $arquivo;
            $comFoto++;
        } else {
            $semFoto++;
        }
    } else {
        $semFoto++;
    }

    $registros[] = procuradosMontarRegistro([
        'mandado_nr' => $row['MANDADO_NR'],
        'pessoa_nome' => $row['PESSOA_NOME'],
        'classific_criminoso' => $row['CLASSIFIC_CRIMINOSO'] ?? null,
        'tipificacao_penal' => $row['TIPIFICACAO_PENAL'] ?? null,
        'orgao_judicial' => $row['ORGAO_JUDICIAL'] ?? null,
        'especie_prisao' => $row['ESPECIE_PRISAO'] ?? null,
        'mandado_dt_exped' => $row['MANDADO_DT_EXPED'] ?? null,
        'pessoa_dt_nasc' => $row['PESSOA_DT_NASC'] ?? null,
        'foto' => $foto,
        'alcunhas' => $alcunhas,
        'enderecos' => $enderecos,
    ]);
}

file_put_contents(
    $outputJson,
    json_encode($registros, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

$total = count($registros);
echo "Importados: {$total} mandados em aberto\n";
echo "Com foto: {$comFoto}\n";
echo "Sem foto (placeholder): {$semFoto}\n";
echo "Gravado em: {$outputJson}\n";
