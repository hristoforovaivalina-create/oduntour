<?php
// ============ ОДУН ТУР — весь сайт одним файлом (сайт + заявки + админка + SQL) ============
// Настройки MySQL (OSPanel по умолчанию: root без пароля). База и таблицы создаются автоматически.
// 'auto' = сайт сам найдёт MySQL в OSPanel. Можно вписать вручную, например 'MySQL-8.0' или '127.0.0.1'
const DB_HOST = 'sql104.infinityfree.com';
const DB_PORT = 3306; // 0 = перебирать 3306, 3307, 3308
const DB_NAME = 'if0_43397295_odun';
const DB_USER = 'if0_43397295';
const DB_PASS = 'ljXdblBUEVw9l';
// Администратор: логин admin, пароль oduntour2026 (хранится как bcrypt-хеш)
// true = показывать причину ошибки (для настройки). После запуска поставьте false.
const DEBUG = false;
const ADMIN_LOGIN = 'admin';
const ADMIN_HASH  = '$2y$10$NGgR60EJpHz2GtpyWeqFAe.VQFOKV13JYnkJ7UXwBzsbzWvrvrr2W';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $o[PDO::ATTR_TIMEOUT] = 2;
    $hosts = DB_HOST === 'auto'
        ? ['127.0.0.1', 'localhost', 'MySQL-8.0', 'MySQL-8.4', 'MySQL-5.7', 'MySQL-5.6', 'MariaDB-10.11', 'MariaDB-10.6', 'MariaDB-10.5', 'MariaDB-10.4', 'MariaDB-10.3', 'MariaDB-10.2', 'MariaDB-11.4']
        : [DB_HOST];
    $ports = DB_PORT ? [DB_PORT] : [3306, 3307, 3308];
    $cache = sys_get_temp_dir() . '/odun_db_conn.txt';
    $combos = [];
    if (DB_HOST === 'auto' && is_file($cache) && ($c = explode('|', trim(@file_get_contents($cache)))) && count($c) === 2) $combos[] = $c;
    foreach ($hosts as $hh) foreach ($ports as $pp) $combos[] = [$hh, $pp];
    $pdo = null; $errs = [];
    foreach ($combos as [$hh, $pp]) {
        try {
            $pdo = new PDO('mysql:host=' . $hh . ';port=' . $pp . ';charset=utf8mb4', DB_USER, DB_PASS, $o);
            if (DB_HOST === 'auto') @file_put_contents($cache, $hh . '|' . $pp);
            break;
        } catch (PDOException $e) {
            $errs[] = $hh . ':' . $pp;
            // сервер найден, но логин/пароль не подошли — перебирать дальше смысла нет
            if (strpos($e->getMessage(), '1045') !== false) throw new PDOException('MySQL найден (' . $hh . ':' . $pp . '), но пароль не подошёл. Впишите пароль в DB_PASS в начале файла.');
        }
    }
    if (!$pdo) throw new PDOException('MySQL не найден. Запустите MySQL в OSPanel. Проверено: ' . implode(', ', array_slice($errs, 0, 6)) . '...');
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    // ---- SQL: таблицы ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL, phone VARCHAR(40) NOT NULL, comment TEXT NULL,
        status ENUM('new','processed') NOT NULL DEFAULT 'new',
        ip VARCHAR(45) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created_at), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ip_time (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(30) NOT NULL UNIQUE, name VARCHAR(60) NOT NULL,
        perms VARCHAR(200) NOT NULL DEFAULT '', is_system TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        login VARCHAR(50) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, name VARCHAR(100) NOT NULL DEFAULT '',
        role_id INT UNSIGNED NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        type ENUM('news','post') NOT NULL DEFAULT 'news', title VARCHAR(200) NOT NULL, body TEXT NULL,
        image VARCHAR(60) NULL, published TINYINT(1) NOT NULL DEFAULT 1, author VARCHAR(50) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS photos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL DEFAULT '', file VARCHAR(60) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // начальные роли и первый администратор
    if (!(int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn()) {
        $pdo->exec("INSERT INTO roles (slug,name,perms,is_system) VALUES
            ('admin','Админ','leads,leads_del,content,users,roles',1),
            ('operator','Оператор','leads',0),
            ('editor','Редактор','content',0)");
    }
    if (!(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
        $pdo->prepare("INSERT INTO users (login,password_hash,name,role_id) VALUES (?,?,'Администратор',(SELECT id FROM roles WHERE slug='admin'))")
            ->execute([ADMIN_LOGIN, ADMIN_HASH]);
    }
    return $pdo;
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- API: приём заявки с сайта ----------
if (($_GET['api'] ?? '') === 'lead') {
    header('Content-Type: application/json; charset=utf-8');
    $out = function (bool $ok, string $e = '', int $c = 200) {
        http_response_code($c);
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $e]); exit;
    };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(false, 'method', 405);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim((string)($in['name'] ?? '')); $phone = trim((string)($in['phone'] ?? '')); $comment = trim((string)($in['comment'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 150) $out(false, 'name', 422);
    if (!preg_match('/^[0-9+()\-\s]{6,40}$/', $phone)) $out(false, 'phone', 422);
    if (mb_strlen($comment) > 2000) $out(false, 'comment', 422);
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $q = db()->prepare('SELECT COUNT(*) FROM leads WHERE ip=? AND created_at > (NOW() - INTERVAL 1 HOUR)');
        $q->execute([$ip]);
        if ((int)$q->fetchColumn() >= 5) $out(false, 'rate', 429);
        db()->prepare('INSERT INTO leads (name,phone,comment,ip) VALUES (?,?,?,?)')->execute([$name, $phone, $comment, $ip]);
        $out(true);
    } catch (Throwable $e) { error_log($e->getMessage()); $out(false, DEBUG ? $e->getMessage() : 'server', 500); }
}

// ---------- РОЛИ, ПОЛЬЗОВАТЕЛИ, КОНТЕНТ ----------
const PERMS = [
    'leads'     => 'Заявки: просмотр и отметка «обработана»',
    'leads_del' => 'Заявки: удаление',
    'content'   => 'Контент: фото, новости, посты',
    'users'     => 'Управление пользователями',
    'roles'     => 'Управление ролями',
];
function qr(string $sql, array $a = []): PDOStatement { $s = db()->prepare($sql); $s->execute($a); return $s; }
function role_perms(array $r): array { return $r['is_system'] ? array_keys(PERMS) : array_values(array_filter(explode(',', (string)$r['perms']))); }

function save_image(array $f): ?string {
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 8 * 1024 * 1024) return null;
    $i = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$i[2] ?? 0] ?? null;
    if (!$ext) return null;
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Options -Indexes -ExecCGI\nRemoveHandler .php .phtml\nAddType text/plain .php .phtml\n");
    }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    return move_uploaded_file($f['tmp_name'], $dir . '/' . $name) ? $name : null;
}
function img_del(?string $n): void {
    if ($n && preg_match('/^[a-f0-9]{20}\.(jpg|png|gif|webp)$/', $n)) @unlink(__DIR__ . '/uploads/' . $n);
}
// фото из админки — в галерею на сайте
function site_photos(): string {
    try {
        $o = '';
        foreach (qr('SELECT file, title FROM photos ORDER BY id DESC LIMIT 30')->fetchAll() as $r)
            $o .= '<a href="uploads/' . h($r['file']) . '" target="_blank" rel="noopener"><img src="uploads/' . h($r['file']) . '" alt="' . h($r['title']) . '" loading="lazy"></a>';
        return $o;
    } catch (Throwable $e) { return ''; }
}
// новости и посты — отдельный блок на сайте (виден, только если есть публикации)
function site_news(): string {
    try {
        $rows = qr('SELECT * FROM posts WHERE published=1 ORDER BY created_at DESC, id DESC LIMIT 9')->fetchAll();
        if (!$rows) return '';
        $o = '<section class="section" id="news"><div class="container"><div class="section__head"><span class="section__uptitle">Новости</span><h2>Новости и статьи</h2></div><div class="news">';
        foreach ($rows as $r) {
            $o .= '<article>' . ($r['image'] ? '<img src="uploads/' . h($r['image']) . '" alt="' . h($r['title']) . '" loading="lazy">' : '')
                . '<div class="nb"><time>' . h(date('d.m.Y', strtotime($r['created_at']))) . '</time><h3>' . h($r['title']) . '</h3><p>' . nl2br(h($r['body'])) . '</p></div></article>';
        }
        return $o . '</div></div></section>';
    } catch (Throwable $e) { return ''; }
}

function admin_main(): void {
    session_name('odun_admin');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
    header('X-Frame-Options: DENY'); header('X-Robots-Tag: noindex, nofollow');
    $csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    $go = function (string $to = '') { header('Location: ?admin' . ($to !== '' ? '&' . $to : '')); exit; };
    $flash = function (string $t, string $m) { $_SESSION['flash'] = [$t, $m]; };
    $chk = function () { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('CSRF'); } };
    $css = <<<'CSS'
