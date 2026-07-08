<?php
declare(strict_types=1);

// Lista de serviços monitorados via Statuspage publico (sem chave de API).
// Pra adicionar um novo servico, so incluir mais um item aqui.

return [
    [
        'key' => 'claude',
        'name' => 'Claude',
        'meta' => 'Anthropic',
        'summary_url' => 'https://status.claude.com/api/v2/summary.json',
        'link' => 'https://status.claude.com',
        'icon' => '<path d="M12 3v4M12 17v4M4.2 4.2l2.8 2.8M17 17l2.8 2.8M3 12h4M17 12h4M4.2 19.8l2.8-2.8M17 7l2.8-2.8" stroke-linecap="round"/><circle cx="12" cy="12" r="2.2"/>',
    ],
    [
        'key' => 'openai',
        'name' => 'ChatGPT',
        'meta' => 'OpenAI',
        'summary_url' => 'https://status.openai.com/api/v2/summary.json',
        'link' => 'https://status.openai.com',
        'icon' => '<path d="M21 11.8a8.4 8.4 0 01-8.4 8.4c-1.35 0-2.62-.33-3.73-.92L3 21l1.72-5.87A8.4 8.4 0 1121 11.8z" stroke-linejoin="round"/>',
    ],
];
