<?php
declare(strict_types=1);

// Crea o actualiza las tablas. Se puede correr las veces que haga falta.

require __DIR__ . '/../src/bootstrap.php';

$pasos = [
    1 => [
        'CREATE TABLE IF NOT EXISTS articulos (c TEXT PRIMARY KEY, d TEXT, u TEXT, x REAL, co REAL, p REAL, cl TEXT)',
        'CREATE TABLE IF NOT EXISTS articulos_carga (snap TEXT, c TEXT, d TEXT, u TEXT, x REAL, co REAL, p REAL, cl TEXT, PRIMARY KEY (snap, c))',
        'CREATE TABLE IF NOT EXISTS importaciones (id TEXT PRIMARY KEY, tipo TEXT, creado TEXT, datos TEXT)',
        'CREATE TABLE IF NOT EXISTS importacion_cambios (imp_id TEXT, c TEXT, antes REAL, despues REAL)',
        'CREATE INDEX IF NOT EXISTS ix_cambios_imp ON importacion_cambios (imp_id)',
        'CREATE TABLE IF NOT EXISTS foto0_items (c TEXT PRIMARY KEY, x REAL, co REAL, d TEXT)',
        'CREATE TABLE IF NOT EXISTS sesiones (id TEXT PRIMARY KEY, origen TEXT, creado TEXT, actualizado TEXT, user TEXT, responsable TEXT, archivo TEXT, guardado TEXT)',
        'CREATE TABLE IF NOT EXISTS lineas (n INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT UNIQUE, sesion_id TEXT, c TEXT, datos TEXT, eliminada INTEGER NOT NULL DEFAULT 0)',
        'CREATE INDEX IF NOT EXISTS ix_lineas_sesion ON lineas (sesion_id)',
        'CREATE TABLE IF NOT EXISTS ajustes (id TEXT PRIMARY KEY, creado TEXT, datos TEXT)',
        'CREATE TABLE IF NOT EXISTS salida_planilla (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT, k TEXT, payload TEXT, creado TEXT, intentos INTEGER NOT NULL DEFAULT 0, ultimo_error TEXT, enviado_el TEXT)',
        'CREATE INDEX IF NOT EXISTS ix_salida_pendiente ON salida_planilla (enviado_el)',
        'CREATE TABLE IF NOT EXISTS sync_estado (clave TEXT PRIMARY KEY, valor TEXT)',
    ],
];

$pdo = db();
$actual = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
foreach ($pasos as $version => $sentencias) {
    if ($version <= $actual) {
        continue;
    }
    tx(function (PDO $pdo) use ($sentencias, $version) {
        foreach ($sentencias as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec('PRAGMA user_version = ' . $version);
    });
    echo "Migración $version aplicada\n";
}
echo 'Base en versión ' . (int) $pdo->query('PRAGMA user_version')->fetchColumn() . "\n";