:root{--green:#2F5D50;--blue:#1F4E79;--deep:#163A5A}*{box-sizing:border-box;margin:0;padding:0}body{font-family:system-ui,"Segoe UI",sans-serif;background:#EDF1F4;color:#1E2A30;font-size:15px}body.c{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}a{color:var(--blue)}.m{color:#55666E}.card{background:#fff;border-radius:14px;box-shadow:0 8px 28px rgba(22,58,90,.12);padding:20px}.login{width:100%;max-width:380px;display:flex;flex-direction:column;gap:14px;padding:32px}.login h1{color:var(--green);font-family:Georgia,serif}h2{color:var(--deep);margin-bottom:12px;font-size:19px}label{display:flex;flex-direction:column;gap:6px;font-weight:600;font-size:14px}input,select,textarea{padding:11px 14px;border:1px solid #cfd8dd;border-radius:10px;font:inherit;width:100%}textarea{min-height:120px;resize:vertical}label.ck{flex-direction:row;align-items:center;gap:8px;font-weight:500}label.ck input{width:auto}.btn{background:var(--blue);color:#fff;border:0;border-radius:10px;padding:11px 20px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;width:auto}.btn:hover{background:var(--deep)}.btn.g{background:transparent;color:var(--green);border:2px solid var(--green)}.al{background:#fdecea;color:#b3261e;padding:10px 14px;border-radius:10px}.ok{background:#d4edda;color:#1e6b34;padding:10px 14px;border-radius:10px}.top{background:var(--deep);color:#fff;padding:12px 24px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}.top a{color:#D9C7A3;margin-left:14px;text-decoration:none}.nav{background:#fff;padding:0 24px;display:flex;gap:4px;flex-wrap:wrap;box-shadow:0 2px 8px rgba(0,0,0,.06)}.nav a{padding:14px 16px;text-decoration:none;color:#55666E;font-weight:600;border-bottom:3px solid transparent}.nav a.on{color:var(--blue);border-color:var(--blue)}.wrap{max-width:1100px;margin:24px auto;padding:0 16px;display:flex;flex-direction:column;gap:16px}.st{display:flex;gap:16px;flex-wrap:wrap}.st .card{flex:1;min-width:150px;display:flex;flex-direction:column}.st b{font-size:28px;color:var(--green)}.f{display:flex;gap:10px;flex-wrap:wrap}.f input{flex:1;min-width:200px}.f select{width:auto}.fm{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;align-items:end}.fm .full{grid-column:1/-1}.sc{overflow-x:auto;padding:8px 12px}table{width:100%;border-collapse:collapse}th,td{padding:12px 10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}th{background:#f4f6f8;color:var(--deep);font-size:13px}.nw{white-space:nowrap}.bd,.chip{padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700;display:inline-block;margin:2px 2px 2px 0}.bd.new{background:#fff3cd;color:#856404}.bd.processed,.bd.on{background:#d4edda;color:#1e6b34}.bd.off{background:#eee;color:#666}.chip{background:#e3ecf5;color:var(--deep)}.mi{border:1px solid #cfd8dd;background:#fff;border-radius:8px;padding:6px 10px;cursor:pointer;text-decoration:none;color:#1E2A30;font:inherit;font-size:14px;display:inline-block;width:auto}.mi:hover{background:#f0f4f6}.ph{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px}.ph div{background:#f4f6f8;border-radius:10px;overflow:hidden;font-size:13px}.ph img{width:100%;height:120px;object-fit:cover;display:block}.ph span{display:block;padding:8px}.th{width:60px;height:44px;object-fit:cover;border-radius:6px}
CSS;
    $page = function (string $body, string $nav = '', string $user = '') use ($css) {
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Админка — Одун Тур</title><style>' . $css . '</style></head>' . $body . '</html>';
    };

    // ---- текущий пользователь ----
    $me = null;
    if (!empty($_SESSION['uid'])) {
        $me = qr('SELECT u.*, r.name AS role_name, r.perms, r.is_system FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.active=1', [$_SESSION['uid']])->fetch() ?: null;
        if (!$me) unset($_SESSION['uid']);
    }
    if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); $go(); }

    // ---- вход ----
    if (!$me) {
        $err = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $chk();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if ((int)qr('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND created_at > (NOW() - INTERVAL 15 MINUTE)', [$ip])->fetchColumn() >= 5) {
                $err = 'Слишком много попыток. Подождите 15 минут.';
            } else {
                $u = qr('SELECT * FROM users WHERE login=? AND active=1', [trim($_POST['login'] ?? '')])->fetch();
                if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
                    session_regenerate_id(true); $_SESSION['uid'] = (int)$u['id'];
                    qr('DELETE FROM login_attempts WHERE ip=?', [$ip]); $go();
                }
                qr('INSERT INTO login_attempts (ip) VALUES (?)', [$ip]);
                $err = 'Неверный логин или пароль';
            }
        }
        ob_start(); ?>
