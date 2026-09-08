<?php
declare(strict_types=1);

/**
 * Regras de transformação do Painel de Procurados — compartilhadas entre
 * includes/procurados-source.php (consumo ao vivo da API do mandados-system)
 * e bin/import-procurados.php (gerador manual do JSON, mantido como
 * alternativa offline). Nunca inclua CPF nem RG nos registros produzidos
 * aqui — esses dados nunca saem do banco de origem.
 */

// Mapa "código de classificação (CLASSIFIC_CRIMINOSO)" -> nível de
// periculosidade, definido pelo cliente (tabela editorial fornecida por
// eles, não um critério nosso).
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

// Descrição oficial de cada código — usada pra normalizar a categoria
// exibida/filtrada, já que o texto cru em CLASSIFIC_CRIMINOSO tem variações
// de grafia (ex: registros antigos com "04. furtador..." em minúsculo) que,
// sem essa normalização, viram categorias diferentes nos filtros do painel
// mesmo sendo o mesmo código.
const CATEGORIA_POR_CODIGO = [
    '01' => 'Devedor de Pensão Alimentícia',
    '03' => 'Agressor Doméstico',
    '04' => 'Furtador, Receptador e Similares',
    '05' => 'Traficante',
    '06' => 'Roubador, Porte Ilegal de Arma ou Furtador por Destruição',
    '07' => 'Homicida/Latrocida',
    '08' => 'Agressor Sexual',
    '09' => 'Múltiplas Condenações',
    '10' => 'Criminoso de ORCRIM',
    '11' => 'Condutor de Veículo Sob Efeito de Álcool',
    '12' => 'Ameaça, Dano, Desacato e Similares',
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
    if (preg_match('/^\s*(\d{2})\./', $classific, $m) && isset(CATEGORIA_POR_CODIGO[$m[1]])) {
        return CATEGORIA_POR_CODIGO[$m[1]];
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

/**
 * Monta um registro no formato que o Painel de Procurados consome, a partir
 * de um item cru vindo de /api/publico/procurados (mandados-system) ou de
 * uma linha equivalente lida direto do MySQL (bin/import-procurados.php).
 *
 * Campos esperados em $item: mandado_nr, pessoa_nome, classific_criminoso,
 * tipificacao_penal, orgao_judicial, especie_prisao, mandado_dt_exped,
 * pessoa_dt_nasc, foto_url (ou foto), alcunhas[], enderecos[].
 */
function procuradosMontarRegistro(array $item): array
{
    $vulgo = 'Sem alcunha registrada';
    foreach ($item['alcunhas'] ?? [] as $a) {
        $a = trim((string) $a);
        $ehPlaceholder = $a === '' || preg_match('/INFORM|CONSTA/ui', $a) === 1;
        if (!$ehPlaceholder) {
            $vulgo = 'Vulgo "' . $a . '"';
            break;
        }
    }

    $cidade = null;
    foreach ($item['enderecos'] ?? [] as $end) {
        $cidade = extrairCidade((string) $end);
        if ($cidade !== null) {
            break;
        }
    }

    $classific = $item['classific_criminoso'] ?? null;
    $categoria = limparCategoria($classific);

    $tags = [];
    $tipificacaoBruta = trim((string) ($item['tipificacao_penal'] ?? ''));
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

    return [
        'id' => $item['mandado_nr'],
        'nome' => trim((string) $item['pessoa_nome']),
        'vulgo' => $vulgo,
        'foto' => $item['foto_url'] ?? $item['foto'] ?? null,
        'risco' => classificacaoParaRisco($classific),
        'categoria' => $categoria,
        'tags' => $tags,
        'cidade' => $cidade ?? 'Não informado',
        'situacao' => 'EM ABERTO',
        'idade' => calcularIdade($item['pessoa_dt_nasc'] ?? null),
        'mandado' => $item['mandado_nr'],
        'vara' => trim((string) ($item['orgao_judicial'] ?? '')) ?: null,
        'especie_prisao' => trim((string) ($item['especie_prisao'] ?? '')) ?: null,
        'expedicao' => formatarDataBr($item['mandado_dt_exped'] ?? null),
    ];
}
