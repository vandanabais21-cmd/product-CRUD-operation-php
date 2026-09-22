<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'crud_comparison';
const DB_USER = 'root';
const DB_PASS = '';
const UPLOAD_DIR = __DIR__ . '/uploads';
const JSON_STORE = __DIR__ . '/data/products.json';

function database(): PDO
{
    static $pdo;
    if (!$pdo instanceof PDO) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flash(?string $message = null): ?string
{
    if ($message !== null) { $_SESSION['flash'] = $message; return null; }
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $value;
}

function csrf(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403); exit('Invalid form token. Please go back and try again.');
    }
}
/** Create writable folders the first time the application is used. */
function ensure_storage(): void
{
    foreach ([UPLOAD_DIR, dirname(JSON_STORE)] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create application storage directory.');
        }
    }
}
