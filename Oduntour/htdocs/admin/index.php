<?php
require __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'toggle') {
        db()->prepare("UPDATE leads SET status = IF(status='new','processed','new') WHERE id=?")->execute([$id]);
    } elseif (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM leads WHERE id=?')->execute([$id]);
    }
    header('Location: index.php?' . http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'st' => $_GET['st'] ?? '']))); exit;
}

$q  = trim($_GET['q'] ?? '');
$st = in_array($_GET['st'] ?? '', ['new', 'processed'], true) ? $_GET['st'] : '';
$where = []; $args = [];
if ($q !== '') { $where[] = '(name LIKE ? OR phone LIKE ? OR comment LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if ($st !== '') { $where[] = 'status = ?'; $args[] = $st; }
$sql = 'SELECT * FROM leads' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC, id DESC';
$s = db()->prepare($sql); $s->execute($args); $rows = $s->fetchAll();

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output', 'w');
    fputcsv($f, ['ID', 'Дата', 'ФИО', 'Телефон', 'Комментарий', 'Статус'], ';');
    foreach ($rows as $r) fputcsv($f, [$r['id'], $r['created_at'], $r['name'], $r['phone'], $r['comment'], $r['status']], ';');
    exit;
}
$total = (int)db()->query('SELECT COUNT(*) FROM leads')->fetchColumn();
$new   = (int)db()->query("SELECT COUNT(*) FROM leads WHERE status='new'")->fetchColumn();
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Заявки — Одун Тур</title>
<link rel="stylesheet" href="style.css"></head>
<body>
<header class="top"><strong>Одун Тур · Админ-панель</strong>
  <span><a href="../" target="_blank">Сайт</a> <a href="logout.php">Выйти (<?= h($_SESSION['admin']) ?>)</a></span></header>
<main class="wrap">
  <div class="stats"><div class="card"><b><?= $total ?></b><span class="muted">всего заявок</span></div>
    <div class="card"><b><?= $new ?></b><span class="muted">новых</span></div></div>
  <form class="filters" method="get">
    <input name="q" value="<?= h($q) ?>" placeholder="Поиск по имени, телефону, комментарию">
    <select name="st"><option value="">Все</option>
      <option value="new"<?= $st === 'new' ? ' selected' : '' ?>>Новые</option>
      <option value="processed"<?= $st === 'processed' ? ' selected' : '' ?>>Обработанные</option></select>
    <button class="btn">Найти</button>
    <a class="btn ghost" href="?csv=1&q=<?= urlencode($q) ?>&st=<?= h($st) ?>">Скачать CSV</a>
  </form>
  <div class="card scroll">
  <?php if (!$rows): ?><p class="muted empty">Заявок не найдено.</p><?php else: ?>
  <table><thead><tr><th>Дата</th><th>ФИО</th><th>Телефон</th><th>Комментарий</th><th>Статус</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="nw"><?= h(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
      <td><strong><?= h($r['name']) ?></strong></td>
      <td class="nw"><a href="tel:<?= h($r['phone']) ?>"><?= h($r['phone']) ?></a></td>
      <td><?= $r['comment'] !== '' && $r['comment'] !== null ? nl2br(h($r['comment'])) : '—' ?></td>
      <td><span class="badge <?= $r['status'] ?>"><?= $r['status'] === 'new' ? 'Новая' : 'Обработана' ?></span></td>
      <td class="nw">
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="mini" name="action" value="toggle">✓</button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('Удалить заявку?')"><input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="mini del" name="action" value="delete">🗑</button></form>
      </td>
    </tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
  </div>
</main></body></html>
