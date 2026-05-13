<?php
/**
 * Alinea la contraseña del rol PostgreSQL con DB_* del .env (mismo parser que Laravel / vlucas/phpdotenv).
 * Uso en VPS: php deploy/sync-pg-password-from-env.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

if (! is_readable($root . '/.env')) {
    fwrite(STDERR, "No readable .env\n");
    exit(1);
}

$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();

$user = $_ENV['DB_USERNAME'] ?? 'jobshour';
$pass = $_ENV['DB_PASSWORD'] ?? '';

if ($user === '') {
    fwrite(STDERR, "DB_USERNAME vacío\n");
    exit(1);
}

$passSql = str_replace("'", "''", $pass);
$userIdent = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $user)
    ? $user
    : '"' . str_replace('"', '""', $user) . '"';

$sql = "ALTER USER {$userIdent} WITH PASSWORD '{$passSql}';";
$cmd = 'sudo -u postgres psql -v ON_ERROR_STOP=1 -c ' . escapeshellarg($sql);

passthru($cmd, $code);
exit($code ?? 1);
