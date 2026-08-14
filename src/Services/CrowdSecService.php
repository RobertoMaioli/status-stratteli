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

    public function __construct(
        private readonly string $lapiUrl,
        private readonly string $machineId,
        private readonly string $password,
        private readonly string $tokenFile,
        private readonly string $alertsCacheFile,
        private readonly int $cacheTtlSeconds = 20
    ) {
    }

    /**
     * Alertas processados e agregados, prontos pro frontend (mapa, graficos,
     * tabela) — ver api/security-threats.php.
     *
     * @return array{
     *     updatedAt: string,
     *     totals: array{alerts: int, events: int, countries: int, uniqueIps: int},
     *     events: array<int, array{ip: string, country: string, lat: float|null, lng: float|null, scenario: string, scenarioLabel: string, asName: string, createdAt: string, eventsCount: int, banDuration: string}>,
     *     byScenario: array<int, array{scenario: string, label: string, count: int}>,
     *     byCountry: array<int, array{country: string, count: int}>,
     *     timeseries: array<int, array{bucket: string, count: int}>
     * }
     */
    public function getThreatSummary(): array
    {
        $alerts = $this->getAlerts();

        $events = [];
        $scenarioCounts = [];
        $countryCounts = [];
        $hourlyCounts = [];
        $totalEvents = 0;
        $uniqueIps = [];

        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $source = is_array($alert['source'] ?? null) ? $alert['source'] : [];
            $scenario = (string) ($alert['scenario'] ?? 'unknown');
            $eventsCount = (int) ($alert['events_count'] ?? 0);
            $createdAt = (string) ($alert['created_at'] ?? '');
            $ip = (string) ($source['ip'] ?? '');
            $country = (string) ($source['cn'] ?? '');
            $lat = isset($source['latitude']) && is_numeric($source['latitude']) ? (float) $source['latitude'] : null;
            $lng = isset($source['longitude']) && is_numeric($source['longitude']) ? (float) $source['longitude'] : null;

            $events[] = [
                'ip' => $ip,
                'country' => $country,
                'lat' => $lat,
                'lng' => $lng,
                'scenario' => $scenario,
                'scenarioLabel' => $this->friendlyScenario($scenario),
                'asName' => (string) ($source['as_name'] ?? ''),
                'createdAt' => $createdAt,
                'eventsCount' => $eventsCount,
                'banDuration' => $this->firstBanDuration($alert['decisions'] ?? null),
            ];

            $totalEvents += $eventsCount;
            if ($ip !== '') {
                $uniqueIps[$ip] = true;
            }

            $scenarioCounts[$scenario] = ($scenarioCounts[$scenario] ?? 0) + 1;
            if ($country !== '') {
                $countryCounts[$country] = ($countryCounts[$country] ?? 0) + 1;
            }

            $hourBucket = $this->hourBucket($createdAt);
            if ($hourBucket !== null) {
                $hourlyCounts[$hourBucket] = ($hourlyCounts[$hourBucket] ?? 0) + 1;
            }
        }

        // Mais recentes primeiro, pra tabela de eventos.
        usort($events, static fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));

        arsort($scenarioCounts);
        $byScenario = [];
        foreach ($scenarioCounts as $scenario => $count) {
            $byScenario[] = ['scenario' => $scenario, 'label' => $this->friendlyScenario($scenario), 'count' => $count];
        }

        arsort($countryCounts);
        $byCountry = [];
        foreach ($countryCounts as $country => $count) {
            $byCountry[] = ['country' => $country, 'count' => $count];
        }

        ksort($hourlyCounts);
        $timeseries = [];
        foreach ($hourlyCounts as $bucket => $count) {
            $timeseries[] = ['bucket' => $bucket, 'count' => $count];
        }

        return [
            'updatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM),
            'totals' => [
                'alerts' => count($events),
                'events' => $totalEvents,
                'countries' => count($countryCounts),
                'uniqueIps' => count($uniqueIps),
            ],
            'events' => $events,
            'byScenario' => $byScenario,
            'byCountry' => $byCountry,
            'timeseries' => $timeseries,
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
     * Lista bruta de alertas, cacheada em arquivo por `cacheTtlSeconds` —
     * assim a LAPI so e consultada de fato quando o cache expira, nao a
     * cada requisicao do frontend (que pode vir de varios usuarios com o
     * dashboard aberto ao mesmo tempo).
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

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAlerts(): array
    {
        $token = $this->getToken();
        $result = $this->request('GET', '/v1/alerts', null, $token);

        if ($result['status'] === 401) {
            // Token pode ter expirado entre a checagem do cache e o uso
            // real — forca um novo login e tenta mais uma vez antes de
            // desistir.
            $token = $this->getToken(true);
            $result = $this->request('GET', '/v1/alerts', null, $token);
        }

        if ($result['status'] !== 200) {
            throw new \RuntimeException("LAPI do CrowdSec retornou HTTP {$result['status']} em /v1/alerts");
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
