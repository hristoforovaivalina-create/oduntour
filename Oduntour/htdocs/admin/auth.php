<?php
require __DIR__ . '/../inc/config.php';
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'httponly' => true, 'samesite' => 'Strict']);
session_name('odun_admin');
session_start();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');

function csrf(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('CSRF'); }
}
function require_login(): void {
    if (empty($_SESSION['admin'])) { header('Location: login.php'); exit; }
}
