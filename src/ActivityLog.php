<?php
declare(strict_types=1);

namespace DashStatus;

class ActivityLog
{
    public function __construct(
        private readonly string $file,
        private readonly int $maxEntries = 200
    ) {
    }

    /**
     * @param 'ok'|'warn'|'crit'|'sys' $level
     */
    public function log(string $level, string $text): void
    {
        $entries = $this->loadEntries();

        $entries[] = [
            'time' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM),
            'level' => $level,
            'text' => $text,
        ];

        if (count($entries) > $this->maxEntries) {
            $entries = array_slice($entries, -$this->maxEntries);
        }

        file_put_contents($this->file, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<int, array{time:string, level:string, text:string}>
     */
    public function recent(int $limit = 10): array
    {
        $entries = array_reverse($this->loadEntries());

        return array_slice($entries, 0, $limit);
    }

    /**
     * @return array<int, array{time:string, level:string, text:string}>
     */
    private function loadEntries(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $data = json_decode(file_get_contents($this->file), true);

        return is_array($data) ? $data : [];
    }
}