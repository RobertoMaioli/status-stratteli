<?php
declare(strict_types=1);

namespace DashStatus\Services;

class AapanelService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $diskPath,
        private readonly bool $verifySsl,
        private readonly string $cacheFile,
        private readonly int $cacheTtlSeconds = 4,
        private readonly string $securityEntrance = '',
        private readonly string $securityCacheFile = '',
        private readonly int $securityCacheTtlSeconds = 45
    ) {
    }

    /**
     * Modulo de seguranca (/v2/safecloud) — confirmado por teste manual que
     * a mesma assinatura do Open API (api_key) autentica esse endpoint,
     * apesar de ser uma rota interna do painel (nao documentada como Open
     * API oficial). O prefixo `security_entrance` foi capturado direto do
     * DevTools do navegador logado no aaPanel.
     *
     * @return array{
     *     score: int,
     *     level: string,
     *     levelDescription: string,
     *     riskCount: int,
     *     protectDays: int,
     *     riskScanTime: string,
     *     severity: array{high: int, medium: int, low: int},
     *     events: array<int, array{severity: string, description: string, time: int}>
     * }
     */
    public function getSecuritySummary(): array
    {
        $raw = $this->loadSecurityRaw();

        $overview = $raw['overview']['message'] ?? [];
        $alarmTrend = $raw['alarmTrend']['message'] ?? [];
        $events = $raw['events']['message']['events'] ?? [];

        return [
            'score' => (int) ($overview['score'] ?? 0),
            'level' => (string) ($overview['level'] ?? ''),
            'levelDescription' => (string) ($overview['level_description'] ?? ''),
            'riskCount' => (int) ($overview['risk_count'] ?? 0),
            'protectDays' => (int) ($overview['protect_days'] ?? 0),
            'riskScanTime' => $this->formatBrDateTime((string) ($overview['risk_scan_time'] ?? '')),
            'severity' => [
                'high' => (int) ($alarmTrend['high_risk'] ?? 0),
                'medium' => (int) ($alarmTrend['medium_risk'] ?? 0),
                'low' => (int) ($alarmTrend['low_risk'] ?? 0),
            ],
            'events' => array_map(static function (array $event): array {
                // aaPanel usa 1=baixo, 2=medio, 3=alto (visto na amostra real:
                // as contagens de level 1/2 batem exatamente com low_risk/medium_risk).
                $level = (int) ($event['level'] ?? 2);
                $severity = $level >= 3 ? 'high' : ($level <= 1 ? 'low' : 'medium');

                return [
                    'severity' => $severity,
                    'description' => (string) ($event['description'] ?? ''),
                    'time' => (int) ($event['time'] ?? 0),
                ];
            }, is_array($events) ? $events : []),
        ];
    }

    /**
     * O aaPanel manda risk_scan_time como timestamp (segundos ou ms) ou como
     * string de data — normaliza pro formato brasileiro dd/mm/aaaa hh:mm.
     * Se nao conseguir interpretar, devolve o valor original sem quebrar.
     */
    private function formatBrDateTime(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (ctype_digit($value)) {
            $timestamp = strlen($value) >= 13 ? intdiv((int) $value, 1000) : (int) $value;
        } else {
            $parsed = strtotime($value);
            $timestamp = $parsed !== false ? $parsed : null;
        }

        if (empty($timestamp)) {
            return $value;
        }

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))
            ->format('d/m/Y H:i');
    }

    /**
     * Resposta bruta combinada do modulo de seguranca — usada so no modo
     * debug (api/host-status.php?debug=1) pra calibrar getSecuritySummary()
     * caso o aaPanel mude o formato de resposta.
     *
     * @return array{overview: array<string, mixed>, alarmTrend: array<string, mixed>, events: array<string, mixed>}
     */
    public function getSecurityRaw(): array
    {
        return $this->loadSecurityRaw();
    }

    /**
     * @return array{overview: array<string, mixed>, alarmTrend: array<string, mixed>, events: array<string, mixed>}
     */
    private function loadSecurityRaw(): array
    {
        if ($this->securityEntrance === '') {
            throw new \RuntimeException('security_entrance nao configurado em config.php');
        }

        $isFresh = $this->securityCacheFile !== ''
            && is_file($this->securityCacheFile)
            && (time() - filemtime($this->securityCacheFile)) < $this->securityCacheTtlSeconds;

        if ($isFresh) {
            $cached = json_decode((string) file_get_contents($this->securityCacheFile), true);
            if (is_array($cached) && isset($cached['overview'], $cached['alarmTrend'], $cached['events'])) {
                return $cached;
            }
        }

        $base = '/' . trim($this->securityEntrance, '/') . '/v2/safecloud?action=';
        $raw = [
            'overview' => $this->request($base . 'get_safe_overview'),
            'alarmTrend' => $this->request($base . 'get_pending_alarm_trend'),
            'events' => $this->request($base . 'get_security_dynamic'),
        ];

        if ($this->securityCacheFile !== '') {
            file_put_contents($this->securityCacheFile, json_encode($raw));
        }

        return $raw;
    }

    /**
     * @return array{
     *     updatedAt: string,
     *     cpu: array{pct: float, cores: int|null},
     *     mem: array{usedMb: float, totalMb: float, pct: float},
     *     disk: array{path: string, usedGb: float, totalGb: float, pct: float, others: array<int, array{path: string, pct: float}>},
     *     network: array{upKbps: float, downKbps: float},
     *     load: array{one: float, five: float, fifteen: float}
     * }
     */
    public function getHostStatus(): array
    {
        $raw = $this->loadRaw();

        return [
            'updatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM),
            'cpu' => $this->parseCpu($raw['network']),
            'mem' => $this->parseMem($raw['network']),
            'disk' => $this->parseDisk($raw['disk']),
            'network' => $this->parseNetwork($raw['network']),
            'load' => $this->parseLoad($raw['network']),
        ];
    }

    /**
     * Resposta bruta combinada (GetNetWork + GetDiskInfo) do aaPanel, sem
     * normalizacao — usada so no modo debug (api/host-status.php?debug=1)
     * pra calibrar os nomes de campo reais contra os assumidos em parseX().
     *
     * @return array{network: array<string, mixed>, disk: mixed}
     */
    public function getRaw(): array
    {
        return $this->loadRaw();
    }

    /**
     * @return array{network: array<string, mixed>, disk: mixed}
     */
    private function loadRaw(): array
    {
        $isFresh = is_file($this->cacheFile) && (time() - filemtime($this->cacheFile)) < $this->cacheTtlSeconds;

        if ($isFresh) {
            $cached = json_decode((string) file_get_contents($this->cacheFile), true);
            if (is_array($cached) && isset($cached['network'], $cached['disk'])) {
                return $cached;
            }
        }

        $raw = [
            'network' => $this->request('/system?action=GetNetWork'),
            'disk' => $this->request('/system?action=GetDiskInfo'),
        ];

        file_put_contents($this->cacheFile, json_encode($raw));

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $path): array
    {
        $requestTime = time();
        $requestToken = md5($requestTime . md5($this->apiKey));

        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'request_time' => $requestTime,
                'request_token' => $requestToken,
            ]),
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
            // Sem User-Agent, alguma protecao anti-bot no nginx na frente do
            // painel devolve 404 generico antes mesmo de chegar no aaPanel.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DashStatus-HostMonitor/1.0)',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Falha ao conectar no Servidor ({$path}): {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            $preview = trim(substr((string) $response, 0, 200));
            $suffix = $preview !== '' ? ": {$preview}" : ' (corpo vazio)';
            throw new \RuntimeException("Servidor ({$path}) retornou HTTP {$status}{$suffix}");
        }

        $data = json_decode((string) $response, true);

        if (!is_array($data)) {
            throw new \RuntimeException("Servidor ({$path}) retornou resposta invalida: " . substr((string) $response, 0, 200));
        }

        if (isset($data['status']) && $data['status'] === false) {
            $msg = (string) ($data['msg'] ?? 'erro desconhecido');
            throw new \RuntimeException("Servidor ({$path}) recusou a requisicao: {$msg}");
        }

        return $data;
    }

    /**
     * Le o primeiro campo existente dentre varios nomes candidatos — os
     * nomes de campo reais do aaPanel nao sao garantidos sem testar contra
     * um painel de verdade, entao cada parseX() tenta as variantes mais
     * comuns conhecidas antes de cair pra 0.
     */
    private function numFrom(array $data, array $keys, float $default = 0.0): float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $network
     * @return array{pct: float, cores: int|null}
     */
    private function parseCpu(array $network): array
    {
        $cpu = $network['cpu'] ?? null;

        if (is_array($cpu)) {
            $pct = isset($cpu[0]) && is_numeric($cpu[0]) ? (float) $cpu[0] : 0.0;
            $cores = isset($cpu[1]) && is_numeric($cpu[1]) ? (int) $cpu[1] : null;

            return ['pct' => $pct, 'cores' => $cores];
        }

        return [
            'pct' => is_numeric($cpu) ? (float) $cpu : $this->numFrom($network, ['cpuPct', 'cpu_percent']),
            'cores' => null,
        ];
    }

    /**
     * @param array<string, mixed> $network
     * @return array{usedMb: float, totalMb: float, pct: float}
     */
    private function parseMem(array $network): array
    {
        $mem = is_array($network['mem'] ?? null) ? $network['mem'] : [];

        $totalMb = $this->numFrom($mem, ['memTotal', 'total']);
        $usedMb = $this->numFrom($mem, ['memRealUsed', 'memUsed', 'used']);
        $pct = $totalMb > 0 ? ($usedMb / $totalMb) * 100 : 0.0;

        return ['usedMb' => $usedMb, 'totalMb' => $totalMb, 'pct' => round($pct, 1)];
    }

    /**
     * $network['network'] e o detalhamento por interface (ex: "lo", "ens5")
     * — os totais ja somados (o que a gente quer aqui) ficam soltos no nivel
     * de cima, junto com cpu/mem/load.
     *
     * @param array<string, mixed> $network
     * @return array{upKbps: float, downKbps: float}
     */
    private function parseNetwork(array $network): array
    {
        return [
            'upKbps' => $this->numFrom($network, ['up', 'upTotal']),
            'downKbps' => $this->numFrom($network, ['down', 'downTotal']),
        ];
    }

    /**
     * @param array<string, mixed> $network
     * @return array{one: float, five: float, fifteen: float}
     */
    private function parseLoad(array $network): array
    {
        $load = is_array($network['load'] ?? null) ? $network['load'] : [];

        return [
            'one' => $this->numFrom($load, ['one']),
            'five' => $this->numFrom($load, ['five']),
            'fifteen' => $this->numFrom($load, ['fifteen']),
        ];
    }

    /**
     * Converte valores de tamanho do aaPanel (numero puro assumido como GB,
     * ou string com sufixo como "49.00 GB"/"512MB") pra um float em GB.
     */
    private function toGb(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return 0.0;
        }

        if (!preg_match('/([\d.]+)\s*([KMGT]?)B?/i', $value, $m)) {
            return 0.0;
        }

        $num = (float) $m[1];
        $unit = strtoupper($m[2]);

        return match ($unit) {
            'T' => $num * 1024,
            'G', '' => $num,
            'M' => $num / 1024,
            'K' => $num / (1024 * 1024),
            default => $num,
        };
    }

    /**
     * @param mixed $disk
     * @return array{path: string, usedGb: float, totalGb: float, pct: float, others: array<int, array{path: string, pct: float}>}
     */
    private function parseDisk(mixed $disk): array
    {
        $partitions = is_array($disk) ? $disk : [];
        if (isset($partitions['data']) && is_array($partitions['data'])) {
            $partitions = $partitions['data'];
        }

        $main = null;
        $others = [];

        foreach ($partitions as $partition) {
            if (!is_array($partition)) {
                continue;
            }

            $path = (string) ($partition['path'] ?? $partition['filesystem'] ?? '/');
            $size = is_array($partition['size'] ?? null) ? $partition['size'] : [];

            $totalGb = $this->toGb($size[0] ?? 0);
            $usedGb = $this->toGb($size[1] ?? 0);
            $pctRaw = isset($size[3]) ? (string) $size[3] : '';
            $pct = $pctRaw !== '' ? (float) rtrim($pctRaw, '%') : ($totalGb > 0 ? round(($usedGb / $totalGb) * 100, 1) : 0.0);

            if ($path === $this->diskPath) {
                $main = ['path' => $path, 'usedGb' => $usedGb, 'totalGb' => $totalGb, 'pct' => $pct];
            } else {
                $others[] = ['path' => $path, 'pct' => $pct];
            }
        }

        if ($main === null) {
            $main = ['path' => $this->diskPath, 'usedGb' => 0.0, 'totalGb' => 0.0, 'pct' => 0.0];
        }

        return $main + ['others' => $others];
    }
}
