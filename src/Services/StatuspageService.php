<?php
declare(strict_types=1);

namespace DashStatus\Services;

class StatuspageService
{
    public function __construct(
        private readonly string $summaryUrl,
        private readonly string $cacheFile,
        private readonly int $cacheTtlSeconds = 300
    ) {
    }

    /**
     * @return array{
     *     indicator: string,
     *     description: string,
     *     components: array<int, array{name: string, status: string}>,
     *     incidents: array<int, array{name: string, status: string}>,
     *     updatedAt: string
     * }
     */
    public function getStatus(): array
    {
        $data = $this->loadSummary();

        $components = array_map(
            static fn (array $c): array => ['name' => (string) $c['name'], 'status' => (string) $c['status']],
            $data['components'] ?? []
        );

        $incidents = array_map(
            static fn (array $i): array => ['name' => (string) $i['name'], 'status' => (string) $i['status']],
            $data['incidents'] ?? []
        );

        return [
            'indicator' => (string) ($data['status']['indicator'] ?? 'none'),
            'description' => (string) ($data['status']['description'] ?? 'Operacional'),
            'components' => $components,
            'incidents' => $incidents,
            'updatedAt' => (string) ($data['page']['updated_at'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSummary(): array
    {
        $isFresh = is_file($this->cacheFile) && (time() - filemtime($this->cacheFile)) < $this->cacheTtlSeconds;

        if ($isFresh) {
            $json = file_get_contents($this->cacheFile);
        } else {
            $json = $this->fetchSummary();
            file_put_contents($this->cacheFile, $json);
        }

        $data = json_decode((string) $json, true);

        return is_array($data) ? $data : [];
    }

    private function fetchSummary(): string
    {
        $ch = curl_init($this->summaryUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Falha ao buscar status ({$this->summaryUrl}): {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            throw new \RuntimeException("Status ({$this->summaryUrl}) retornou HTTP {$status}");
        }

        return $response;
    }
}