<body class="c"><form class="card login" method="post" action="?admin"><h1>Одун Тур</h1><p class="m">Вход в админ-панель</p>
<?php if ($err): ?><div class="al"><?= h($err) ?></div><?php endif; ?>
<input type="hidden" name="csrf" value="<?= $csrf ?>"><label>Логин<input name="login" required autofocus autocomplete="username"></label>
<label>Пароль<input type="password" name="password" required autocomplete="current-password"></label><button class="btn">Войти</button></form></body>
<?php   $page(ob_get_clean()); exit;
    }

    // ---- права ----
    $all = array_keys(PERMS);
    $perms = role_perms($me);
    $can = function (string $x) use ($perms): bool { return in_array($x, $perms, true); };
    $pages = ['home' => ['Главная', ''], 'leads' => ['Заявки', 'leads'], 'posts' => ['Новости и посты', 'content'], 'photos' => ['Фото', 'content'],
              'users' => ['Пользователи', 'users'], 'roles' => ['Роли', 'roles'], 'me' => ['Мой профиль', '']];
    $p = $_GET['p'] ?? 'home';
    if (!isset($pages[$p]) || ($pages[$p][1] !== '' && !$can($pages[$p][1]))) $p = 'home';
    $myid = (int)$me['id'];
    $roleOk = function (int $rid) use ($perms) { // роль можно выдавать, только если её права не больше своих
        $r = qr('SELECT * FROM roles WHERE id=?', [$rid])->fetch();
        return $r && !array_diff(role_perms($r), $perms);
    };

    // ---- действия (POST) ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $chk();
        $do = $_POST['do'] ?? ''; $id = (int)($_POST['id'] ?? 0);
        try {
            if ($do === 'lead_toggle' && $can('leads')) qr("UPDATE leads SET status=IF(status='new','processed','new') WHERE id=?", [$id]);
            if ($do === 'lead_del' && $can('leads_del')) qr('DELETE FROM leads WHERE id=?', [$id]);
            if (in_array($do, ['lead_toggle', 'lead_del'], true)) $go(http_build_query(array_filter(['p' => 'leads', 'q' => $_POST['q'] ?? '', 'st' => $_POST['st'] ?? ''])));

            if ($do === 'user_save' && $can('users')) {
                $login = trim($_POST['login'] ?? ''); $name = trim($_POST['name'] ?? ''); $rid = (int)($_POST['role_id'] ?? 0);
                $act = isset($_POST['active']) ? 1 : 0; $pw = (string)($_POST['password'] ?? '');
                if ($id === $myid) { $rid = (int)$me['role_id']; $act = 1; }
                elseif (!$roleOk($rid)) { $flash('e', 'Эту роль выдать нельзя'); $go('p=users'); }
                if ($id) {
                    $t = qr('SELECT r.* FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id])->fetch();
                    if (!$t || array_diff(role_perms($t), $perms)) { $flash('e', 'Нет прав менять этого пользователя'); $go('p=users'); }
                    if ($pw !== '' && mb_strlen($pw) < 8) { $flash('e', 'Пароль — минимум 8 символов'); $go('p=users&edit=' . $id); }
                    qr('UPDATE users SET name=?, role_id=?, active=? WHERE id=?', [$name, $rid, $act, $id]);
                    if ($pw !== '') qr('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
                    $flash('ok', 'Пользователь сохранён');
                } else {
                    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $login)) { $flash('e', 'Логин: 3–50 символов, латиница, цифры, . _ -'); $go('p=users'); }
                    if (mb_strlen($pw) < 8) { $flash('e', 'Пароль — минимум 8 символов'); $go('p=users'); }
                    qr('INSERT INTO users (login,password_hash,name,role_id,active) VALUES (?,?,?,?,?)', [$login, password_hash($pw, PASSWORD_DEFAULT), $name, $rid, $act]);
                    $flash('ok', 'Пользователь «' . $login . '» добавлен');
                }
                $go('p=users');
            }
            if ($do === 'user_del' && $can('users')) {
                $t = qr('SELECT r.* FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id])->fetch();
                if ($id === $myid) $flash('e', 'Нельзя удалить самого себя');
                elseif (!$t || array_diff(role_perms($t), $perms)) $flash('e', 'Нет прав удалить этого пользователя');
                else { qr('DELETE FROM users WHERE id=?', [$id]); $flash('ok', 'Пользователь удалён'); }
                $go('p=users');
            }

            if ($do === 'role_save' && $can('roles')) {
                $name = trim($_POST['name'] ?? ''); $rp = array_values(array_intersect($all, (array)($_POST['perms'] ?? [])));
                if ($name === '' || mb_strlen($name) > 60) { $flash('e', 'Введите название роли'); $go('p=roles'); }
                if (array_diff($rp, $perms)) { $flash('e', 'Нельзя выдать права, которых нет у вас'); $go('p=roles'); }
                if ($id) {
                    $r = qr('SELECT * FROM roles WHERE id=?', [$id])->fetch();
                    if (!$r || $r['is_system']) { $flash('e', 'Системную роль менять нельзя'); $go('p=roles'); }
                    qr('UPDATE roles SET name=?, perms=? WHERE id=?', [$name, implode(',', $rp), $id]);
                } else {
                    qr('INSERT INTO roles (slug,name,perms) VALUES (?,?,?)', ['r' . bin2hex(random_bytes(4)), $name, implode(',', $rp)]);
                }
                $flash('ok', 'Роль сохранена'); $go('p=roles');
            }
            if ($do === 'role_del' && $can('roles')) {
                $r = qr('SELECT * FROM roles WHERE id=?', [$id])->fetch();
                if (!$r || $r['is_system']) $flash('e', 'Системную роль удалить нельзя');
                elseif ((int)qr('SELECT COUNT(*) FROM users WHERE role_id=?', [$id])->fetchColumn()) $flash('e', 'Роль назначена пользователям — сначала смените им роль');
                else { qr('DELETE FROM roles WHERE id=?', [$id]); $flash('ok', 'Роль удалена'); }
                $go('p=roles');
            }

            if ($do === 'post_save' && $can('content')) {
                $title = trim($_POST['title'] ?? ''); $body = trim($_POST['body'] ?? '');
                $type = ($_POST['type'] ?? '') === 'post' ? 'post' : 'news'; $pub = isset($_POST['published']) ? 1 : 0;
                if ($title === '' || mb_strlen($title) > 200) { $flash('e', 'Введите заголовок'); $go('p=posts'); }
                $img = !empty($_FILES['image']['tmp_name']) ? save_image($_FILES['image']) : null;
                if ($id) {
                    $old = qr('SELECT image FROM posts WHERE id=?', [$id])->fetchColumn();
                    $new = $old;
                    if ($img) { img_del($old ?: null); $new = $img; } elseif (isset($_POST['rm_image'])) { img_del($old ?: null); $new = null; }
                    qr('UPDATE posts SET type=?, title=?, body=?, image=?, published=? WHERE id=?', [$type, $title, $body, $new, $pub, $id]);
                } else {
                    qr('INSERT INTO posts (type,title,body,image,published,author) VALUES (?,?,?,?,?,?)', [$type, $title, $body, $img, $pub, $me['login']]);
                }
                $flash('ok', 'Сохранено'); $go('p=posts');
            }
            if ($do === 'post_del' && $can('content')) {
                img_del(qr('SELECT image FROM posts WHERE id=?', [$id])->fetchColumn() ?: null);
                qr('DELETE FROM posts WHERE id=?', [$id]); $flash('ok', 'Удалено'); $go('p=posts');
            }
            if ($do === 'photo_add' && $can('content')) {
                $n = 0; $title = trim($_POST['title'] ?? '');
                foreach ((array)($_FILES['files']['tmp_name'] ?? []) as $k => $tmp) {
                    if (!$tmp) continue;
                    $f = save_image(['tmp_name' => $tmp, 'error' => $_FILES['files']['error'][$k], 'size' => $_FILES['files']['size'][$k]]);
                    if ($f) { qr('INSERT INTO photos (title,file) VALUES (?,?)', [$title, $f]); $n++; }
                }
                $n ? $flash('ok', 'Загружено фото: ' . $n) : $flash('e', 'Не удалось загрузить: нужны JPG, PNG, GIF или WEBP до 8 МБ');
                $go('p=photos');
            }
            if ($do === 'photo_del' && $can('content')) {
                img_del(qr('SELECT file FROM photos WHERE id=?', [$id])->fetchColumn() ?: null);
                qr('DELETE FROM photos WHERE id=?', [$id]); $flash('ok', 'Фото удалено'); $go('p=photos');
            }
            if ($do === 'pw_change') {
                $new = (string)($_POST['new'] ?? '');
                if (!password_verify($_POST['old'] ?? '', $me['password_hash'])) $flash('e', 'Текущий пароль неверный');
                elseif (mb_strlen($new) < 8) $flash('e', 'Новый пароль — минимум 8 символов');
                else { qr('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $myid]); $flash('ok', 'Пароль изменён'); }
                $go('p=me');
            }
        } catch (PDOException $e) {
            $flash('e', $e->getCode() === '23000' ? 'Такой логин уже существует' : (DEBUG ? $e->getMessage() : 'Ошибка базы данных'));
            $go('p=' . $p);
        }
        $go();
    }

    // ---- данные страниц ----
    $edit = (int)($_GET['edit'] ?? 0);
    $fl = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    $hid = '<input type="hidden" name="csrf" value="' . $csrf . '">';
    $roles = qr('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) AS cnt FROM roles r ORDER BY r.is_system DESC, r.id')->fetchAll();

    if ($p === 'leads') {
        $q = trim($_GET['q'] ?? ''); $st = in_array($_GET['st'] ?? '', ['new', 'processed'], true) ? $_GET['st'] : '';
        $w = []; $a = [];
        if ($q !== '') { $w[] = '(name LIKE ? OR phone LIKE ? OR comment LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
        if ($st !== '') { $w[] = 'status=?'; $a[] = $st; }
        $leads = qr('SELECT * FROM leads' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY created_at DESC, id DESC', $a)->fetchAll();
        if (isset($_GET['csv'])) {
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
            echo "\xEF\xBB\xBF"; $f = fopen('php://output', 'w');
            fputcsv($f, ['ID', 'Дата', 'ФИО', 'Телефон', 'Комментарий', 'Статус'], ';');
            foreach ($leads as $r) fputcsv($f, [$r['id'], $r['created_at'], $r['name'], $r['phone'], $r['comment'], $r['status']], ';');
            exit;
        }
    }

    // ---- вывод ----
    ob_start(); ?>
<body>
<div class="top"><strong>Одун Тур · Админ-панель</strong><span><a href="./" target="_blank">Сайт</a><a href="?admin&logout=1">Выйти (<?= h($me['login']) ?>)</a></span></div>
<nav class="nav"><?php foreach ($pages as $k => [$t, $pm]) if ($pm === '' || $can($pm)): ?><a class="<?= $k === $p ? 'on' : '' ?>" href="?admin&p=<?= $k ?>"><?= h($t) ?></a><?php endif; ?></nav>
<div class="wrap">
<?php if ($fl): ?><div class="<?= $fl[0] === 'ok' ? 'ok' : 'al' ?>"><?= h($fl[1]) ?></div><?php endif; ?>

<?php if ($p === 'home'): ?>
  <div class="card"><h2>Здравствуйте, <?= h($me['name'] ?: $me['login']) ?>!</h2><p class="m">Ваша роль: <b><?= h($me['role_name']) ?></b></p></div>
  <div class="st">
  <?php if ($can('leads')): ?><div class="card"><b><?= (int)qr("SELECT COUNT(*) FROM leads WHERE status='new'")->fetchColumn() ?></b><span class="m">новых заявок</span></div>
    <div class="card"><b><?= (int)qr('SELECT COUNT(*) FROM leads')->fetchColumn() ?></b><span class="m">всего заявок</span></div><?php endif; ?>
  <?php if ($can('content')): ?><div class="card"><b><?= (int)qr('SELECT COUNT(*) FROM posts')->fetchColumn() ?></b><span class="m">новостей и постов</span></div>
    <div class="card"><b><?= (int)qr('SELECT COUNT(*) FROM photos')->fetchColumn() ?></b><span class="m">фото в галерее</span></div><?php endif; ?>
  <?php if ($can('users')): ?><div class="card"><b><?= (int)qr('SELECT COUNT(*) FROM users')->fetchColumn() ?></b><span class="m">пользователей</span></div><?php endif; ?>
  <?php if ($can('roles')): ?><div class="card"><b><?= count($roles) ?></b><span class="m">ролей</span></div><?php endif; ?>
  </div>

<?php elseif ($p === 'leads'): ?>
  <form class="f" method="get"><input type="hidden" name="admin" value="1"><input type="hidden" name="p" value="leads"><input name="q" value="<?= h($q) ?>" placeholder="Поиск по имени, телефону, комментарию">
    <select name="st"><option value="">Все</option><option value="new"<?= $st === 'new' ? ' selected' : '' ?>>Новые</option><option value="processed"<?= $st === 'processed' ? ' selected' : '' ?>>Обработанные</option></select>
    <button class="btn">Найти</button><a class="btn g" href="?admin&p=leads&csv=1&q=<?= urlencode($q) ?>&st=<?= h($st) ?>">Скачать CSV</a></form>
  <div class="card sc"><?php if (!$leads): ?><p class="m" style="padding:30px;text-align:center">Заявок не найдено.</p><?php else: ?>
  <table><thead><tr><th>Дата</th><th>ФИО</th><th>Телефон</th><th>Комментарий</th><th>Статус</th><th></th></tr></thead><tbody>
  <?php foreach ($leads as $r): ?><tr>
    <td class="nw"><?= h(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td><td><strong><?= h($r['name']) ?></strong></td>
    <td class="nw"><a href="tel:<?= h($r['phone']) ?>"><?= h($r['phone']) ?></a></td><td><?= $r['comment'] ? nl2br(h($r['comment'])) : '—' ?></td>
    <td><span class="bd <?= $r['status'] ?>"><?= $r['status'] === 'new' ? 'Новая' : 'Обработана' ?></span></td>
    <td class="nw"><?php foreach (['lead_toggle' => ['✓', 'leads', ''], 'lead_del' => ['🗑', 'leads_del', "return confirm('Удалить заявку?')"]] as $act => [$ico, $pm, $js]) if ($can($pm)): ?>
      <form method="post" style="display:inline" onsubmit="<?= h($js) ?>"><?= $hid ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="st" value="<?= h($st) ?>"><button class="mi" name="do" value="<?= $act ?>"><?= $ico ?></button></form>
    <?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>

<?php elseif ($p === 'users'):
    $eu = $edit ? qr('SELECT * FROM users WHERE id=?', [$edit])->fetch() : null;
    $users = qr('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id')->fetchAll(); ?>
  <form class="card" method="post"><h2><?= $eu ? 'Редактировать: ' . h($eu['login']) : 'Добавить пользователя' ?></h2><?= $hid ?><input type="hidden" name="do" value="user_save"><input type="hidden" name="id" value="<?= $eu ? (int)$eu['id'] : 0 ?>">
    <div class="fm">
      <label>Логин<input name="login" value="<?= h($eu['login'] ?? '') ?>" <?= $eu ? 'readonly' : 'required' ?> autocomplete="off"></label>
      <label>Имя<input name="name" value="<?= h($eu['name'] ?? '') ?>"></label>
      <label>Роль<select name="role_id" <?= $eu && (int)$eu['id'] === $myid ? 'disabled' : '' ?>><?php foreach ($roles as $r) if ($roleOk((int)$r['id']) || ($eu && (int)$eu['role_id'] === (int)$r['id'])): ?><option value="<?= $r['id'] ?>" <?= ($eu['role_id'] ?? 0) == $r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option><?php endif; ?></select></label>
      <label>Пароль<input type="password" name="password" <?= $eu ? '' : 'required' ?> placeholder="<?= $eu ? 'оставьте пустым, чтобы не менять' : 'минимум 8 символов' ?>" autocomplete="new-password"></label>
      <label class="ck"><input type="checkbox" name="active" <?= !$eu || $eu['active'] ? 'checked' : '' ?> <?= $eu && (int)$eu['id'] === $myid ? 'disabled' : '' ?>> Активен (может входить)</label>
      <div><button class="btn"><?= $eu ? 'Сохранить' : 'Добавить' ?></button> <?php if ($eu): ?><a class="btn g" href="?admin&p=users">Отмена</a><?php endif; ?></div>
    </div></form>
  <div class="card sc"><table><thead><tr><th>Логин</th><th>Имя</th><th>Роль</th><th>Статус</th><th>Создан</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): ?><tr><td><strong><?= h($u['login']) ?></strong><?= (int)$u['id'] === $myid ? ' <span class="m">(вы)</span>' : '' ?></td><td><?= h($u['name']) ?></td><td><span class="chip"><?= h($u['role_name']) ?></span></td>
    <td><span class="bd <?= $u['active'] ? 'on' : 'off' ?>"><?= $u['active'] ? 'Активен' : 'Отключён' ?></span></td><td class="nw"><?= h(date('d.m.Y', strtotime($u['created_at']))) ?></td>
    <td class="nw"><a class="mi" href="?admin&p=users&edit=<?= $u['id'] ?>">✎</a>
    <?php if ((int)$u['id'] !== $myid): ?><form method="post" style="display:inline" onsubmit="return confirm('Удалить пользователя?')"><?= $hid ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="mi" name="do" value="user_del">🗑</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>

<?php elseif ($p === 'roles'):
    $er = null; foreach ($roles as $r) if ((int)$r['id'] === $edit && !$r['is_system']) $er = $r;
    $erp = $er ? role_perms($er) : []; ?>
  <form class="card" method="post"><h2><?= $er ? 'Редактировать роль' : 'Новая роль' ?></h2><?= $hid ?><input type="hidden" name="do" value="role_save"><input type="hidden" name="id" value="<?= $er ? (int)$er['id'] : 0 ?>">
    <div class="fm"><label class="full">Название роли<input name="name" value="<?= h($er['name'] ?? '') ?>" required maxlength="60"></label>
    <?php foreach (PERMS as $k => $t): ?><label class="ck"><input type="checkbox" name="perms[]" value="<?= $k ?>" <?= in_array($k, $erp, true) ? 'checked' : '' ?> <?= $can($k) ? '' : 'disabled' ?>> <?= h($t) ?></label><?php endforeach; ?>
    <div class="full"><button class="btn"><?= $er ? 'Сохранить' : 'Создать роль' ?></button> <?php if ($er): ?><a class="btn g" href="?admin&p=roles">Отмена</a><?php endif; ?></div></div></form>
  <div class="card sc"><table><thead><tr><th>Роль</th><th>Права</th><th>Польз.</th><th></th></tr></thead><tbody>
  <?php foreach ($roles as $r): ?><tr><td><strong><?= h($r['name']) ?></strong><?= $r['is_system'] ? ' <span class="m">(системная)</span>' : '' ?></td>
    <td><?php foreach (role_perms($r) as $k) echo '<span class="chip">' . h(PERMS[$k] ?? $k) . '</span>'; ?></td><td><?= (int)$r['cnt'] ?></td>
    <td class="nw"><?php if (!$r['is_system']): ?><a class="mi" href="?admin&p=roles&edit=<?= $r['id'] ?>">✎</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Удалить роль?')"><?= $hid ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="mi" name="do" value="role_del">🗑</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>

<?php elseif ($p === 'posts'):
    $ep = $edit ? qr('SELECT * FROM posts WHERE id=?', [$edit])->fetch() : null;
    $posts = qr('SELECT * FROM posts ORDER BY created_at DESC, id DESC')->fetchAll(); ?>
  <form class="card" method="post" enctype="multipart/form-data"><h2><?= $ep ? 'Редактировать запись' : 'Новая запись' ?></h2><?= $hid ?><input type="hidden" name="do" value="post_save"><input type="hidden" name="id" value="<?= $ep ? (int)$ep['id'] : 0 ?>">
    <div class="fm"><label>Тип<select name="type"><option value="news" <?= ($ep['type'] ?? '') === 'news' ? 'selected' : '' ?>>Новость</option><option value="post" <?= ($ep['type'] ?? '') === 'post' ? 'selected' : '' ?>>Пост</option></select></label>
      <label>Заголовок<input name="title" value="<?= h($ep['title'] ?? '') ?>" required maxlength="200"></label>
      <label class="full">Текст<textarea name="body"><?= h($ep['body'] ?? '') ?></textarea></label>
      <label>Картинка (JPG, PNG, WEBP до 8 МБ)<input type="file" name="image" accept="image/*"></label>
      <?php if ($ep && $ep['image']): ?><label class="ck"><img class="th" src="uploads/<?= h($ep['image']) ?>" alt=""><input type="checkbox" name="rm_image"> убрать картинку</label><?php endif; ?>
      <label class="ck"><input type="checkbox" name="published" <?= !$ep || $ep['published'] ? 'checked' : '' ?>> Опубликовано на сайте</label>
      <div><button class="btn"><?= $ep ? 'Сохранить' : 'Добавить' ?></button> <?php if ($ep): ?><a class="btn g" href="?admin&p=posts">Отмена</a><?php endif; ?></div></div></form>
  <div class="card sc"><?php if (!$posts): ?><p class="m" style="padding:30px;text-align:center">Записей пока нет.</p><?php else: ?><table><thead><tr><th></th><th>Заголовок</th><th>Тип</th><th>Статус</th><th>Дата</th><th></th></tr></thead><tbody>
  <?php foreach ($posts as $r): ?><tr><td><?= $r['image'] ? '<img class="th" src="uploads/' . h($r['image']) . '" alt="">' : '' ?></td><td><strong><?= h($r['title']) ?></strong></td><td><?= $r['type'] === 'news' ? 'Новость' : 'Пост' ?></td>
    <td><span class="bd <?= $r['published'] ? 'on' : 'off' ?>"><?= $r['published'] ? 'На сайте' : 'Черновик' ?></span></td><td class="nw"><?= h(date('d.m.Y', strtotime($r['created_at']))) ?></td>
    <td class="nw"><a class="mi" href="?admin&p=posts&edit=<?= $r['id'] ?>">✎</a><form method="post" style="display:inline" onsubmit="return confirm('Удалить запись?')"><?= $hid ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="mi" name="do" value="post_del">🗑</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>

<?php elseif ($p === 'photos'): $photos = qr('SELECT * FROM photos ORDER BY id DESC')->fetchAll(); ?>
  <form class="card" method="post" enctype="multipart/form-data"><h2>Загрузить фото в галерею</h2><?= $hid ?><input type="hidden" name="do" value="photo_add">
    <div class="fm"><label>Подпись (необязательно)<input name="title" maxlength="150"></label><label>Файлы (можно несколько)<input type="file" name="files[]" accept="image/*" multiple required></label><div><button class="btn">Загрузить</button></div></div></form>
  <div class="card"><?php if (!$photos): ?><p class="m" style="text-align:center;padding:20px">Фото пока нет.</p><?php else: ?><div class="ph"><?php foreach ($photos as $r): ?>
    <div><img src="uploads/<?= h($r['file']) ?>" alt=""><span><?= h($r['title'] ?: '—') ?><form method="post" style="display:inline;float:right" onsubmit="return confirm('Удалить фото?')"><?= $hid ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="mi" name="do" value="photo_del">🗑</button></form></span></div><?php endforeach; ?></div><?php endif; ?></div>

<?php else: ?>
  <form class="card" method="post" style="max-width:480px"><h2>Сменить пароль</h2><?= $hid ?><input type="hidden" name="do" value="pw_change">
    <div class="fm"><label class="full">Текущий пароль<input type="password" name="old" required autocomplete="current-password"></label><label class="full">Новый пароль (минимум 8 символов)<input type="password" name="new" required minlength="8" autocomplete="new-password"></label><div><button class="btn">Изменить</button></div></div></form>
<?php endif; ?>
</div></body>
<?php
    $page(ob_get_clean());
}

// ---------- АДМИНКА: /index.php?admin ----------
if (isset($_GET['admin'])) {
    try { admin_main(); }
    catch (Throwable $e) { echo 'Ошибка: ' . h(DEBUG ? $e->getMessage() : 'server'); }
    exit;
}
// ---------- САЙТ ----------
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ОДУН ТУР — Туры по Якутии: Ленские столбы, бизонарий, экспедиции</title>
<meta name="description" content="Авторские туры по Якутии: Ленские столбы, питомник лесных бизонов, сплавы и зимние экспедиции. Местные гиды, малые группы, маршруты без туристов.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
/* ============ ПЕРЕМЕННЫЕ ПАЛИТРЫ ============ */
:root{
  --bg:#EDF1F4; 
  --bg-soft:#F7F9FA;
  --green:#2F5D50; 
  --green-soft:#3E7A68;
  --blue:#1F4E79; 
  --blue-deep:#163A5A;
  --sand:#D9C7A3;
  --text:#1E2A30; 
  --text-muted:#55666E;
  --white:#FFFFFF; 
  --radius:14px;
  --shadow:0 10px 34px rgba(22,58,90,.12);
  --shadow-hover:0 18px 46px rgba(22,58,90,.20);
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'Manrope',sans-serif;background:var(--bg);color:var(--text);line-height:1.65;font-size:17px;-webkit-font-smoothing:antialiased;}
h1,h2,h3{font-family:'Playfair Display',serif;line-height:1.18;color:var(--green);}
img{max-width:100%;display:block;}
a{text-decoration:none;color:inherit;}
.container{max-width:1180px;margin:0 auto;padding:0 24px;}
section{scroll-margin-top:90px;}

/* ============ КНОПКИ ============ */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;background:var(--blue);color:var(--white);font-family:'Manrope',sans-serif;font-weight:700;font-size:16px;padding:16px 34px;border-radius:50px;border:none;cursor:pointer;transition:background .2s,transform .2s,box-shadow .2s;box-shadow:0 8px 22px rgba(31,78,121,.30);}
.btn:hover{background:var(--blue-deep);transform:translateY(-2px);}
.btn--green{background:var(--green);}
.btn--green:hover{background:var(--green-soft);}
.btn--outline{background:transparent;color:var(--green);border:2px solid var(--green);box-shadow:none;}
.btn--outline:hover{background:var(--green);color:var(--white);}
.btn--light{background:var(--white);color:var(--blue);}
.btn--light:hover{background:var(--sand);color:var(--blue-deep);}

/* ============ МОДАЛЬНЫЕ ОКНА ============ */
.modal-overlay {
  position:fixed; top:0; left:0; width:100%; height:100%;
  background:rgba(22,58,90,0.7); backdrop-filter:blur(5px);
  display:flex; align-items:center; justify-content:center;
  z-index:3000; opacity:0; visibility:hidden; transition:all .3s;
  padding: 20px;
}
.modal-overlay.active { opacity:1; visibility:visible; }
.modal {
  background:var(--white); border-radius:20px; padding:40px;
  width:100%; max-width:500px; position:relative;
  transform:translateY(20px); transition:transform .3s;
  box-shadow:0 24px 60px rgba(0,0,0,0.3);
  max-height: 90vh; overflow-y: auto;
}
.modal-overlay.active .modal { transform:translateY(0); }
.modal__close {
  position:absolute; top:20px; right:20px; background:none; border:none;
  font-size:28px; cursor:pointer; color:var(--text-muted); transition:color .2s;
  line-height:1;
}
.modal__close:hover { color:var(--blue); }
.modal h3 { font-size:28px; margin-bottom:8px; color:var(--green); }
.modal p { color:var(--text-muted); font-size:15px; margin-bottom:24px; }
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:14px; font-weight:600; margin-bottom:6px; color:var(--text); }
.form-group input, .form-group textarea {
  width:100%; padding:14px 16px; border:1px solid #ccc; border-radius:10px;
  font-family:'Manrope',sans-serif; font-size:15px; outline:none; transition:border-color .2s;
}
.form-group input:focus, .form-group textarea:focus { border-color:var(--blue); }
.form-group textarea { resize:vertical; min-height:80px; }
.modal .btn { width:100%; margin-top:10px; }

/* ============ АДМИНКА (ТАБЛИЦА ЗАЯВОК) ============ */

/* ============ ШАПКА ============ */
.header{position:sticky;top:0;z-index:1000;background:rgba(237,241,244,.94);backdrop-filter:blur(12px);border-bottom:1px solid rgba(31,78,121,.12);}
.header__inner{display:flex;align-items:center;justify-content:space-between;height:72px;position:relative;}
.brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.brand__logo{width:48px;height:48px;object-fit:contain;}
.logo{font-family:'Playfair Display',serif;font-size:24px;font-weight:700;color:var(--green);line-height:1;}
.logo span{color:var(--blue);}
.nav{display:flex;gap:30px;align-items:center;}
.nav a{font-weight:600;font-size:15px;color:var(--text);position:relative;padding:6px 0;}
.nav a:hover{color:var(--blue);}
.nav a::after{content:'';position:absolute;left:0;bottom:0;width:0;height:2px;background:var(--blue);transition:width .25s;}
.nav a:hover::after{width:100%;}
.header .btn{padding:11px 24px;font-size:14px;}
.burger{display:none;background:none;border:none;cursor:pointer;flex-direction:column;gap:5px;padding:8px;z-index:1100;}
.burger span{display:block;width:26px;height:2.5px;background:var(--green);border-radius:2px;transition:.3s;}

/* ============ ГЛАВНЫЙ ЭКРАН ============ */
.hero{position:relative;min-height:90vh;display:flex;align-items:center;color:var(--white);
  background:
    linear-gradient(115deg,rgba(22,58,90,.86) 0%,rgba(47,93,80,.68) 55%,rgba(22,58,90,.55) 100%),
    url('https://static.tildacdn.com/tild6664-6634-4463-a163-663733623834/47a3fad2a2dd512a13fe.jpg') center/cover no-repeat;
}
.hero__content{max-width:780px;padding:90px 0;}
.hero__uptitle{text-transform:uppercase;letter-spacing:4px;font-size:13px;font-weight:800;color:var(--sand);margin-bottom:22px;display:inline-block;border-left:3px solid var(--sand);padding-left:14px;}
.hero h1{font-size:clamp(38px,5.5vw,68px);color:var(--white);margin-bottom:24px;}
.hero p{font-size:clamp(17px,1.6vw,20px);max-width:640px;color:rgba(255,255,255,.94);margin-bottom:38px;}
.hero__actions{display:flex;gap:16px;flex-wrap:wrap;}
.hero .btn--outline{color:var(--white);border-color:rgba(255,255,255,.75);}
.hero .btn--outline:hover{background:var(--white);color:var(--green);}
.hero__badges{display:flex;gap:26px;flex-wrap:wrap;margin-top:52px;}
.hero__badge{font-size:14px;display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.9);}
.hero__badge::before{content:'✓';display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--sand);color:var(--green);font-size:12px;font-weight:800;flex-shrink:0;}

