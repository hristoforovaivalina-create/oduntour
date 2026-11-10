<?php
require __DIR__ . '/auth.php';
if (!empty($_SESSION['admin'])) { header('Location: index.php'); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $c = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND created_at > (NOW() - INTERVAL 15 MINUTE)');
    $c->execute([$ip]);
    if ((int)$c->fetchColumn() >= 5) {
        $err = 'Слишком много попыток. Подождите 15 минут.';
    } else {
        $s = db()->prepare('SELECT * FROM admins WHERE login=?');
        $s->execute([trim($_POST['login'] ?? '')]);
        $a = $s->fetch();
        if ($a && password_verify($_POST['password'] ?? '', $a['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = $a['login'];
            db()->prepare('DELETE FROM login_attempts WHERE ip=?')->execute([$ip]);
            header('Location: index.php'); exit;
        }
        db()->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
        $err = 'Неверный логин или пароль';
    }
}
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Вход — Одун Тур</title>
<link rel="stylesheet" href="style.css"></head>
<body class="center">
<form class="card login" method="post">
  <h1>Одун Тур</h1><p class="muted">Вход в админ-панель</p>
  <?php if ($err): ?><div class="alert"><?= h($err) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= csrf() ?>">
  <label>Логин<input name="login" required autofocus autocomplete="username"></label>
  <label>Пароль<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn" type="submit">Войти</button>
</form></body></html>
