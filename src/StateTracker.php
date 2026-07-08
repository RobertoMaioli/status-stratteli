<?php
declare(strict_types=1);

namespace DashStatus;

/**
 * Guarda o ultimo estado conhecido de cada servico para detectar transicoes
 * (ex: OpenCage saiu de "ok" e entrou em "warn") sem repetir o mesmo evento
 * a cada carregamento da pagina.
 */
class StateTracker
{
    public function __construct(private readonly string $file)
    {
    }

    /**
     * @return array{changed:bool, previous:?string}
     */
    public function checkTransition(string $service, string $newState): array
    {
        $states = $this->loadStates();
        $previous = $states[$service] ?? null;
        $changed = $previous !== $newState;

        if ($changed) {
            $states[$service] = $newState;
            file_put_contents($this->file, json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return ['changed' => $changed, 'previous' => $previous];
    }

    /**
     * @return array<string, string>
     */
    private function loadStates(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $data = json_decode(file_get_contents($this->file), true);

        return is_array($data) ? $data : [];
    }
}