/* ============ СЕКЦИИ ============ */
.section{padding:96px 0;}
.section--soft{background:var(--bg-soft);}
.section__head{max-width:700px;margin:0 auto 56px;text-align:center;}
.section__uptitle{text-transform:uppercase;letter-spacing:3px;font-size:12px;font-weight:800;color:var(--blue);margin-bottom:14px;display:block;}
.section__head h2{font-size:clamp(30px,3.6vw,44px);margin-bottom:16px;}
.section__head p{color:var(--text-muted);font-size:17px;}

/* ============ ПОЧЕМУ МЫ ============ */
.features{display:grid;grid-template-columns:repeat(3,1fr);gap:28px;}
.feature{background:var(--white);border-radius:var(--radius);padding:40px 32px;box-shadow:var(--shadow);transition:transform .25s,box-shadow .25s;border-top:4px solid var(--blue);}
.feature:nth-child(2){border-top-color:var(--green-soft);}
.feature:nth-child(3){border-top-color:var(--sand);}
.feature:hover{transform:translateY(-6px);box-shadow:var(--shadow-hover);}
.feature__icon{width:64px;height:64px;border-radius:18px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--green-soft),var(--green));margin-bottom:24px;box-shadow:0 6px 16px rgba(47,93,80,.2);}
.feature__icon svg{width:32px;height:32px;stroke:#ffffff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
.feature h3{font-size:21px;margin-bottom:12px;}
.feature p{color:var(--text-muted);font-size:15.5px;}

/* ============ НАПРАВЛЕНИЯ ============ */
.tours{display:grid;grid-template-columns:repeat(2,1fr);gap:28px;}
.tour-card{position:relative;border-radius:20px;overflow:hidden;min-height:480px;display:flex;align-items:flex-end;text-decoration:none;box-shadow:0 8px 30px rgba(0,0,0,0.15);transition:transform .4s cubic-bezier(0.25, 0.46, 0.45, 0.94), box-shadow .4s;}
.tour-card:hover{ transform:translateY(-8px); box-shadow:0 20px 40px rgba(0,0,0,0.25); }
.tour-card__bg{position:absolute;inset:0;background-size:cover;background-position:center;transition:transform .8s cubic-bezier(0.25, 0.46, 0.45, 0.94);}
.tour-card:hover .tour-card__bg{transform:scale(1.08);}
.tour-card::after{content:'';position:absolute;inset:0;background:linear-gradient(to top, rgba(14, 30, 45, 0.95) 0%, rgba(14, 30, 45, 0.4) 60%, rgba(14, 30, 45, 0.1) 100%);transition:opacity .4s;}
.tour-card__body{position:relative;z-index:2;padding:40px;color:var(--white);width:100%;display:flex;flex-direction:column;gap:16px;}
.tour-card__tag{align-self:flex-start;background:var(--sand);color:var(--green);font-weight:800;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;padding:8px 16px;border-radius:30px;}
.tour-card h3{ color:var(--white);font-size:32px;margin:0;font-weight:700; text-shadow:0 2px 10px rgba(0,0,0,0.3); }
.tour-card p{ font-size:16px;color:rgba(255,255,255,0.9);margin:0;line-height:1.5;max-width:90%; text-shadow:0 1px 5px rgba(0,0,0,0.3); }
.tour-card .btn{margin-top:8px;align-self:flex-start;background:rgba(255,255,255,0.15);backdrop-filter:blur(5px);color:var(--white);border:1px solid rgba(255,255,255,0.3);box-shadow:none;padding:14px 32px;font-size:15px;font-weight:700;transition:all .3s;}
.tour-card:hover .btn{ background:var(--white);color:var(--green);border-color:var(--white); transform:translateY(-2px); }

/* ============ БЛИЖАЙШИЕ ТУРЫ ============ */
.upcoming{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;}
.trip{background:var(--white);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);display:flex;flex-direction:column;transition:transform .25s,box-shadow .25s;}
.trip:hover{transform:translateY(-5px);box-shadow:var(--shadow-hover);}
.trip__img{height:210px;background-size:cover;background-position:center;position:relative;}
.trip__date{position:absolute;top:14px;left:14px;background:var(--white);color:var(--blue);font-weight:800;font-size:13px;padding:7px 14px;border-radius:30px;box-shadow:0 3px 10px rgba(0,0,0,.18);}
.trip__body{padding:26px;display:flex;flex-direction:column;flex:1;}
.trip__body h3{font-size:20px;margin-bottom:8px;}
.trip__meta{font-size:14px;color:var(--text-muted);margin-bottom:18px;flex:1;}
.trip__price{font-family:'Playfair Display',serif;font-size:25px;color:var(--blue);font-weight:700;margin-bottom:18px;}
.trip__price small{font-size:13px;color:var(--text-muted);font-family:'Manrope',sans-serif;font-weight:500;}
.trip .btn{width:100%;padding:13px;font-size:15px;}

