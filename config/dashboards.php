<?php
declare(strict_types=1);

// Lista de dashboards disponiveis no hub pos-login.
// Pra adicionar um novo dashboard no futuro, so incluir mais um item aqui.

return [
    [
        'key' => 'api-monitor',
        'name' => 'API Monitor',
        'description' => 'Uso e status das APIs de geolocalização (OpenCage, Mapbox) e dos provedores de IA (Claude, ChatGPT).',
        'link' => 'index.php',
        'icon' => '<path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/>',
    ],
];
