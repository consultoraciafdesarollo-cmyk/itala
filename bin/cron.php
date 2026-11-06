<?php
declare(strict_types=1);

// Tareas periódicas. El cron de hPanel lo corre cada minuto a través de cron.sh:
// envía la cola a la planilla, lee la planilla cada 5 minutos y respalda la base una vez por día.

require __DIR__ . '/../src/bootstrap.php';

const CADA_LECTURA = 300;
const DIAS_RESPALDO = 14;

$cola = Planilla::enviarCola();
if (!empty($cola['enviadas']) || !empty($cola['error'])) {
    echo ahora() . ' cola: ' . aJson($cola) . "\n";
}

if (time() - (int) Datos::leer('planilla_intento', 0) >= CADA_LECTURA) {
    $r = Planilla::sincronizar();
    if (empty($r['ok']) || !empty($r['nuevas']) || !empty($r['bajas'])) {
        echo ahora() . ' planilla: ' . aJson(array_intersect_key($r, array_flip(['ok', 'error', 'lineas', 'nuevas', 'bajas']))) . "\n";
    }
}

$dir = cfg()['backups'] ?? '';
if ($dir !== '') {
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $destino = $dir . '/itala-' . date('Y-m-d') . '.sqlite';
    if (!is_file($destino)) {
        db()->exec('VACUUM INTO ' . db()->quote($destino));
        chmod($destino, 0600);
        db()->exec("DELETE FROM salida_planilla WHERE enviado_el < date('now', '-30 days')");
        foreach (glob($dir . '/itala-*.sqlite') ?: [] as $viejo) {
            if (filemtime($viejo) < time() - DIAS_RESPALDO * 86400) {
                unlink($viejo);
            }
        }
        echo ahora() . " respaldo: $destino\n";
    }
}