/* ============ ГАЛЕРЕЯ ============ */
.gallery{display:grid;grid-template-columns:repeat(3,1fr);grid-auto-rows:240px;gap:16px;}
.gallery a{position:relative;overflow:hidden;border-radius:14px;display:block;box-shadow:var(--shadow);transition:transform .3s,box-shadow .3s;background:#cfd8dd;}
.gallery a:hover{transform:scale(1.02);box-shadow:var(--shadow-hover);}
.gallery img{width:100%;height:100%;object-fit:cover;transition:transform .5s;}
.gallery a:hover img{transform:scale(1.08);}
.gallery .tall{grid-row:span 2;}
.gallery .wide{grid-column:span 2;}
.news{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
.news article{background:#fff;border-radius:14px;box-shadow:var(--shadow);overflow:hidden;}
.news article img{width:100%;height:200px;object-fit:cover;display:block;}
.news .nb{padding:18px;}
.news time{font-size:13px;opacity:.7;}
.news h3{margin:6px 0 8px;}
.news p{line-height:1.6;}
@media(max-width:900px){.news{grid-template-columns:1fr;}}

/* ============ ОТЗЫВЫ ============ */
.reviews{display:grid;grid-template-columns:repeat(2,1fr);gap:28px;}
.review{background:var(--white);border-radius:var(--radius);padding:38px;box-shadow:var(--shadow);border-left:4px solid var(--blue);}
.review__text{font-size:16px;color:var(--text);margin-bottom:24px;}
.review__author{display:flex;align-items:center;gap:14px;}
.review__avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--green-soft),var(--green));color:var(--white);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex-shrink:0;}
.review__name{font-weight:700;font-size:15px;}
.review__tour{font-size:13px;color:var(--blue);font-weight:600;}

