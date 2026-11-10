<?php
// Настройки подключения к MySQL (OSPanel: по умолчанию root без пароля)
const DB_HOST = 'sql211.infinityfree.com';
const DB_NAME = 'if0_43397295_odun';
const DB_USER = 'if0_43397295';
const DB_PASS = 'ljXdblBUEVw9l';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
