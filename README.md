# DashStatus (Sentinel)

Dashboard de monitoramento de limites de uso de APIs (OpenCage, Mapbox), com
login para acesso restrito.

## Estrutura

```
index.php            página principal (protegida por login)
hub.php               hub de dashboards pós-login (config/dashboards.php)
host-monitor.php      dashboard "Host Monitor" — CPU/RAM/disco/rede ao vivo (aaPanel)
login.php            tela de login
logout.php            encerra a sessão
mapbox-update.php     tela para registrar manualmente o uso do Mapbox (Map Loads)
mapbox-search-update.php  tela para registrar manualmente o uso do Mapbox Search
assets/
  css/style.css       estilos do dashboard
  css/login.css       estilos da tela de login/formulários
  js/opencage-chart.js       gráfico de uso diário do OpenCage (Chart.js)
  js/mapbox-chart.js         gráfico de delta entre leituras do Mapbox (Chart.js)
  js/host-monitor.js         polling AJAX (8s) do Host Monitor
  js/vendor/chart.umd.js     Chart.js (vendorizado, sem depender de CDN)
  img/                imagens/ícones (futuro)
includes/
  bootstrap.php       carrega autoload + auth + config
  autoload.php        autoload PSR-4-like para DashStatus\... em src/
  auth.php            sessão, auth_check(), csrf_token()/csrf_verify()
src/
  Database.php               conexão PDO (MySQL)
  ActivityLog.php             grava/lê o registro de atividade (data/activity-log.json)
  StateTracker.php            detecta mudança de estado dos serviços (data/service-states.json)
  Auth/AuthService.php       valida usuário/senha contra a tabela users
  Services/OpenCageService.php   uso real via CSV do dashboard OpenCage
  Services/MapboxService.php     uso via leitura manual (data/mapbox-usage.json)
  Services/AapanelService.php    CPU/RAM/disco/rede via Open API do aaPanel
config/
  config.example.php  modelo de configuração (banco, chaves de API, limites)
  config.php          configuração local real (fora do git)
  dashboards.php       lista de dashboards exibidos no hub pós-login
sql/
  schema.sql           cria o banco `dashstatus` e a tabela `users`
bin/
  create-admin.php     CLI para criar/atualizar um usuário
api/
  status.php           endpoint JSON com o uso real do OpenCage (não usado hoje)
  host-status.php       endpoint JSON polled pelo Host Monitor (CPU/RAM/disco/rede)
data/                  cache do CSV do OpenCage, leituras do Mapbox, log de atividade
logs/                  logs locais
```

## Rodando localmente com XAMPP

1. Instale o XAMPP e coloque este projeto em `htdocs/dashstatus` (ou crie um
   vhost apontando para esta pasta — aqui usamos uma junction do Windows
   apontando para a pasta original do projeto).
2. Suba o Apache e o MySQL pelo painel do XAMPP.
3. Crie o banco e a tabela: abra o phpMyAdmin e importe `sql/schema.sql`
   (ou rode o conteúdo do arquivo lá).
4. Confira `config/config.php` — os defaults (`host=127.0.0.1`, `user=root`,
   `pass=''`) já batem com o MySQL padrão do XAMPP.
5. Crie o usuário admin:
   ```
   php bin/create-admin.php admin "sua-senha-aqui"
   ```
6. Acesse `http://localhost/dashstatus/login.php`.

Alternativa sem XAMPP (só PHP instalado, sem necessidade de banco ainda):
`php -S localhost:8000` — mas o login não vai funcionar sem MySQL configurado.

## Configuração

Copie `config/config.example.php` para `config/config.php` (já feito neste
checkout) e preencha as credenciais do banco e as chaves reais das APIs.
`config.php` não deve ir para o controle de versão.

## Login

- Login é feito contra a tabela `users` (MySQL) — pensado para 1 usuário admin
  hoje, mas já suporta múltiplos usuários (basta rodar `create-admin.php` de
  novo com outro nome).
- Senhas usam `password_hash`/`password_verify` (bcrypt).
- Formulário de login tem proteção CSRF via token de sessão.
- `index.php` chama `auth_check()` e redireciona para `login.php` se não
  houver sessão ativa.

## Integrações

### OpenCage (automático)

O plano pago (assinatura) não retorna o campo `rate` nem headers
`X-Ratelimit-*` na resposta de geocoding — isso só existe em contas trial/
avulsas. O uso real é obtido via CSV de histórico diário disponível no
dashboard do OpenCage (aba "Geocoding API" > link com token exclusivo),
configurado em `config.php` como `services.opencage.usage_csv_url`.
`OpenCageService` baixa esse CSV (cacheado em `data/opencage-usage.csv` por
10 min) e calcula uso de hoje + histórico. O gráfico do card usa Chart.js
com tooltip por dia.

### Mapbox (manual)