/* ============ О ЯКУТИИ ============ */
.about{display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;}
.about__img{border-radius:16px;overflow:hidden;box-shadow:var(--shadow);}
.about__img img{width:100%;height:500px;object-fit:cover;}
.about h2{font-size:clamp(28px,3.4vw,42px);margin-bottom:22px;}
.about p{color:var(--text-muted);margin-bottom:18px;font-size:16.5px;}
.about__stats{display:flex;gap:40px;margin:32px 0;flex-wrap:wrap;}
.about__stat b{font-family:'Playfair Display',serif;font-size:36px;color:var(--blue);display:block;}
.about__stat span{font-size:14px;color:var(--text-muted);}

/* ============ CTA ============ */
.cta{position:relative;color:var(--white);text-align:center;padding:110px 0;overflow:hidden;
  background:
    linear-gradient(120deg,rgba(31,78,121,.92) 0%,rgba(47,93,80,.88) 100%),
    url('https://static.tildacdn.com/tild3539-6330-4366-a139-663130356533/IMG_5410.JPEG') center/cover no-repeat;
}
.cta h2{color:var(--white);font-size:clamp(30px,4vw,48px);margin-bottom:18px;}
.cta p{color:rgba(255,255,255,.92);max-width:620px;margin:0 auto 36px;font-size:18px;}
.cta .btn{font-size:17px;padding:18px 40px;}

/* ============ ПОДВАЛ ============ */
.footer{background:var(--blue-deep);color:rgba(255,255,255,.85);padding:64px 0 28px;}
.footer__grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:48px;margin-bottom:48px;}
.footer .brand{margin-bottom:16px;}
.footer .logo{color:var(--white);}
.footer p{font-size:15px;line-height:1.7;max-width:320px;}
.footer h4{color:var(--white);font-family:'Manrope',sans-serif;font-size:14px;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:18px;}
.footer a{display:block;font-size:15px;margin-bottom:10px;transition:color .2s;}
.footer a:hover{color:var(--sand);}
.footer__contacts a{display:flex;align-items:center;gap:10px;margin-bottom:14px;font-size:16px;font-weight:600;}
.footer__bottom{border-top:1px solid rgba(255,255,255,.15);padding-top:24px;text-align:center;font-size:13px;color:rgba(255,255,255,.6); position:relative;}


/* ============ АДАПТИВ ============ */
@media(max-width:980px){
  .features{grid-template-columns:1fr;}
  .tours{grid-template-columns:1fr;}
  .upcoming{grid-template-columns:1fr 1fr;}
  .reviews{grid-template-columns:1fr;}
  .about{grid-template-columns:1fr;gap:36px;}
  .about__img img{height:360px;}
  .gallery{grid-template-columns:repeat(2,1fr);grid-auto-rows:200px;}
  .gallery .wide{grid-column:span 1;}
  .footer__grid{grid-template-columns:1fr 1fr;gap:32px;}
}
@media(max-width:760px){
  .nav{display:none;position:absolute;top:72px;left:0;right:0;flex-direction:column;align-items:flex-start;gap:0;background:var(--bg-soft);padding:12px 24px 24px;box-shadow:0 12px 30px rgba(22,58,90,.15);border-bottom:1px solid rgba(31,78,121,.12);}
  .nav.open{display:flex;}
  .nav a{padding:14px 0;width:100%;border-bottom:1px solid rgba(31,78,121,.08);}
  .nav a::after{display:none;}
  .header .btn{display:none;}
  .burger{display:flex;}
  .burger.open span:nth-child(1){transform:translateY(7.5px) rotate(45deg);}
  .burger.open span:nth-child(2){opacity:0;}
  .burger.open span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg);}
  .hero{min-height:auto;padding:60px 0;}
  .hero__content{padding:40px 0;}
  .upcoming{grid-template-columns:1fr;}
  .section{padding:64px 0;}
  .footer__grid{grid-template-columns:1fr;}
  .tour-card{min-height:400px;}
  .tour-card__body{padding:30px;}
  .tour-card h3{font-size:28px;}
  .gallery{grid-template-columns:1fr;grid-auto-rows:220px;}
  .gallery .tall{grid-row:span 1;}
  .modal { padding: 30px 20px; width: 90%; }
}
</style>
</head>
<body>

