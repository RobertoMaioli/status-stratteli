<?php
declare(strict_types=1);

namespace DashStatus\Services;

class CrowdSecService
{
    /**
     * Traducao amigavel pros cenarios mais comuns do CrowdSec (prefixo
     * "crowdsecurity/" ja removido antes de consultar este mapa). Cenarios
     * fora dessa lista caem no fallback de friendlyScenario() (slug
     * "http-generic-bf" vira "Http Generic Bf"), pra nunca quebrar a
     * exibicao quando aparecer um cenario novo/nao mapeado.
     */
    private const SCENARIO_LABELS = [
        'http-probing' => 'Varredura HTTP',
        'http-crawl-non_statics' => 'Varredura de conteúdo',
        'http-sqli-probing' => 'Tentativa de SQL Injection',
        'http-xss-probing' => 'Tentativa de XSS',
        'http-path-traversal-probing' => 'Tentativa de Path Traversal',
        'http-generic-bf' => 'Força bruta HTTP',
        'http-wordpress_bf' => 'Força bruta WordPress',
        'http-bad-user-agent' => 'User-Agent suspeito',
        'http-open-proxy' => 'Proxy aberto detectado',
        'http-sensitive-files' => 'Acesso a arquivo sensível',
        'ssh-bf' => 'Força bruta SSH',
        'ssh-slow-bf' => 'Força bruta SSH (lenta)',
        'mysql-bf' => 'Força bruta MySQL',
        'ftp-bf' => 'Força bruta FTP',
        'rdp-bf' => 'Força bruta RDP',
        'port-scan' => 'Varredura de portas',
    ];

    /** Quantos eventos recentes manter na tabela (historico cumulativo). */
    private const MAX_RECENT_EVENTS = 500;

    /**
     * Cenarios que NAO sao ataques de verdade e por isso ficam de fora do
     * mapa e do historico cumulativo. "update" e o que o aaPanel gera
     * quando ele so reconfirma/reaplica o ban de um IP que ja estava
     * banido (nao e uma deteccao nova) — sem esse filtro, isso inflava o
     * grafico "por tipo de ataque" com milhares de eventos sem nenhum IP
     * novo, dando a falsa impressao de um ataque em massa.
     */
    private const IGNORED_SCENARIOS = ['update'];

    /** Buckets horarios mais antigos que isso somem do grafico de linha do
     *  tempo (os totais cumulativos por cenario/pais/geral NAO dependem
     *  disso — continuam contando pra sempre). */
    private const TIMESERIES_MAX_AGE_DAYS = 30;

    public function __construct(
        private readonly string $lapiUrl,
        private readonly string $machineId,
        private readonly string $password,
        private readonly string $tokenFile,
        private readonly string $alertsCacheFile,
        private readonly string $historyFile,
        private readonly int $cacheTtlSeconds = 20
    ) {
    }

