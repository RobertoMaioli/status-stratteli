<?php
declare(strict_types=1);

/**
 * Importa os mandados em aberto (Maringá/PR) da base do mandados-system
 * (SQLite, mantida localmente por outra ferramenta) para
 * assets/data/procurados.json, que o painel de procurados lê em produção.
 *
 * Roda só localmente, na máquina onde o mandados-system existe — o SQLite e
 * as fotos não existem no servidor. O resultado (JSON + fotos copiadas) é
 * versionado no git e chega em produção pelo `git pull` normal do projeto.
 *
 * Uso:
 *   php bin/import-procurados.php
 *   php bin/import-procurados.php --db="C:\outro\caminho\mandados_mgr.db" --photos="C:\outro\caminho\mgr"
 *
 * Não copia CPF nem RG para o JSON público — esses dados nunca saem do SQLite de origem.
 */

$options = getopt('', ['db:', 'photos:']);

$dbPath = $options['db'] ?? 'E:\\Workspace\\S\\Stratelli\\2025\\Automatizador\\mandados-system\\database\\mandados_mgr.db';
$photosDir = $options['photos'] ?? 'E:\\Workspace\\S\\Stratelli\\2025\\Automatizador\\mandados-system\\uploads\\procurados\\mgr';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Banco SQLite não encontrado: {$dbPath}\n");
    exit(1);
}
if (!is_dir($photosDir)) {
    fwrite(STDERR, "Pasta de fotos não encontrada: {$photosDir}\n");
    exit(1);
}

$outputJson = __DIR__ . '/../assets/data/procurados.json';
$outputPhotosDir = __DIR__ . '/../assets/img/procurados';
if (!is_dir($outputPhotosDir)) {
    mkdir($outputPhotosDir, 0775, true);
}

// Mapa "código de classificação (CLASSIFIC_CRIMINOSO)" -> nível de periculosidade,
// definido pelo cliente (tabela editorial fornecida por eles, não um critério nosso).
const RISCO_POR_CODIGO = [
    '01' => 'sem',       // Devedor de Pensão Alimentícia — Sem Periculosidade
    '03' => 'media',     // Agressor Doméstico — Média
    '04' => 'baixa',     // Furtador, Receptador e Similares — Baixa
    '05' => 'media',     // Traficante — Média
    '06' => 'alta',      // Roubador, Porte Ilegal de Arma ou Furtador por Destruição — Alta
    '07' => 'alta',      // Homicida/Latrocida — Alta
    '08' => 'alta',      // Agressor Sexual — Alta
    '09' => 'altissima', // Múltiplas Condenações — Altíssima
    '10' => 'altissima', // Criminoso de ORCRIM — Altíssima
    '11' => 'baixa',     // Condutor de Veículo Sob Efeito de Álcool — Baixa
    '12' => 'media',     // Ameaça, Dano, Desacato e Similares — Média
];

function classificacaoParaRisco(?string $classific): string
{
    if ($classific !== null && preg_match('/^\s*(\d{2})\./', $classific, $m)) {
        return RISCO_POR_CODIGO[$m[1]] ?? 'sem';
    }
    return 'sem';
}

function limparCategoria(?string $classific): string
{
    if ($classific === null || trim($classific) === '' || trim($classific) === '?') {
        return 'Não classificado';
    }
    $limpo = preg_replace('/^\s*\d{2}\.\s*/', '', $classific);
    return trim((string) $limpo);
}

