<?php
require __DIR__ . '/../inc/config.php';
header('Content-Type: application/json; charset=utf-8');

function out(bool $ok, string $err = '', int $code = 200): never {
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 'method', 405);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$name    = trim((string)($in['name'] ?? ''));
$phone   = trim((string)($in['phone'] ?? ''));
$comment = trim((string)($in['comment'] ?? ''));

if (mb_strlen($name) < 2 || mb_strlen($name) > 150) out(false, 'name', 422);
if (!preg_match('/^[0-9+()\-\s]{6,40}$/', $phone)) out(false, 'phone', 422);
if (mb_strlen($comment) > 2000) out(false, 'comment', 422);

try {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    // не более 5 заявок в час с одного IP
    $q = db()->prepare('SELECT COUNT(*) FROM leads WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $q->execute([$ip]);
    if ((int)$q->fetchColumn() >= 5) out(false, 'rate', 429);

    db()->prepare('INSERT INTO leads (name, phone, comment, ip) VALUES (?,?,?,?)')
        ->execute([$name, $phone, $comment, $ip]);
    out(true);
} catch (Throwable $e) {
    error_log($e->getMessage());
    out(false, 'server', 500);
}