O Mapbox não tem API pública de uso ("Mapbox usage data is not available
through an API service", segundo a doc oficial) nem exportação CSV. Existe um
endpoint interno não documentado (`billing/usage/v2`) usado pelo próprio
dashboard deles, mas exige um token de sessão que expira em ~1h e só é gerado
fazendo login no console — automatizar isso exigiria guardar a senha da conta
no servidor, o que é um risco desproporcional ao benefício. Por isso o uso é
registrado manualmente em `mapbox-update.php` (protegido por login + CSRF).

`data/mapbox-usage.json` guarda o **histórico** de leituras (não sobrescreve),
cada uma com `used` e `updated_at`. A cada nova leitura, `MapboxService`
calcula o delta em relação à leitura anterior (ex: 263 → 268 = +5 requisições
naquele intervalo) e mostra isso na tela de atualização.

O gráfico do card usa `MapboxService::getDailyHistory()`, que agrupa por dia
e soma os deltas de leituras feitas no mesmo dia — uma barra por dia, não por
leitura (evita ter 2+ barras coladas se você atualizar várias vezes no mesmo
dia). A 1ª leitura do histórico usa 0 como base (sem leitura anterior pra
comparar), então seu delta é o próprio valor lido — assim o total do gráfico
sempre bate com o acumulado.

### Mapbox Search / Temporary Geocoding API (manual)

Mesmo mecanismo do Map Loads acima, e pelo mesmo motivo: sem API de uso nem
CSV oficiais, e o total mostrado no console do Mapbox não reseta mensalmente
(pelo menos neste plano) — então o modelo de contador cumulativo + delta
entre leituras é o correto aqui também, não uma leitura "por dia".

`MapboxService` já é genérico (recebe `monthlyLimit` e `storageFile` no
construtor), então é reaproveitado sem alteração: uma segunda instância
aponta pra `data/mapbox-search-usage.json` e pro limite em
`config.php` → `services.mapbox_search.monthly_limit` (100.000, o free tier
da Temporary Geocoding API). Leitura registrada em
`mapbox-search-update.php` (clone de `mapbox-update.php`), card e gráfico
próprios em `index.php` (`mapbox-search-chart`), com chave de estado
`mapbox_search` separada da `mapbox` no `StateTracker`/`ActivityLog`.

`assets/js/mapbox-chart.js` deixou de estar hardcoded pra um único canvas:
agora expõe `initMapboxChart(canvasId, unitLabel, dayCaption)`, chamada uma
vez pra cada card (Map Loads e Search).

### Host Monitor / aaPanel (automático, ao vivo)

Dashboard separado (`host-monitor.php`, acessível pelo hub) que mostra CPU,
RAM, disco e rede do servidor **ao vivo**, via polling AJAX a cada 8s
(`assets/js/host-monitor.js` → `api/host-status.php`) — diferente do resto
do app, que recarrega a página inteira a cada 2min.

Os dados vêm da Open API do próprio aaPanel (`AapanelService`), que expõe
`/system?action=GetNetWork` (CPU/memória/rede/load) e
`/system?action=GetDiskInfo` (disco), autenticados por uma assinatura
`request_token = md5(request_time . md5(api_key))`. Configuração em
`config.php` → `services.aapanel`:

```php
'aapanel' => [
    'base_url' => '',   // ex: http://127.0.0.1:8888
    'api_key' => '',    // aaPanel > Configurações > Interface (Open API)
    'disk_path' => '/', // particao principal exibida no card de disco
    'verify_ssl' => false,
],
```

Pra habilitar: no painel, vá em **Configurações > Interface** (Open API),
ative e copie a chave gerada. Como o DashStatus roda no mesmo servidor, o
IP a liberar no whitelist do painel deve ser só `127.0.0.1`.

**Calibração:** os nomes de campo usados em `AapanelService` (`mem.memTotal`,
`network.up/down`, `disk[].size`, etc.) são a melhor estimativa baseada na
documentação pública e em bibliotecas de terceiros — o aaPanel não publica
um schema de resposta formal. Se algum número aparecer zerado ou estranho
após configurar as credenciais reais, acesse `api/host-status.php?debug=1`
(autenticado) pra ver o JSON bruto retornado pelo painel e ajustar o
parsing em `AapanelService::parseCpu()/parseMem()/parseNetwork()/parseDisk()`
de acordo.

## Registro de atividade

`ActivityLog` (`data/activity-log.json`) guarda os últimos 200 eventos; a
página mostra os 10 mais recentes. Eventos gravados hoje:

- **Mudança de estado de um serviço** (ex: OpenCage saiu de Operacional e
  entrou em Alerta). Detectado por `StateTracker` (`data/service-states.json`),
  que compara o estado atual com o último conhecido a cada carregamento de
  `index.php` — só loga na transição real, não repete a cada visita e não
  loga nada na primeira vez que um serviço é visto (evita ruído inicial).
- **Leitura manual do Mapbox registrada**, com o delta em relação à anterior.
- **Login e logout** de usuário.
- **Falha ao buscar o CSV do OpenCage** — tratada como um estado especial
  (`error`) dentro do mesmo mecanismo de transição acima, então só loga
  quando começa a falhar e quando volta a funcionar, não a cada tentativa.

De propósito, **não** existe um evento de "lembrete pra atualizar o Mapbox"
(o mock original tinha isso) — decidido explicitamente para não gerar ruído.

## Próximos passos

- Se o volume de uso do OpenCage justificar, considerar mostrar o mês inteiro
  (hoje o gráfico mostra os últimos 30 dias corridos).
- Se necessário: tela de gestão de usuários (hoje só via `bin/create-admin.php`).