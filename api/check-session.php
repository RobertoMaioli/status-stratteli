<?php
declare(strict_types=1);

/**
 * Endpoint de checagem de sessão pra SSO via nginx auth_request — usado por
 * outros subdomínios de stratelli.com.br (ex: mandados.stratelli.com.br) pra
 * confirmar se o usuário está logado no DashStatus antes de liberar acesso.
 *
 * Só responde 200 (logado) ou 401 (não logado) — nada de redirect/HTML aqui,
 * o nginx auth_request trata qualquer outro código como erro.
 */

require_once __DIR__ . '/../includes/auth.php';

http_response_code(empty($_SESSION['user_id']) ? 401 : 200);