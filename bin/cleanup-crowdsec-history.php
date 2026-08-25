<?php
declare(strict_types=1);

/**
 * Remove do historico cumulativo do CrowdSec (data/crowdsec-history.json)
 * os alertas do cenario "update" que ja tinham sido contados antes do
 * CrowdSecService passar a ignora-los (ver IGNORED_SCENARIOS). So precisa
 * rodar uma vez, depois do deploy do filtro — daqui pra frente esses
 * alertas nem entram no arquivo.
 *
 * Ajusta com precisao: scenario_totals (a chave inteira e' removida) e
 * total_alerts (decrementado na mesma contagem — todo alerta soma 1 nos
 * dois lugares). Tambem remove as entradas de recent_events e desconta
 * eventsCount/hourly_totals correspondentes, mas isso so cobre os ultimos
 * 500 eventos ainda guardados na janela (MAX_RECENT_EVENTS) — alertas mais
 * antigos que ja tinham saido da lista continuam contados em total_events,
 * entao esse numero pode seguir levemente inflado. unique_ips/country_totals
 * nao sao tocados: sao sets por IP/pais, nao por cenario, entao um IP que
 * ja tinha um ataque real registrado nao deve ser removido de la so porque
 * tambem gerou eventos "update".
 *
 * Uso: php bin/cleanup-crowdsec-history.php
 */

if (PHP_SAPI !== 'cli') {
    exit('Este script só pode ser executado via linha de comando (CLI).' . PHP_EOL);
}

const IGNORED_SCENARIOS = ['update'];

function normalizeScenario(string $scenario): string
{
    $slug = str_starts_with($scenario, 'crowdsecurity/') ? substr($scenario, strlen('crowdsecurity/')) : $scenario;

    return strtolower($slug);
}

function hourBucket(string $createdAt): ?string
{
    if ($createdAt === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($createdAt);
    } catch (Throwable) {
        return null;
    }

    return $date->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:00');
}

$file = __DIR__ . '/../data/crowdsec-history.json';

if (!is_file($file)) {
    exit("Arquivo não encontrado: {$file}" . PHP_EOL);
}

$history = json_decode((string) file_get_contents($file), true);
if (!is_array($history)) {
    exit("Arquivo inválido/corrompido: {$file}" . PHP_EOL);
}

$removedAlerts = 0;
foreach ($history['scenario_totals'] ?? [] as $scenario => $count) {
    if (in_array(normalizeScenario((string) $scenario), IGNORED_SCENARIOS, true)) {
        $removedAlerts += (int) $count;
        unset($history['scenario_totals'][$scenario]);
    }
}

$removedEvents = 0;
$recentEvents = $history['recent_events'] ?? [];
$keptEvents = [];
foreach ($recentEvents as $event) {
    if (!is_array($event) || !in_array(normalizeScenario((string) ($event['scenario'] ?? '')), IGNORED_SCENARIOS, true)) {
        $keptEvents[] = $event;
        continue;
    }

    $removedEvents += (int) ($event['eventsCount'] ?? 0);

    $bucket = hourBucket((string) ($event['createdAt'] ?? ''));
    if ($bucket !== null && isset($history['hourly_totals'][$bucket])) {
        $history['hourly_totals'][$bucket] -= 1;
        if ($history['hourly_totals'][$bucket] <= 0) {
            unset($history['hourly_totals'][$bucket]);
        }
    }
}
$history['recent_events'] = $keptEvents;

$history['total_alerts'] = max(0, (int) ($history['total_alerts'] ?? 0) - $removedAlerts);
$history['total_events'] = max(0, (int) ($history['total_events'] ?? 0) - $removedEvents);

file_put_contents($file, json_encode($history));

echo "OK — {$removedAlerts} alerta(s) 'update' removido(s) de scenario_totals/total_alerts." . PHP_EOL;
echo "{$removedEvents} evento(s) 'update' removido(s) de recent_events/total_events/hourly_totals (só cobre a janela dos últimos " . count($recentEvents) . " eventos que ainda estavam guardados)." . PHP_EOL;
echo "total_events pode continuar levemente inflado por alertas 'update' antigos que já tinham saído dessa janela antes deste cleanup." . PHP_EOL;
