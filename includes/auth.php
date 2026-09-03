<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Em produção (stratelli.com.br), escopa o cookie pro domínio raiz —
    // não só o host exato — pra mesma sessão valer em outros subdomínios
    // (ex: mandados.stratelli.com.br), permitindo SSO via nginx auth_request
    // (ver api/check-session.php). Em dev local (localhost etc.) mantém o
    // comportamento padrão do PHP, senão a sessão não funciona sem HTTPS.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (str_ends_with($host, 'stratelli.com.br')) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '.stratelli.com.br',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

function auth_check(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function auth_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_verify(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}