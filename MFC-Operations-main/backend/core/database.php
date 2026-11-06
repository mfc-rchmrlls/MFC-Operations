<?php

declare(strict_types=1);
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = getenv('WQ_DB_HOST') ?: '127.0.0.1';
    $name = getenv('WQ_DB_NAME') ?: 'withdrawal_qb';
    $user = getenv('WQ_DB_USER') ?: 'root';
    $pass = getenv('WQ_DB_PASS') ?: '';
    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
