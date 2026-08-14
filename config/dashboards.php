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
    [
        'key' => 'host-monitor',
        'name' => 'Host Monitor',
        'description' => 'CPU, memória, disco e rede do servidor em tempo real.',
        'link' => 'host-monitor.php',
        'icon' => '<rect x="2" y="4" width="20" height="6" rx="1.5"/><rect x="2" y="14" width="20" height="6" rx="1.5"/><circle cx="6" cy="7" r="0.5"/><circle cx="6" cy="17" r="0.5"/>',
    ],
    [
        'key' => 'security-threats',
        'name' => 'Ameaças (CrowdSec)',
        'description' => 'Mapa de origem dos ataques, tipos de ameaça e eventos bloqueados pelo CrowdSec.',
        'link' => 'security-threats.php',
        'icon' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.8 5.7 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.7-3.8-9s1.3-6.5 3.8-9z"/>',
    ],
];
