<?php
declare(strict_types=1);

namespace DashStatus\Services;

class OpenCageService
{
    public function __construct(
        private readonly string $usageCsvUrl,
        private readonly int $dailyLimit,
        private readonly string $cacheFile,
        private readonly int $cacheTtlSeconds = 600
    ) {
    }

    /**
     * O CSV do OpenCage tem defasagem (o dia corrente costuma vir zerado
     * até ser consolidado). Por isso o "uso" reportado é o do último dia
     * com requisições registradas na janela, não necessariamente hoje.
     *
     * @return array{used:int, limit:int, remaining:int, history:array<int, array{date:string, total:int}>, referenceDate:string, hasRecentActivity:bool}
     */
    public function getUsage(int $historyDays = 30): array
    {
        $rows = $this->loadUsageRows();

        $history = [];
        for ($i = $historyDays - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $history[] = [
                'date' => $date,
                'total' => (int) ($rows[$date]['total'] ?? 0),
            ];
        }

        $referenceDate = date('Y-m-d');
        $referenceTotal = 0;
        $hasRecentActivity = false;

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['total'] > 0) {
                $referenceDate = $history[$i]['date'];
                $referenceTotal = $history[$i]['total'];
                $hasRecentActivity = true;
                break;
            }
        }

        return [
            'used' => $referenceTotal,
            'limit' => $this->dailyLimit,
            'remaining' => max(0, $this->dailyLimit - $referenceTotal),
            'history' => $history,
            'referenceDate' => $referenceDate,
            'hasRecentActivity' => $hasRecentActivity,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadUsageRows(): array
    {
        $isFresh = is_file($this->cacheFile) && (time() - filemtime($this->cacheFile)) < $this->cacheTtlSeconds;

        if ($isFresh) {
            $csv = file_get_contents($this->cacheFile);
        } else {
            $csv = $this->fetchCsv();
            file_put_contents($this->cacheFile, $csv);
        }

        return $this->parseCsv($csv);
    }

    private function fetchCsv(): string
    {
        $ch = curl_init($this->usageCsvUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Falha ao baixar CSV de uso do OpenCage: {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            throw new \RuntimeException("CSV de uso do OpenCage retornou HTTP {$status}");
        }

        return $response;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv((string) array_shift($lines), ';');

        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $fields = str_getcsv($line, ';');
            $row = array_combine($header, $fields);
            $rows[$row['date']] = $row;
        }

        return $rows;
    }
}