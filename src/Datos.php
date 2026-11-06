<?php
declare(strict_types=1);

/** Error de datos de entrada: la API lo responde como 400 con este código. */
final class DatoInvalido extends RuntimeException
{
}

final class Datos
{
    public static function leer(string $clave, $defecto = null)
    {
        $st = db()->prepare('SELECT valor FROM sync_estado WHERE clave = ?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $v === false ? $defecto : json_decode((string) $v, true);
    }

    public static function guardar(string $clave, $valor): void
    {
        db()->prepare('INSERT INTO sync_estado (clave, valor) VALUES (?, ?) ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor')
            ->execute([$clave, aJson($valor)]);
    }

    public static function version(): int
    {
        return (int) self::leer('version', 0);
    }

    /** Avisa a los navegadores que hay datos nuevos para traer. */
    public static function tocar(): void
    {
        self::guardar('version', self::version() + 1);
    }

    public static function cola(): array
    {
        $r = db()->query('SELECT COUNT(*) AS pendientes, MAX(intentos) AS intentos FROM salida_planilla WHERE enviado_el IS NULL')->fetch();
        $error = db()->query('SELECT ultimo_error FROM salida_planilla WHERE enviado_el IS NULL AND ultimo_error IS NOT NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
        return [
            'pendientes' => (int) $r['pendientes'], 'intentos' => (int) $r['intentos'], 'error' => $error ?: null,
            'script' => (cfg()['script_url'] ?? '') !== '',
        ];
    }

    public static function estado(int $desde): array
    {
        $version = self::version();
        $base = ['version' => $version, 'planilla' => self::leer('planilla'), 'cola' => self::cola()];
        if ($desde === $version) {
            return $base + ['sinCambios' => true];
        }
        return $base + [
            'meta' => self::leer('maestro'),
            'foto0Meta' => self::leer('foto0'),
            'sesiones' => self::sesiones(),
            'ajustes' => self::documentos('ajustes'),
            'importaciones' => self::documentos('importaciones'),
        ];
    }

    private static function documentos(string $tabla): array
    {
        $out = [];
        foreach (db()->query("SELECT id, datos FROM $tabla ORDER BY creado") as $f) {
            $out[] = ['id' => $f['id']] + json_decode($f['datos'], true);
        }
        return $out;
    }

    private static function sesiones(): array
    {
        $porId = [];
        foreach (db()->query('SELECT * FROM sesiones ORDER BY creado') as $s) {
            $s['lineas'] = [];
            $porId[$s['id']] = $s;
        }
        foreach (db()->query('SELECT sesion_id, datos FROM lineas WHERE eliminada = 0 ORDER BY n') as $l) {
            if (isset($porId[$l['sesion_id']])) {
                $porId[$l['sesion_id']]['lineas'][] = json_decode($l['datos'], true);
            }
        }
        return array_values(array_filter($porId, fn ($s) => $s['lineas']));
    }

    public static function maestro(): array
    {
        $meta = self::leer('maestro');
        $items = db()->query('SELECT c, d, u, x, co, p, cl FROM articulos')->fetchAll(PDO::FETCH_NUM);
        return ['snap' => $meta['snap'] ?? null, 'items' => $items];
    }

    public static function foto0(): array
    {
        $meta = self::leer('foto0');
        $items = db()->query('SELECT c, x, co, d FROM foto0_items')->fetchAll(PDO::FETCH_NUM);
        return ['id' => $meta['id'] ?? null, 'items' => $items];
    }

    public static function delta(string $id): array
    {
        $st = db()->prepare('SELECT c, antes, despues FROM importacion_cambios WHERE imp_id = ?');
        $st->execute([self::id($id)]);
        return ['rows' => $st->fetchAll(PDO::FETCH_NUM)];
    }

    // ---------- Existencias ----------

    public static function maestroChunk(array $b): array
    {
        $snap = self::id($b['snap'] ?? null);
        $i = (int) ($b['i'] ?? -1);
        $items = $b['items'] ?? null;
        if ($i < 0 || !is_array($items) || count($items) > 3000) {
            throw new DatoInvalido('invalid_argument');
        }
        tx(function (PDO $pdo) use ($snap, $i, $items) {
            if ($i === 0) {
                $pdo->prepare('DELETE FROM articulos_carga WHERE snap <> ?')->execute([$snap]);
            }
            $st = $pdo->prepare('INSERT OR REPLACE INTO articulos_carga (snap, c, d, u, x, co, p, cl) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($items as $it) {
                if (!is_array($it) || count($it) !== 7) {
                    throw new DatoInvalido('invalid_argument');
                }
                $st->execute([
                    $snap, self::texto($it[0], 60, true), self::texto($it[1], 200), self::texto($it[2], 60),
                    self::numero($it[3]), self::numero($it[4]), self::numero($it[5]), self::texto($it[6], 20),
                ]);
            }
        });
        return ['ok' => true];
    }

    public static function maestroFin(array $b): array
    {
        $snap = self::id($b['snap'] ?? null);
        $impId = self::id($b['impId'] ?? null);
        $total = (int) ($b['total'] ?? 0);
        $delta = $b['delta'] ?? [];
        if (!is_array($delta)) {
            throw new DatoInvalido('invalid_argument');
        }
        return tx(function (PDO $pdo) use ($b, $snap, $impId, $total, $delta) {
            if ((self::leer('maestro')['snap'] ?? '') === $snap) {
                return ['ok' => true]; // reintento de una importación que ya entró
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM articulos_carga WHERE snap = ?');
            $st->execute([$snap]);
            if ($total < 1 || (int) $st->fetchColumn() !== $total) {
                throw new DatoInvalido('importacion_incompleta');
            }
            $pdo->exec('DELETE FROM articulos');
            $pdo->prepare('INSERT INTO articulos (c, d, u, x, co, p, cl) SELECT c, d, u, x, co, p, cl FROM articulos_carga WHERE snap = ?')->execute([$snap]);
            $pdo->exec('DELETE FROM articulos_carga');
            $st = $pdo->prepare('INSERT INTO importacion_cambios (imp_id, c, antes, despues) VALUES (?, ?, ?, ?)');
            foreach ($delta as $r) {
                $st->execute([$impId, self::texto($r[0] ?? '', 60, true), $r[1] ?? null, $r[2] ?? null]);
            }
            $creado = ahora();
            $archivo = self::texto($b['archivo'] ?? '', 200);
            $fecha = self::texto($b['fechaDato'] ?? '', 25);
            $doc = [
                'tipo' => 'existencias', 'creado' => $creado, 'user' => self::texto($b['user'] ?? '', 80),
                'archivo' => $archivo, 'fechaDato' => $fecha, 'snap' => $snap, 'total' => $total,
                'conStock' => (int) ($b['conStock'] ?? 0), 'valor' => self::numero($b['valor'] ?? 0),
                'nuevos' => (int) ($b['nuevos'] ?? 0), 'bajas' => (int) ($b['bajas'] ?? 0), 'cambios' => (int) ($b['cambios'] ?? 0),
                'base' => !empty($b['base']), 'duplicados' => array_slice((array) ($b['duplicados'] ?? []), 0, 500),
            ];
            $pdo->prepare('INSERT INTO importaciones (id, tipo, creado, datos) VALUES (?, ?, ?, ?)')
                ->execute([$impId, 'existencias', $creado, aJson($doc)]);
            self::guardar('maestro', [
                'snap' => $snap, 'total' => $total, 'fechaDato' => $fecha, 'archivo' => $archivo,
                'importacion' => $impId, 'actualizado' => $creado,
            ]);
            self::tocar();
            return ['ok' => true];
        });
    }

    public static function guardarFoto0(array $b): array
    {
        $id = self::id($b['id'] ?? null);
        $items = $b['items'] ?? null;
        $meta = $b['meta'] ?? null;
        if (!is_array($items) || !is_array($meta) || count($items) > 60000) {
            throw new DatoInvalido('invalid_argument');
        }
        tx(function (PDO $pdo) use ($id, $items, $meta) {
            $pdo->exec('DELETE FROM foto0_items');
            $st = $pdo->prepare('INSERT OR REPLACE INTO foto0_items (c, x, co, d) VALUES (?, ?, ?, ?)');
            foreach ($items as $it) {
                $st->execute([self::texto($it[0] ?? '', 60, true), self::numero($it[1] ?? 0), self::numero($it[2] ?? 0), self::texto($it[3] ?? '', 200)]);
            }
            self::guardar('foto0', [
                'id' => $id, 'total' => count($items), 'fechaDato' => self::texto($meta['fechaDato'] ?? '', 25),
                'archivo' => self::texto($meta['archivo'] ?? '', 200), 'importacion' => self::texto($meta['importacion'] ?? '', 64),
                'marcado' => ahora(), 'user' => self::texto($meta['user'] ?? '', 80), 'valor' => self::numero($meta['valor'] ?? 0),
            ]);
            self::tocar();
        });
        return ['ok' => true];
    }

    // ---------- Conteos ----------

    public static function linea(array $b): array
    {
        $s = $b['sesion'] ?? null;
        $l = $b['linea'] ?? null;
        if (!is_array($s) || !is_array($l)) {
            throw new DatoInvalido('invalid_argument');
        }
        $sid = self::id($s['id'] ?? null);
        $k = self::id($l['k'] ?? null);
        $q = self::numero($l['q'] ?? 0);
        if ($q <= 0 || $q > 10000000) {
            throw new DatoInvalido('invalid_argument');
        }
        $linea = [
            'k' => $k, 'c' => self::texto($l['c'] ?? '', 60, true), 'b' => self::texto($l['b'] ?? '', 60),
            'd' => self::texto($l['d'] ?? '', 200), 'q' => $q, 'e' => self::texto($l['e'] ?? '', 30),
            'u' => self::texto($l['u'] ?? '', 40, true), 't' => self::fecha($l['t'] ?? ''), 'r' => self::texto($l['r'] ?? '', 80),
            'et' => !empty($l['et']),
        ];
        $user = self::texto($s['user'] ?? '', 80);
        $resp = self::texto($s['responsable'] ?? '', 80);
        tx(function (PDO $pdo) use ($sid, $k, $linea, $user, $resp) {
            $hoy = ahora();
            $pdo->prepare("INSERT OR IGNORE INTO sesiones (id, origen, creado, actualizado, user, responsable, archivo, guardado) VALUES (?, 'app', ?, ?, ?, ?, '', '')")
                ->execute([$sid, $hoy, $hoy, $user, $resp]);
            $st = $pdo->prepare('INSERT OR IGNORE INTO lineas (k, sesion_id, c, datos) VALUES (?, ?, ?, ?)');
            $st->execute([$k, $sid, $linea['c'], aJson($linea)]);
            if ($st->rowCount() === 0) {
                return; // reintento de una línea que ya entró
            }
            $pdo->prepare('UPDATE sesiones SET actualizado = ? WHERE id = ?')->execute([$hoy, $sid]);
            self::encolar('linea', $k, $linea);
            self::tocar();
        });
        return ['ok' => true];
    }

    public static function lineaMarca(array $b): array
    {
        $k = self::id($b['k'] ?? null);
        $tipo = $b['tipo'] ?? '';
        $user = self::texto($b['user'] ?? '', 80);
        if ($tipo !== 'ticket' && $tipo !== 'anular') {
            throw new DatoInvalido('invalid_argument');
        }
        tx(function (PDO $pdo) use ($k, $tipo, $user) {
            $st = $pdo->prepare('SELECT l.datos, s.origen FROM lineas l JOIN sesiones s ON s.id = l.sesion_id WHERE l.k = ?');
            $st->execute([$k]);
            $f = $st->fetch();
            if (!$f) {
                throw new DatoInvalido('invalid_argument');
            }
            $d = json_decode($f['datos'], true);
            $hoy = ahora();
            if ($tipo === 'ticket') {
                $d['et'] = true;
                $d['ett'] = $hoy;
                $d['etu'] = $user;
            } else {
                $d['x'] = true;
                $d['xt'] = $hoy;
                $d['xu'] = $user;
            }
            $pdo->prepare('UPDATE lineas SET datos = ? WHERE k = ?')->execute([aJson($d), $k]);
            if ($f['origen'] === 'app') { // las pestañas Historico* no se tocan
                self::encolar('marca', $k, ['k' => $k, 'tipo' => $tipo]);
            }
            self::tocar();
        });
        return ['ok' => true];
    }

    /** Solo se encolan campos de conteo: nunca costos, precios ni cierres. */
    private static function encolar(string $tipo, string $k, array $datos): void
    {
        $permitidos = ['k', 'c', 'b', 'd', 'q', 'e', 'u', 't', 'r', 'et', 'tipo'];
        $payload = array_intersect_key($datos, array_flip($permitidos));
        db()->prepare('INSERT INTO salida_planilla (tipo, k, payload, creado) VALUES (?, ?, ?, ?)')
            ->execute([$tipo, $k, aJson($payload), ahora()]);
    }

    // ---------- Cierres ----------

    public static function ajuste(array $b): array
    {
        $id = self::id($b['id'] ?? null);
        $doc = $b['doc'] ?? null;
        if (!is_array($doc) || !is_array($doc['lineas'] ?? null) || !$doc['lineas']) {
            throw new DatoInvalido('invalid_argument');
        }
        tx(function (PDO $pdo) use ($id, $doc) {
            $creado = is_string($doc['creado'] ?? null) ? self::fecha($doc['creado']) : ahora();
            $st = $pdo->prepare('INSERT OR IGNORE INTO ajustes (id, creado, datos) VALUES (?, ?, ?)');
            $st->execute([$id, $creado, aJson($doc)]);
            if ($st->rowCount() > 0) {
                self::tocar();
            }
        });
        return ['ok' => true];
    }

    public static function ajusteMarca(array $b): array
    {
        $id = self::id($b['id'] ?? null);
        $tipo = $b['tipo'] ?? '';
        $user = self::texto($b['user'] ?? '', 80);
        if ($tipo !== 'anular' && $tipo !== 'cargado') {
            throw new DatoInvalido('invalid_argument');
        }
        tx(function (PDO $pdo) use ($id, $tipo, $user) {
            $st = $pdo->prepare('SELECT datos FROM ajustes WHERE id = ?');
            $st->execute([$id]);
            $json = $st->fetchColumn();
            if ($json === false) {
                throw new DatoInvalido('invalid_argument');
            }
            $d = json_decode($json, true);
            $hoy = ahora();
            if ($tipo === 'anular') {
                $d['anulado'] = true;
                $d['anuladoEl'] = $hoy;
                $d['anuladoPor'] = $user;
            } else {
                $d['cargado'] = true;
                $d['cargadoEl'] = $hoy;
                $d['cargadoPor'] = $user;
            }
            $pdo->prepare('UPDATE ajustes SET datos = ? WHERE id = ?')->execute([aJson($d), $id]);
            self::tocar();
        });
        return ['ok' => true];
    }

    // ---------- Validación ----------

    private static function id($v): string
    {
        if (!is_string($v) || !preg_match('/^[A-Za-z0-9_-]{4,64}$/', $v)) {
            throw new DatoInvalido('invalid_argument');
        }
        return $v;
    }

    private static function texto($v, int $max, bool $requerido = false): string
    {
        if ($v === null) {
            $v = '';
        }
        if (!is_scalar($v)) {
            throw new DatoInvalido('invalid_argument');
        }
        $t = trim((string) $v);
        if (mb_strlen($t) > $max || ($requerido && $t === '')) {
            throw new DatoInvalido('invalid_argument');
        }
        return $t;
    }

    private static function numero($v): float
    {
        if (!is_int($v) && !is_float($v)) {
            throw new DatoInvalido('invalid_argument');
        }
        return (float) $v;
    }

    private static function fecha($v): string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
            throw new DatoInvalido('invalid_argument');
        }
        return $v;
    }
}