<!-- ===== МОДАЛЬНОЕ ОКНО: ФОРМА ===== -->
<div class="modal-overlay" id="callbackModal">
  <div class="modal">
    <button class="modal__close" id="closeModal">&times;</button>
    <h3>Подобрать тур</h3>
    <p>Оставьте свои данные, и мы свяжемся с вами для подбора идеального маршрута.</p>
    <form id="callbackForm" action="?api=lead" method="POST">
      <div class="form-group">
        <label for="name">Ваше ФИО</label>
        <input type="text" id="name" required placeholder="Иванов Иван Иванович">
      </div>
      <div class="form-group">
        <label for="phone">Номер телефона</label>
        <input type="tel" id="phone" required placeholder="+7 (999) 123-45-67">
      </div>
      <div class="form-group">
        <label for="comment">Комментарий (пожелания, даты)</label>
        <textarea id="comment" placeholder="Хочу тур на Ленские столбы в июле..."></textarea>
      </div>
      <button type="submit" class="btn" id="submitBtn">Отправить заявку</button>
    </form>
  </div>
</div>

<!-- ===== ШАПКА ===== -->
<header class="header">
  <div class="container header__inner">
    <a href="#top" class="brand">
      <img src="https://static.tildacdn.com/tild3566-3933-4537-a162-346533656162/E84C6010-BB97-4E47-8.png" alt="Логотип Одун Тур" class="brand__logo">
      <span class="logo">ОДУН<span>ТУР</span></span>
    </a>
    <nav class="nav" id="nav">
      <a href="#why">Почему мы</a>
      <a href="#tours">Направления</a>
      <a href="#upcoming">Ближайшие туры</a>
      <a href="#gallery">Галерея</a>
      <a href="#about">О Якутии</a>
      <a href="#reviews">Отзывы</a>
      <a href="#contacts">Контакты</a>
    </nav>
    <a href="#" class="btn open-modal-btn">Подобрать тур</a>
    <button class="burger" id="burger" aria-label="Меню" aria-expanded="false"><span></span><span></span><span></span></button>
  </div>
</header>

<!-- ===== ГЛАВНЫЙ ЭКРАН ===== -->
<section class="hero" id="top">
  <div class="container">
    <div class="hero__content">
      <span class="hero__uptitle">Туры по Якутии</span>
      <h1>Якутия, которую вы не видели</h1>
      <p>Ленские столбы, питомник лесных бизонов, сплавы по северным рекам и зимние экспедиции. Организуем маршруты без туристов — с проводниками, которые знают Север.</p>
      <div class="hero__actions">
        <a href="#" class="btn open-modal-btn">Подобрать тур</a>
        <a class="btn btn--outline" href="#tours">Смотреть направления</a>
      </div>
      <div class="hero__badges">
        <span class="hero__badge">Местные гиды</span>
        <span class="hero__badge">Малые группы до 8 человек</span>
        <span class="hero__badge">Маршруты без туристов</span>
      </div>
    </div>
  </div>
</section>

<!-- ===== ПОЧЕМУ МЫ ===== -->
<section class="section" id="why">
  <div class="container">
    <div class="section__head">
      <span class="section__uptitle">Почему Одун Тур</span>
      <h2>Путешествие, которому можно доверять</h2>
      <p>Мы не продаём «туры по каталогу». Мы живём здесь и показываем Якутию так, как её видят местные.</p>
    </div>
    <div class="features">
      <div class="feature">
        <div class="feature__icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg></div>
        <h3>Местные гиды</h3>
        <p>Мы родились и живём в Якутии. Знаем маршруты, которых нет в путеводителях, и места, куда не возят туристические автобусы.</p>
      </div>
      <div class="feature">
        <div class="feature__icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg></div>
        <h3>Безопасность</h3>
        <p>Снаряжение, связь и сопровождение на всех маршрутах. Продумываем логистику до мелочей — от заброски до эвакуации.</p>
      </div>
      <div class="feature">
        <div class="feature__icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
        <h3>Малые группы</h3>
        <p>До 8 человек в группе. Никакой толпы, очередей за фото и спешки. Только вы, природа и проводник.</p>
      </div>
    </div>
  </div>
</section>

<!-- ===== НАПРАВЛЕНИЯ ===== -->
<section class="section section--soft" id="tours">
  <div class="container">
    <div class="section__head">
      <span class="section__uptitle">Направления</span>
      <h2>Выберите своё приключение</h2>
      <p>Зимние и летние форматы: от коротких экскурсий до многодневных экспедиций.</p>
    </div>
    <div class="tours">
      <a class="tour-card" href="https://oduntur.ru/page66005803.html" target="_blank" rel="noopener">
        <div class="tour-card__bg" style="background-image:url('https://static.tildacdn.com/tild3731-3731-4437-b565-343434333031/IMG_9962.jpeg');"></div>
        <div class="tour-card__body">
          <span class="tour-card__tag">Зима</span>
          <h3>Зимние туры</h3>
          <p>Ленские столбы в инее, полюс холода, зимняя рыбалка и северное сияние.</p>
          <span class="btn">Подробнее</span>
        </div>
      </a>
      <a class="tour-card" href="https://oduntur.ru/page66007579.html" target="_blank" rel="noopener">
        <div class="tour-card__bg" style="background-image:url('https://static.tildacdn.com/tild3530-6238-4639-b437-623339646439/IMG_9961.jpeg');"></div>
        <div class="tour-card__body">
          <span class="tour-card__tag">Лето</span>
          <h3>Летние туры</h3>
          <p>Сплавы по Лене, Ленские столбы с воды, бизонарий и пешие маршруты по тайге.</p>
          <span class="btn">Подробнее</span>
        </div>
      </a>
    </div>
  </div>
</section>

<!-- ===== БЛИЖАЙШИЕ ТУРЫ ===== -->
<section class="section" id="upcoming">
  <div class="container">
    <div class="section__head">
      <span class="section__uptitle">Расписание</span>
      <h2>Ближайшие туры</h2>
      <p>Даты и цены обновляются. Забронируйте место заранее — группы небольшие.</p>
    </div>
    <div class="upcoming">
      <div class="trip">
        <div class="trip__img" style="background-image:url('https://static.tildacdn.com/tild6530-3138-4537-b063-343938313736/IMG_7233.jpeg');"><span class="trip__date">12–14 марта</span></div>
        <div class="trip__body">
          <h3>Зимние Ленские столбы</h3>
          <p class="trip__meta">3 дня · снегоходы · ночёвка в избе · группа до 6 человек</p>
          <div class="trip__price">от 42 000 ₽ <small>/ человек</small></div>
          <a href="#" class="btn open-modal-btn">Забронировать</a>
        </div>
      </div>
      <div class="trip">
        <div class="trip__img" style="background-image:url('https://static.tildacdn.com/tild3336-6465-4161-a330-663362626337/photo_3_2024-06-11_1.jpg');"><span class="trip__date">5–8 апреля</span></div>
        <div class="trip__body">
          <h3>Бизонарий + Лена</h3>
          <p class="trip__meta">4 дня · питомник бизонов · сплав · группа до 8 человек</p>
          <div class="trip__price">от 56 000 ₽ <small>/ человек</small></div>
          <a href="#" class="btn open-modal-btn">Забронировать</a>
        </div>
      </div>
      <div class="trip">
        <div class="trip__img" style="background-image:url('https://static.tildacdn.com/tild3135-3061-4334-b938-333937353832/IMG_5344.jpeg');"><span class="trip__date">15–22 июня</span></div>
        <div class="trip__body">
          <h3>Экспедиция «Север»</h3>
          <p class="trip__meta">8 дней · сплав · рыбалка · полевые ночёвки · группа до 6 человек</p>
          <div class="trip__price">от 89 000 ₽ <small>/ человек</small></div>
          <a href="#" class="btn open-modal-btn">Забронировать</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== ГАЛЕРЕЯ ===== -->