    /**
     * Dados prontos pro frontend (mapa, graficos, tabela) — ver
     * api/security-threats.php. O mapa (`activeThreats`) reflete só ameaça
     * ativa agora (decisao/ban ainda nao expirou); todo o resto
     * (`totals`, `events`, `byScenario`, `byCountry`, `timeseries`) e
     * historico cumulativo — nunca reseta so porque um ban expirou, ver
     * updateHistory().
     *
     * @return array{
     *     updatedAt: string,
     *     totals: array{alerts: int, events: int, countries: int, uniqueIps: int},
     *     activeThreats: array<int, array{id: int, ip: string, country: string, lat: float|null, lng: float|null, scenario: string, scenarioLabel: string, asName: string, createdAt: string, eventsCount: int, banDuration: string}>,
     *     events: array<int, array{id: int, ip: string, country: string, lat: float|null, lng: float|null, scenario: string, scenarioLabel: string, asName: string, createdAt: string, eventsCount: int, banDuration: string}>,
     *     byScenario: array<int, array{scenario: string, label: string, count: int}>,
     *     byCountry: array<int, array{country: string, count: int}>,
     *     timeseries: array<int, array{bucket: string, count: int}>
     * }
     */
    public function getThreatSummary(): array
    {
        $activeAlerts = $this->getAlerts();
        $activeThreats = array_values(array_map(
            fn (array $alert): array => $this->alertToEvent($alert),
            array_filter($activeAlerts, fn (mixed $alert): bool => is_array($alert) && !$this->isIgnoredScenario((string) ($alert['scenario'] ?? '')))
        ));

        $history = $this->loadHistory();

        $scenarioCounts = $history['scenario_totals'];
        arsort($scenarioCounts);
        $byScenario = [];
        foreach ($scenarioCounts as $scenario => $count) {
            $byScenario[] = ['scenario' => $scenario, 'label' => $this->friendlyScenario($scenario), 'count' => $count];
        }

        $countryCounts = $history['country_totals'];
        arsort($countryCounts);
        $byCountry = [];
        foreach ($countryCounts as $country => $count) {
            $byCountry[] = ['country' => $country, 'count' => $count];
        }

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))
            ->modify('-' . self::TIMESERIES_MAX_AGE_DAYS . ' days')
            ->format('Y-m-d\TH:00');
        $hourlyCounts = array_filter(
            $history['hourly_totals'],
            static fn (string $bucket): bool => $bucket >= $cutoff,
            ARRAY_FILTER_USE_KEY
        );
        ksort($hourlyCounts);
        $timeseries = [];
        foreach ($hourlyCounts as $bucket => $count) {
            $timeseries[] = ['bucket' => $bucket, 'count' => $count];
        }

        return [
            'updatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM),
            'totals' => [
                'alerts' => $history['total_alerts'],
                'events' => $history['total_events'],
                'countries' => count($history['country_totals']),
                'uniqueIps' => count($history['unique_ips']),
            ],
            'activeThreats' => $activeThreats,
            'events' => $history['recent_events'],
            'byScenario' => $byScenario,
            'byCountry' => $byCountry,
            'timeseries' => $timeseries,
        ];
    }

    /**
     * @param array<string, mixed> $alert
     * @return array{id: int, ip: string, country: string, lat: float|null, lng: float|null, scenario: string, scenarioLabel: string, asName: string, createdAt: string, eventsCount: int, banDuration: string}
     */
    private function alertToEvent(array $alert): array
    {
        $source = is_array($alert['source'] ?? null) ? $alert['source'] : [];
        $scenario = (string) ($alert['scenario'] ?? 'unknown');
        $lat = isset($source['latitude']) && is_numeric($source['latitude']) ? (float) $source['latitude'] : null;
        $lng = isset($source['longitude']) && is_numeric($source['longitude']) ? (float) $source['longitude'] : null;

        return [
            'id' => (int) ($alert['id'] ?? 0),
            'ip' => (string) ($source['ip'] ?? ''),
            'country' => (string) ($source['cn'] ?? ''),
            'lat' => $lat,
            'lng' => $lng,
            'scenario' => $scenario,
            'scenarioLabel' => $this->friendlyScenario($scenario),
            'asName' => (string) ($source['as_name'] ?? ''),
            'createdAt' => (string) ($alert['created_at'] ?? ''),
            'eventsCount' => (int) ($alert['events_count'] ?? 0),
            'banDuration' => $this->firstBanDuration($alert['decisions'] ?? null),
        ];
    }

    /**
     * @param mixed $decisions
     */
    private function firstBanDuration(mixed $decisions): string
    {
        if (!is_array($decisions)) {
            return '';
        }

        foreach ($decisions as $decision) {
            if (is_array($decision) && !empty($decision['duration'])) {
                return (string) $decision['duration'];
            }
        }

        return '';
    }

    private function hourBucket(string $createdAt): ?string
    {
        if ($createdAt === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($createdAt);
        } catch (\Throwable) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:00');
    }

    private function isIgnoredScenario(string $scenario): bool
    {
        $slug = str_starts_with($scenario, 'crowdsecurity/') ? substr($scenario, strlen('crowdsecurity/')) : $scenario;

        return in_array(strtolower($slug), self::IGNORED_SCENARIOS, true);
    }

    private function friendlyScenario(string $scenario): string
    {
        $slug = str_starts_with($scenario, 'crowdsecurity/') ? substr($scenario, strlen('crowdsecurity/')) : $scenario;

        if (isset(self::SCENARIO_LABELS[$slug])) {
            return self::SCENARIO_LABELS[$slug];
        }

        $words = preg_split('/[-_]/', $slug) ?: [$slug];

        return implode(' ', array_map('ucfirst', array_filter($words, static fn (string $w): bool => $w !== '')));
    }

    /**
     * @return array{last_processed_id: int, total_alerts: int, total_events: int, unique_ips: array<string, bool>, country_totals: array<string, int>, scenario_totals: array<string, int>, hourly_totals: array<string, int>, recent_events: array<int, array<string, mixed>>}
     */
    private function loadHistory(): array
    {
        $defaults = [
            'last_processed_id' => 0,
            'total_alerts' => 0,
            'total_events' => 0,
            'unique_ips' => [],
            'country_totals' => [],
            'scenario_totals' => [],
            'hourly_totals' => [],
            'recent_events' => [],
        ];

        if (!is_file($this->historyFile)) {
            return $defaults;
        }

        $data = json_decode((string) file_get_contents($this->historyFile), true);

        return is_array($data) ? ($data + $defaults) : $defaults;
    }

    /**
     * Atualiza o historico cumulativo com alertas que ainda nao tinham sido
     * contados (id > last_processed_id — os IDs do CrowdSec sao sequenciais,
     * entao isso e suficiente pra nunca contar o mesmo alerta duas vezes,
     * mesmo ele aparecendo em varios polls seguidos enquanto o ban dele
     * continua ativo). So e chamado quando `fetchAlerts()` busca dado novo
     * de verdade na LAPI (nao a cada leitura do cache).
     *
     * @param array<int, array<string, mixed>> $freshAlerts
     */
    private function updateHistory(array $freshAlerts): void
    {
        $history = $this->loadHistory();
        $maxId = $history['last_processed_id'];
        $changed = false;

        foreach ($freshAlerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $id = (int) ($alert['id'] ?? 0);
            if ($id <= $history['last_processed_id']) {
                continue;
            }

            // Marca como processado mesmo ignorando, senao o mesmo alerta
            // "update" ficaria sendo reavaliado (e descartado) em todo poll
            // futuro enquanto o ban dele continuar ativo.
            $maxId = max($maxId, $id);
            $changed = true;

            if ($this->isIgnoredScenario((string) ($alert['scenario'] ?? ''))) {
                continue;
            }

            $event = $this->alertToEvent($alert);

            $history['total_alerts'] += 1;
            $history['total_events'] += $event['eventsCount'];
            if ($event['ip'] !== '') {
                $history['unique_ips'][$event['ip']] = true;
            }
            if ($event['country'] !== '') {
                $history['country_totals'][$event['country']] = ($history['country_totals'][$event['country']] ?? 0) + 1;
            }
            $history['scenario_totals'][$event['scenario']] = ($history['scenario_totals'][$event['scenario']] ?? 0) + 1;

            $hourBucket = $this->hourBucket($event['createdAt']);
            if ($hourBucket !== null) {
                $history['hourly_totals'][$hourBucket] = ($history['hourly_totals'][$hourBucket] ?? 0) + 1;
            }

            array_unshift($history['recent_events'], $event);
        }

        if (!$changed) {
            return;
        }

        $history['last_processed_id'] = $maxId;
        $history['recent_events'] = array_slice($history['recent_events'], 0, self::MAX_RECENT_EVENTS);

        file_put_contents($this->historyFile, json_encode($history));
    }

    /**
     * Lista bruta de alertas ATIVOS (has_active_decision=true), cacheada em
     * arquivo por `cacheTtlSeconds` — assim a LAPI so e consultada de fato
     * quando o cache expira, nao a cada requisicao do frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getAlerts(): array
    {
        $isFresh = is_file($this->alertsCacheFile)
            && (time() - filemtime($this->alertsCacheFile)) < $this->cacheTtlSeconds;

        if ($isFresh) {
            $cached = json_decode((string) file_get_contents($this->alertsCacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $alerts = $this->fetchAlerts();
        file_put_contents($this->alertsCacheFile, json_encode($alerts));
        $this->updateHistory($alerts);

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAlerts(): array
    {
        // has_active_decision=true: sem isso, /v1/alerts devolve todo o
        // historico que o CrowdSec ainda guarda no banco (a decisao/ban
        // expira, mas o registro do alerta continua la por dias, ate a
        // proxima poda interna) — o MAPA ficaria acumulando pontos de
        // ataques que ja nao estao mais banidos. O historico cumulativo
        // (totals/events/byScenario/byCountry/timeseries) não depende
        // desse filtro pra "nunca esquecer" — updateHistory() ja garante
        // isso lendo cada alerta pelo menos uma vez antes do ban expirar.
        $path = '/v1/alerts?has_active_decision=true';

        $token = $this->getToken();
        $result = $this->request('GET', $path, null, $token);

        if ($result['status'] === 401) {
            // Token pode ter expirado entre a checagem do cache e o uso
            // real — forca um novo login e tenta mais uma vez antes de
            // desistir.
            $token = $this->getToken(true);
            $result = $this->request('GET', $path, null, $token);
        }

        if ($result['status'] !== 200) {
            throw new \RuntimeException("LAPI do CrowdSec retornou HTTP {$result['status']} em {$path}");
        }

        return $result['body'];
    }

    /**
     * Token de sessao (~1h) cacheado em arquivo. Renova com 60s de folga
     * antes do vencimento real, pra nao correr risco de usar um token que
     * expira no meio da requisicao.
     */
    private function getToken(bool $forceRelogin = false): string
    {
        if (!$forceRelogin && is_file($this->tokenFile)) {
            $cached = json_decode((string) file_get_contents($this->tokenFile), true);
            if (is_array($cached) && !empty($cached['token']) && !empty($cached['expire'])) {
                $expireTs = strtotime((string) $cached['expire']);
                if ($expireTs !== false && $expireTs > time() + 60) {
                    return (string) $cached['token'];
                }
            }
        }

        return $this->login();
    }

    private function login(): string
    {
        $result = $this->request('POST', '/v1/watchers/login', [
            'machine_id' => $this->machineId,
            'password' => $this->password,
        ]);

        if ($result['status'] !== 200 || empty($result['body']['token'])) {
            $msg = (string) ($result['body']['message'] ?? 'resposta invalida');
            throw new \RuntimeException("Falha ao autenticar na LAPI do CrowdSec (HTTP {$result['status']}): {$msg}");
        }

        $token = (string) $result['body']['token'];
        $expire = (string) ($result['body']['expire'] ?? '');

        file_put_contents($this->tokenFile, json_encode(['token' => $token, 'expire' => $expire]));

        return $token;
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{status: int, body: mixed}
     */
    private function request(string $method, string $path, ?array $jsonBody = null, ?string $bearerToken = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($bearerToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            // Sem User-Agent, a propria protecao do CrowdSec contra
            // "bad user agent" rejeita a chamada — mesmo motivo documentado
            // em AapanelService::request() pro nginx na frente do aaPanel.
            CURLOPT_USERAGENT => 'crowdsec-dashboard/1.0',
        ];

        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
        }

        $ch = curl_init(rtrim($this->lapiUrl, '/') . $path);
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Falha ao conectar na LAPI do CrowdSec ({$path}): {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $response, true);

        return ['status' => $status, 'body' => $data ?? []];
    }
}