// Extrai a cidade do fim de uma string de endereço tipo
// "RUA X, 35, JD. TAL, Sarandi - PR" (às vezes com mais de um endereço
// concatenado) — pega a última ocorrência de "Nome da cidade - UF".
function extrairCidade(?string $endereco): ?string
{
    if ($endereco === null || trim($endereco) === '') {
        return null;
    }
    if (preg_match_all('/([A-Za-zÀ-ÿ0-9 .\'-]+?)\s*-\s*[A-Z]{2}(?:\s|$)/u', $endereco, $matches)) {
        $cidade = trim(end($matches[1]));
        $cidade = preg_replace('/^(CEP\s*[\d.-]*\s*)/i', '', $cidade);
        $cidade = trim((string) $cidade);
        if ($cidade === '') {
            return null;
        }
        // Título: primeira letra de cada palavra maiúscula, resto minúsculo.
        return mb_convert_case(mb_strtolower($cidade, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
    return null;
}

function calcularIdade(?string $dataNascBr): ?int
{
    if ($dataNascBr === null || trim($dataNascBr) === '') {
        return null;
    }
    $data = DateTime::createFromFormat('d/m/Y', trim($dataNascBr));
    if (!$data) {
        return null;
    }
    $idade = $data->diff(new DateTime())->y;
    return ($idade > 0 && $idade < 110) ? $idade : null;
}

function formatarDataBr(?string $dataBr): ?string
{
    if ($dataBr === null || trim($dataBr) === '') {
        return null;
    }
    return trim($dataBr);
}

$pdo = new PDO('sqlite:' . $dbPath);
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
$enderecoStmt = $pdo->prepare("SELECT TP_TIT_NOME_NR_LOGRADOURO FROM PESSOA_ENDERECO WHERE PESSOA_RJI = ? LIMIT 5");

$registros = [];
$comFoto = 0;
$semFoto = 0;

foreach ($rows as $row) {
    $rji = $row['PESSOA_RJI'];

    $vulgo = 'Sem alcunha registrada';
    if ($rji) {
        $alcunhaStmt->execute([$rji]);
        foreach ($alcunhaStmt->fetchAll(PDO::FETCH_COLUMN) as $a) {
            $a = trim((string) $a);
            $ehPlaceholder = $a === '' || preg_match('/INFORM|CONSTA/ui', $a) === 1;
            if (!$ehPlaceholder) {
                $vulgo = 'Vulgo "' . $a . '"';
                break;
            }
        }
    }

    $cidade = null;
    if ($rji) {
        $enderecoStmt->execute([$rji]);
        foreach ($enderecoStmt->fetchAll(PDO::FETCH_COLUMN) as $end) {
            $cidade = extrairCidade((string) $end);
            if ($cidade !== null) {
                break;
            }
        }
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

    $categoria = limparCategoria($row['CLASSIFIC_CRIMINOSO'] ?? null);

    $tags = [];
    $tipificacaoBruta = trim((string) ($row['TIPIFICACAO_PENAL'] ?? ''));
    foreach (explode('|', $tipificacaoBruta) as $parte) {
        $parte = trim($parte);
        if ($parte === '') {
            continue;
        }
        $jaExiste = false;
        foreach ($tags as $existente) {
            if (strcasecmp($existente, $parte) === 0) {
                $jaExiste = true;
                break;
            }
        }
        if (!$jaExiste) {
            $tags[] = $parte;
        }
        if (count($tags) >= 3) {
            break;
        }
    }
    if ($categoria !== 'Não classificado' && !in_array($categoria, $tags, true) && count($tags) < 4) {
        $tags[] = $categoria;
    }
    if (empty($tags)) {
        $tags[] = 'Sem tipificação registrada';
    }

    $registros[] = [
        'id' => $row['MANDADO_NR'],
        'nome' => trim((string) $row['PESSOA_NOME']),
        'vulgo' => $vulgo,
        'foto' => $foto,
        'risco' => classificacaoParaRisco($row['CLASSIFIC_CRIMINOSO'] ?? null),
        'categoria' => $categoria,
        'tags' => $tags,
        'cidade' => $cidade ?? 'Não informado',
        'situacao' => 'EM ABERTO',
        'idade' => calcularIdade($row['PESSOA_DT_NASC'] ?? null),
        'mandado' => $row['MANDADO_NR'],
        'vara' => trim((string) ($row['ORGAO_JUDICIAL'] ?? '')) ?: null,
        'especie_prisao' => trim((string) ($row['ESPECIE_PRISAO'] ?? '')) ?: null,
        'expedicao' => formatarDataBr($row['MANDADO_DT_EXPED'] ?? null),
    ];
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