<section class="section section--soft" id="gallery">
  <div class="container">
    <div class="section__head">
      <span class="section__uptitle">Галерея</span>
      <h2>Якутия в кадре</h2>
      <p>Ленские столбы, тайга, бизоны и северное небо — то, что вы увидите в турах.</p>
    </div>
    <div class="gallery">
      <a href="https://static.tildacdn.com/tild6530-3138-4537-b063-343938313736/IMG_7233.jpeg" target="_blank" rel="noopener" class="tall">
        <img src="https://static.tildacdn.com/tild6530-3138-4537-b063-343938313736/IMG_7233.jpeg" alt="Зимние Ленские столбы">
      </a>
      <a href="https://static.tildacdn.com/tild3336-6465-4161-a330-663362626337/photo_3_2024-06-11_1.jpg" target="_blank" rel="noopener">
        <img src="https://static.tildacdn.com/tild3336-6465-4161-a330-663362626337/photo_3_2024-06-11_1.jpg" alt="Бизонарий">
      </a>
      <a href="https://static.tildacdn.com/tild3135-3061-4334-b938-333937353832/IMG_5344.jpeg" target="_blank" rel="noopener">
        <img src="https://static.tildacdn.com/tild3135-3061-4334-b938-333937353832/IMG_5344.jpeg" alt="Летняя экспедиция">
      </a>
      <a href="https://static.tildacdn.com/tild3261-6434-4539-b162-353164373061/IMG_5347.jpeg" target="_blank" rel="noopener" class="wide">
        <img src="https://static.tildacdn.com/tild3261-6434-4539-b162-353164373061/IMG_5347.jpeg" alt="Природа Якутии">
      </a>
      <a href="https://static.tildacdn.com/tild3731-3731-4437-b565-343434333031/IMG_9962.jpeg" target="_blank" rel="noopener">
        <img src="https://static.tildacdn.com/tild3731-3731-4437-b565-343434333031/IMG_9962.jpeg" alt="Зимний тур">
      </a>
      <a href="https://static.tildacdn.com/tild3530-6238-4639-b437-623339646439/IMG_9961.jpeg" target="_blank" rel="noopener">
        <img src="https://static.tildacdn.com/tild3530-6238-4639-b437-623339646439/IMG_9961.jpeg" alt="Летний тур">
      </a>
<?php echo site_photos(); ?>
    </div>
  </div>
</section>

<!-- ===== О ЯКУТИИ ===== -->
<?php echo site_news(); ?>
<section class="section" id="about">
  <div class="container about">
    <div class="about__img">
      <img src="https://static.tildacdn.com/tild3736-6138-4438-b334-613932363530/47a3fad2a2dd512a13fe.jpg" alt="Якутия">
    </div>
    <div>
      <span class="section__uptitle">О Якутии (Саха Сирэ)</span>
      <h2>Земля, которой нет аналогов</h2>
      <p>Здесь находится полюс холода и единственный в мире питомник лесных бизонов. Ленские столбы — объект Всемирного наследия ЮНЕСКО.</p>
      <p>Республика Саха (Якутия) — крупнейший регион России. Мы покажем вам её так, как её видят местные: без суеты, с уважением к природе и северным традициям.</p>
      <div class="about__stats">
        <div class="about__stat"><b>−50°</b><span>зимой на полюсе холода</span></div>
        <div class="about__stat"><b>8</b><span>человек в группе</span></div>
        <div class="about__stat"><b>100%</b><span>местные гиды</span></div>
      </div>
      <a class="btn btn--outline" href="https://youtu.be/mdG1-b5W9Vo" target="_blank" rel="noopener">Посмотреть видео про Якутию</a>
    </div>
  </div>
</section>

<!-- ===== ОТЗЫВЫ ===== -->
<section class="section section--soft" id="reviews">
  <div class="container">
    <div class="section__head">
      <span class="section__uptitle">Отзывы</span>
      <h2>Люди, которые путешествуют с нами</h2>
      <p>Реальные впечатления клиентов Одун Тур.</p>
    </div>
    <div class="reviews">
      <div class="review">
        <p class="review__text">Выражаю огромную благодарность инструктору Андрею. Сразу видно, что человек любит свою работу, грамотно подобрал нам путёвки на Ленские столбы исходя из наших пожеланий, отвечал на все вопросы по ватсапу. Консультация на высшем уровне. Мы с девочками в восторге от поездки!</p>
        <div class="review__author">
          <div class="review__avatar">А</div>
          <div><div class="review__name">Анна</div><div class="review__tour">Зимние Ленские столбы, март 2026</div></div>
        </div>
      </div>
      <div class="review">
        <p class="review__text">Про зубров и зубровники наслышаны, наверное, все. А вот увидеть питомник лесных бизонов — потомков древних бизонов, которые жили здесь вместе с мамонтами — это редкая возможность. Бизонарий подарил кучу эмоций нашим клиентам!</p>
        <div class="review__author">
          <div class="review__avatar">С</div>
          <div><div class="review__name">Сергей</div><div class="review__tour">Бизонарий + Лена, июнь 2026</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== CTA ===== -->
<section class="cta">
  <div class="container">
    <h2>Не знаете, какой тур выбрать?</h2>
    <p>Оставьте заявку, и мы подберём маршрут под ваши даты, компанию и бюджет. Ответим в течение часа.</p>
    <a href="#" class="btn btn--light open-modal-btn">Оставить заявку</a>
  </div>
</section>

<!-- ===== ПОДВАЛ ===== -->
<footer class="footer" id="contacts">
  <div class="container">
    <div class="footer__grid">
      <div>
        <a href="#top" class="brand">
          <img src="https://static.tildacdn.com/tild3566-3933-4537-a162-346533656162/E84C6010-BB97-4E47-8.png" alt="Логотип Одун Тур" class="brand__logo">
          <span class="logo">ОДУН<span style="color:var(--sand)">ТУР</span></span>
        </a>
        <p>Региональный туроператор по Якутии. Экскурсии, активный отдых, экспедиции, охота и рыбалка на территории Республики Саха (Якутия).</p>
      </div>
      <div>
        <h4>Разделы</h4>
        <a href="#why">Почему мы</a>
        <a href="#tours">Направления</a>
        <a href="#upcoming">Ближайшие туры</a>
        <a href="#gallery">Галерея</a>
        <a href="#about">О Якутии</a>
      </div>
      <div class="footer__contacts">
        <h4>Контакты</h4>
        <a href="tel:+79991737407">📞 +7 999 173-74-07</a>
        <a href="mailto:oduntur@mail.ru">✉️ oduntur@mail.ru</a>
      </div>
    </div>
    <div class="footer__bottom">
      © 2026 Одун Тур. Все права защищены.
    </div>
  </div>
</footer>

<script>
(function(){
  // ===== БУРГЕР МЕНЮ =====
  var burger = document.getElementById('burger');
  var nav = document.getElementById('nav');
  
  burger.addEventListener('click', function(){
    var isOpen = nav.classList.toggle('open');
    burger.classList.toggle('open', isOpen);
    burger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  });

  nav.querySelectorAll('a').forEach(function(link){
    link.addEventListener('click', function(){
      nav.classList.remove('open');
      burger.classList.remove('open');
      burger.setAttribute('aria-expanded', 'false');
    });
  });

  // ===== ПЛАВНЫЙ СКРОЛЛ =====
  document.querySelectorAll('a[href^="#"]').forEach(function(anchor){
    anchor.addEventListener('click', function(e){
      var targetId = this.getAttribute('href');
      if(targetId === '#' || targetId === '') return;
      var target = document.querySelector(targetId);
      if(!target) return;
      e.preventDefault();
      var headerHeight = document.querySelector('.header').offsetHeight;
      var top = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 10;
      window.scrollTo({ top: top, behavior: 'smooth' });
    });
  });

  // ===== МОДАЛЬНОЕ ОКНО: ФОРМА =====
  var modal = document.getElementById('callbackModal');
  var closeBtn = document.getElementById('closeModal');
  var form = document.getElementById('callbackForm');
  var submitBtn = document.getElementById('submitBtn');
  
  document.querySelectorAll('.open-modal-btn').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      modal.classList.add('active');
    });
  });

  closeBtn.addEventListener('click', function(){ modal.classList.remove('active'); });
  modal.addEventListener('click', function(e){ if(e.target === modal) modal.classList.remove('active'); });

  // ===== ОТПРАВКА ФОРМЫ (PHP + MySQL) =====
  form.addEventListener('submit', function(e){
    e.preventDefault();
    submitBtn.disabled = true;
    submitBtn.textContent = 'Отправка...';

    fetch('?api=lead', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        name: document.getElementById('name').value,
        phone: document.getElementById('phone').value,
        comment: document.getElementById('comment').value
      })
    })
    .then(function(r){ return r.json(); })
    .then(function(res){
      if(!res.ok) throw new Error(res.error || 'error');
      alert('Спасибо! Ваша заявка отправлена. Мы свяжемся с вами.');
      modal.classList.remove('active');
      form.reset();
    })
    .catch(function(err){
      console.error('Ошибка отправки: ', err);
      var msgs = {
        name: 'Введите имя (минимум 2 символа).',
        phone: 'Введите корректный номер телефона, например +7 999 123-45-67.',
        comment: 'Комментарий слишком длинный.',
        rate: 'Слишком много заявок с вашего адреса. Попробуйте позже.'
      };
      var m = err && err.message;
      alert(msgs[m] || ('Произошла ошибка при отправке. Пожалуйста, попробуйте ещё раз чуть позже.' + (m && m !== 'error' ? '\n\n(' + m + ')' : '')));
    })
    .finally(function(){
      submitBtn.disabled = false;
      submitBtn.textContent = 'Отправить заявку';
    });
  });
})();
</script>
</body>
</html>