<?php
declare(strict_types=1);

namespace DashStatus\Services;

class MapboxService
{
    public function __construct(
        private readonly int $monthlyLimit,
        private readonly string $storageFile
    ) {
    }

    /**
     * Mapbox nao expoe uso via API publica: o valor e lido de um historico
     * de leituras manuais salvo em data/mapbox-usage.json.
     *
     * @return array{used:int, limit:int, updated_at:string}
     */
    public function getUsage(): array
    {
        $entries = $this->loadEntries();

        if (empty($entries)) {
            throw new \RuntimeException('Nenhuma leitura manual registrada ainda.');
        }

        $latest = end($entries);

        return [
            'used' => (int) $latest['used'],
            'limit' => $this->monthlyLimit,
            'updated_at' => (string) $latest['updated_at'],
        ];
    }

    /**
     * Historico de leituras com o delta (loads desde a leitura anterior).
     * A primeira leitura do periodo nao tem delta (nao ha o que comparar).
     *
     * @return array<int, array{date:string, used:int, delta:int|null}>
     */
    public function getHistory(): array
    {
        $entries = $this->loadEntries();

        $history = [];
        $previousUsed = null;

        foreach ($entries as $entry) {
            $used = (int) $entry['used'];
            $history[] = [
                'date' => (string) $entry['updated_at'],
                'used' => $used,
                'delta' => $previousUsed === null ? null : $used - $previousUsed,
            ];
            $previousUsed = $used;
        }

        return $history;
    }

    /**
     * Mesmo historico, mas agrupado por dia (soma os deltas de leituras
     * feitas no mesmo dia) — uma barra por dia no grafico, em vez de uma
     * por leitura. A primeira leitura (sem delta) fica de fora.
     *
     * @return array<int, array{date:string, used:int, delta:int}>
     */
    public function getDailyHistory(): array
    {
        $daily = [];

        foreach ($this->getHistory() as $entry) {
            if ($entry['delta'] === null) {
                continue;
            }

            $day = (new \DateTimeImmutable($entry['date']))->format('Y-m-d');

            if (!isset($daily[$day])) {
                $daily[$day] = ['date' => $day, 'used' => $entry['used'], 'delta' => 0];
            }

            $daily[$day]['delta'] += $entry['delta'];
            $daily[$day]['used'] = $entry['used'];
        }

        return array_values($daily);
    }

    /**
     * Registra uma nova leitura e retorna o delta em relacao a anterior
     * (null se for a primeira leitura registrada).
     */
    public function setUsage(int $used): ?int
    {
        $entries = $this->loadEntries();
        $previous = empty($entries) ? null : (int) end($entries)['used'];

        $entries[] = [
            'used' => $used,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM),
        ];

        file_put_contents($this->storageFile, json_encode($entries, JSON_PRETTY_PRINT));

        return $previous === null ? null : $used - $previous;
    }

    /**
     * @return array<int, array{used:int, updated_at:string}>
     */
    private function loadEntries(): array
    {
        if (!is_file($this->storageFile)) {
            return [];
        }

        $data = json_decode(file_get_contents($this->storageFile), true);

        if (!is_array($data)) {
            return [];
        }

        // Compatibilidade com o formato antigo (objeto unico, sem historico).
        if (isset($data['used'])) {
            return [$data];
        }

        return $data;
    }
}