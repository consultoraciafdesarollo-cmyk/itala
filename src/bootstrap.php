<?php
declare(strict_types=1);

// Punto de entrada común: configuración y conexión a la base.
// config.php vive fuera del repo; ver config.example.php.

date_default_timezone_set('America/Argentina/San_Juan');

require_once __DIR__ . '/Datos.php';
require_once __DIR__ . '/Planilla.php';

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        $ruta = getenv('ITALA_CONFIG') ?: dirname(__DIR__, 2) . '/itala/config.php';
        if (!is_file($ruta)) {
            throw new RuntimeException('No encontré config.php en ' . $ruta);
        }
        $c = require $ruta;
    }
    return $c;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . cfg()['db'], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
    }
    return $pdo;
}

/** Corre $fn en una transacción que toma el bloqueo de escritura desde el inicio. */
function tx(callable $fn)
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $r = $fn($pdo);
        $pdo->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function ahora(): string
{
    return date('Y-m-d H:i:s');
}

function aJson($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}
