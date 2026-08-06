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
     * O numero que o Mapbox mostra no console e do periodo corrente (pode
     * cair quando um novo periodo comeca com pouco uso ainda) — nao e um
     * total vitalicio. `used` aqui soma tudo que ja foi visto, inclusive
     * antes de cada queda desse tipo, pra refletir o total real de loads
     * ja consumidos, nao so o que sobrou no periodo atual.
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
            'used' => $this->calculateLifetimeTotal($entries),
            'limit' => $this->monthlyLimit,
            'updated_at' => (string) $latest['updated_at'],
        ];
    }

    /**
     * Ultima leitura crua, sem acumular o total — usada pelo formulario de
     * atualizacao pra mostrar/pre-preencher o numero que foi digitado da
     * ultima vez (o que o usuario ve agora no console do Mapbox), nao o
     * total acumulado que o card mostra.
     *
     * @return array{used:int, limit:int, updated_at:string}
     */
    public function getLatestReading(): array
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
     * Soma o uso acumulado ao longo de todo o historico. Quando uma leitura
     * e menor que a anterior, trata como o Mapbox tendo reiniciado a
     * contagem do periodo (nao como uma correcao) — o valor lido vira uso
     * novo somado ao total, em vez de gerar um delta negativo.
     *
     * @param array<int, array{used:int, updated_at:string}> $entries
     */
    private function calculateLifetimeTotal(array $entries): int
    {
        $total = 0;
        $previous = null;

        foreach ($entries as $entry) {
            $used = (int) $entry['used'];
            $total += ($previous === null || $used < $previous) ? $used : $used - $previous;
            $previous = $used;
        }

        return $total;
    }

    /**
     * Historico de leituras com o delta (loads desde a leitura anterior).
     * A primeira leitura do historico usa 0 como base (nao ha leitura
     * anterior), entao seu delta e o proprio valor lido - assim o total do
     * grafico bate com o acumulado, em vez de perder essa leitura.
     *
     * @return array<int, array{date:string, used:int, delta:int}>
     */
    public function getHistory(): array
    {
        $entries = $this->loadEntries();

        $history = [];
        $previousUsed = 0;

        foreach ($entries as $entry) {
            $used = (int) $entry['used'];
            $history[] = [
                'date' => (string) $entry['updated_at'],
                'used' => $used,
                'delta' => $used - $previousUsed,
            ];
            $previousUsed = $used;
        }

        return $history;
    }

    /**
     * Mesmo historico, mas agrupado por dia (soma os deltas de leituras
     * feitas no mesmo dia) — uma barra por dia no grafico, em vez de uma
     * por leitura.
     *
     * @return array<int, array{date:string, used:int, delta:int}>
     */
    public function getDailyHistory(): array
    {
        $daily = [];

        foreach ($this->getHistory() as $entry) {
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