<?php
declare(strict_types=1);

/**
 * Comunicación con la planilla "Historico Stock Itala".
 *
 * Lectura: baja el .xlsx completo y copia a la base los conteos de todas las pestañas con
 * encabezados ID y Codigo, menos App Conteos (la escribe esta app; leerla contaría doble).
 * Una pestaña con encabezado Articulo, si existe, es el informe de existencias.
 * No usar el endpoint gviz: infiere tipos por columna y pierde filas (0100B77288001, por ejemplo).
 *
 * Escritura: los conteos cargados en la app salen por la cola salida_planilla hacia el
 * Apps Script. A la planilla solo van datos de conteo, nunca costos ni cierres.
 */
final class Planilla
{
    private const HOJA_APP = 'App Conteos';

    /**
     * Los artículos son 3/100178223, y en el sistema algunos tienen un espacio: 3/ 100254805.
     * Sheets convierte otros en fecha: 3/07 llega como 46206.
     */
    public const FORMATO_ARTICULO = '#^\d+/\s*\S#';

    public static function sincronizar(): array
    {
        Datos::guardar('planilla_intento', time());
        try {
            return self::procesar(self::descargar());
        } catch (Throwable $e) {
            $estado = Datos::leer('planilla') ?: [];
            $estado['ok'] = false;
            $estado['error'] = $e->getMessage();
            $estado['intento'] = ahora();
            Datos::guardar('planilla', $estado);
            return $estado;
        }
    }

    private static function descargar(): string
    {
        $id = cfg()['sheet_id'] ?? '';
        if ($id === '') {
            throw new RuntimeException('Falta configurar la planilla en el servidor.');
        }
        $ch = curl_init('https://docs.google.com/spreadsheets/d/' . rawurlencode($id) . '/export?format=xlsx');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
        ]);
        $bytes = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($bytes === false) {
            throw new RuntimeException('No pude conectar con Google: ' . curl_error($ch));
        }
        if ($http !== 200 || !str_starts_with($bytes, 'PK')) {
            throw new RuntimeException('Google no entregó la planilla (HTTP ' . $http . '). Revisá que el link siga compartido.');
        }
        return $bytes;
    }

    /** Copia a la base lo que trae el .xlsx. Si algo se ve mal, lanza una excepción y no toca nada. */
    public static function procesar(string $bytes): array
    {
        return self::aplicar(self::leerXlsx($bytes));
    }

    public static function aplicar(array $hojas): array
    {
        return tx(function (PDO $pdo) use ($hojas) {
            $hoy = ahora();
            $existencias = self::aplicarExistencias($pdo, $hojas, $hoy);
            $previas = [];
            foreach ($pdo->query("SELECT l.k, l.sesion_id, l.datos, l.eliminada FROM lineas l JOIN sesiones s ON s.id = l.sesion_id WHERE s.origen = 'planilla'") as $f) {
                $previas[$f['k']] = $f;
            }

            $apariciones = [];
            $vistas = [];
            $pestanas = [];
            $ignoradas = [];
            $rechazadas = [];
            $altaSesion = $pdo->prepare("INSERT OR IGNORE INTO sesiones (id, origen, creado, actualizado, user, responsable, archivo, guardado) VALUES (?, 'planilla', ?, ?, '', '', ?, ?)");
            $altaLinea = $pdo->prepare('INSERT INTO lineas (k, sesion_id, c, datos) VALUES (?, ?, ?, ?)');
            $actualizar = $pdo->prepare('UPDATE lineas SET sesion_id = ?, datos = ?, eliminada = 0 WHERE k = ?');
            $nuevas = 0;
            $cambios = 0;

            foreach ($hojas as $hoja) {
                if ($hoja['nombre'] === self::HOJA_APP) {
                    continue;
                }
                $r = self::parsearHoja($hoja['nombre'], $hoja['filas'], $apariciones);
                if ($r === null) {
                    if ($hoja['nombre'] !== ($existencias['nombre'] ?? null)) {
                        $ignoradas[] = $hoja['nombre'];
                    }
                    continue;
                }
                $pestanas[] = ['nombre' => $hoja['nombre'], 'lineas' => $r['total'], 'rechazadas' => count($r['rechazadas'])];
                array_push($rechazadas, ...$r['rechazadas']);
                foreach ($r['bloques'] as $b) {
                    $sid = 'hoja-' . substr(sha1($hoja['nombre'] . '|' . $b['guardado']), 0, 16);
                    $creado = $b['guardado'] ?: $hoy;
                    $altaSesion->execute([$sid, $creado, $hoy, $hoja['nombre'], $b['guardado']]);
                    foreach ($b['lineas'] as $l) {
                        $vistas[$l['k']] = true;
                        $previa = $previas[$l['k']] ?? null;
                        if ($previa === null) {
                            $altaLinea->execute([$l['k'], $sid, $l['c'], aJson($l)]);
                            $nuevas++;
                            continue;
                        }
                        // La clave no incluye producto ni estado, y una fila puede cambiar de
                        // pestaña: la planilla manda en sus columnas. Las marcas de la app
                        // (ticket, anulación) no están en la planilla y se conservan.
                        $antes = json_decode($previa['datos'], true);
                        $volvio = (int) $previa['eliminada'] === 1;
                        $distinta = $previa['sesion_id'] !== $sid || $l['d'] !== ($antes['d'] ?? '') || $l['e'] !== ($antes['e'] ?? '');
                        if ($volvio || $distinta) {
                            $actualizar->execute([$sid, aJson($l + $antes), $l['k']]);
                            $volvio ? $nuevas++ : $cambios++;
                        }
                    }
                }
            }
            if (!$pestanas) {
                throw new RuntimeException('No encontré pestañas con los encabezados ID y Codigo.');
            }
            $activas = array_filter($previas, fn ($p) => (int) $p['eliminada'] === 0);
            if (!$vistas && $activas) {
                throw new RuntimeException('La planilla vino sin conteos. No se cambió nada en la base.');
            }

            $bajas = 0;
            $ausentes = array_diff_key($activas, $vistas);
            if ($ausentes) {
                $enCierre = self::lineasEnCierres($pdo);
                $baja = $pdo->prepare('UPDATE lineas SET eliminada = 1 WHERE k = ?');
                foreach (array_keys($ausentes) as $k) {
                    if (!isset($enCierre[$k])) {
                        $baja->execute([$k]);
                        $bajas++;
                    }
                }
            }

            $estado = [
                'ok' => true, 'error' => null, 'ultima' => $hoy, 'intento' => $hoy, 'pestanas' => $pestanas,
                'ignoradas' => $ignoradas,
                'lineas' => count($vistas), 'nuevas' => $nuevas, 'bajas' => $bajas, 'cambios' => $cambios,
                'rechazadas' => array_slice($rechazadas, 0, 200), 'totalRechazadas' => count($rechazadas),
                'existencias' => $existencias,
            ];
            Datos::guardar('planilla', $estado);
            if ($nuevas || $bajas || $cambios) {
                $doc = [
                    'tipo' => 'relevamiento', 'creado' => $hoy, 'user' => '', 'archivo' => 'Google Sheets',
                    'nuevas' => $nuevas, 'bajas' => $bajas, 'cambios' => $cambios, 'duplicadas' => 0, 'rechazadas' => count($rechazadas),
                ];
                $pdo->prepare('INSERT INTO importaciones (id, tipo, creado, datos) VALUES (?, ?, ?, ?)')
                    ->execute(['imp-hoja-' . bin2hex(random_bytes(6)), 'relevamiento', $hoy, aJson($doc)]);
                Datos::tocar();
            }
            return $estado;
        });
    }

    /**
     * Si una pestaña tiene encabezado Articulo, la toma como informe de existencias.
     * Sin esa pestaña conserva el último maestro: un error en la planilla no borra nada.
     */
    private static function aplicarExistencias(PDO $pdo, array $hojas, string $hoy): ?array
    {
        foreach ($hojas as $hoja) {
            $r = self::parsearExistencias($hoja['filas']);
            if ($r === null || !$r['items']) {
                continue;
            }
            $items = array_values($r['items']);
            $resumen = ['nombre' => $hoja['nombre'], 'total' => count($items)];
            $huella = sha1(aJson($items));
            if ((Datos::leer('maestro')['huella'] ?? '') === $huella) {
                return $resumen; // sin cambios: no reescribir miles de filas en cada lectura
            }

            $previo = [];
            foreach ($pdo->query('SELECT c, x FROM articulos') as $f) {
                $previo[$f['c']] = (float) $f['x'];
            }
            $base = !$previo;
            $impId = 'imp-exist-' . bin2hex(random_bytes(6));
            $cambio = $pdo->prepare('INSERT INTO importacion_cambios (imp_id, c, antes, despues) VALUES (?, ?, ?, ?)');
            $nuevos = 0;
            $cambios = 0;
            $conStock = 0;
            $valor = 0.0;
            $pdo->exec('DELETE FROM articulos');
            $alta = $pdo->prepare('INSERT INTO articulos (c, d, u, x, co, p, cl) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($items as $it) {
                $alta->execute($it);
                [$c, , , $x, $co] = $it;
                $conStock += $x > 0 ? 1 : 0;
                $valor += max($x, 0) * (float) $co;
                if (!array_key_exists($c, $previo)) {
                    $nuevos++;
                    if (!$base) {
                        $cambio->execute([$impId, $c, null, $x]);
                    }
                } elseif ($previo[$c] !== $x) {
                    $cambios++;
                    $cambio->execute([$impId, $c, $previo[$c], $x]);
                }
            }
            $bajas = 0;
            foreach (array_diff_key($previo, $r['items']) as $c => $x) {
                $bajas++;
                $cambio->execute([$impId, $c, $x, null]);
            }

            $snap = 'hoja-' . substr($huella, 0, 16);
            $doc = [
                'tipo' => 'existencias', 'creado' => $hoy, 'user' => '', 'archivo' => $hoja['nombre'], 'fechaDato' => $hoy,
                'snap' => $snap, 'total' => count($items), 'conStock' => $conStock, 'valor' => round($valor),
                'nuevos' => $nuevos, 'bajas' => $bajas, 'cambios' => $cambios, 'base' => $base, 'duplicados' => $r['duplicados'],
            ];
            $pdo->prepare('INSERT INTO importaciones (id, tipo, creado, datos) VALUES (?, ?, ?, ?)')
                ->execute([$impId, 'existencias', $hoy, aJson($doc)]);
            Datos::guardar('maestro', [
                'snap' => $snap, 'huella' => $huella, 'total' => count($items), 'fechaDato' => $hoy,
                'archivo' => $hoja['nombre'], 'importacion' => $impId, 'actualizado' => $hoy,
            ]);
            Datos::tocar();
            return $resumen;
        }
        return null;
    }

    private static function lineasEnCierres(PDO $pdo): array
    {
        $usadas = [];
        foreach ($pdo->query('SELECT datos FROM ajustes') as $f) {
            $a = json_decode($f['datos'], true);
            if (!empty($a['anulado'])) {
                continue;
            }
            foreach ($a['lineas'] ?? [] as $l) {
                foreach ($l[4] ?? [] as $k) {
                    $usadas[$k] = true;
                }
            }
        }
        return $usadas;
    }

    // ---------- Lectura del .xlsx ----------

    /** @return array<int, array{nombre: string, filas: array<int, array<int, mixed>>}> */
    public static function leerXlsx(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'itala');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            unlink($tmp);
            throw new RuntimeException('El archivo de la planilla no se pudo abrir.');
        }
        try {
            $libro = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
            $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
            if ($libro === false || $rels === false) {
                throw new RuntimeException('El archivo de la planilla tiene un formato inesperado.');
            }
            $compartidos = self::textosCompartidos((string) $zip->getFromName('xl/sharedStrings.xml'));
            $destinos = [];
            foreach ($rels->Relationship as $r) {
                $destinos[(string) $r['Id']] = (string) $r['Target'];
            }
            $hojas = [];
            foreach ($libro->sheets->sheet as $h) {
                $rid = (string) $h->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $destino = ltrim($destinos[$rid] ?? '', '/');
                $xml = $zip->getFromName(str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino);
                if ($xml !== false) {
                    $hojas[] = ['nombre' => (string) $h['name'], 'filas' => self::filas($xml, $compartidos)];
                }
            }
            return $hojas;
        } finally {
            $zip->close();
            unlink($tmp);
        }
    }

    private static function textosCompartidos(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $out = [];
        foreach (simplexml_load_string($xml)->si as $si) {
            $t = (string) $si->t;
            foreach ($si->r as $r) {
                $t .= (string) $r->t;
            }
            $out[] = $t;
        }
        return $out;
    }

    private static function filas(string $xml, array $compartidos): array
    {
        $filas = [];
        foreach (simplexml_load_string($xml)->sheetData->row as $row) {
            $celdas = [];
            foreach ($row->c as $c) {
                $tipo = (string) $c['t'];
                $crudo = (string) $c->v;
                if ($tipo === 's') {
                    $v = $compartidos[(int) $crudo] ?? '';
                } elseif ($tipo === 'inlineStr') {
                    $v = (string) $c->is->t;
                } elseif ($tipo === 'str' || $tipo === 'b' || $tipo === 'e') {
                    $v = $crudo;
                } elseif ($crudo === '') {
                    continue;
                } else {
                    $v = is_numeric($crudo) ? (float) $crudo : $crudo;
                }
                $celdas[self::columna((string) $c['r'])] = $v;
            }
            $filas[(int) $row['r']] = $celdas;
        }
        return $filas;
    }

    private static function columna(string $ref): int
    {
        $n = 0;
        foreach (str_split(preg_replace('/\d/', '', $ref)) as $letra) {
            $n = $n * 26 + ord($letra) - 64;
        }
        return $n - 1;
    }

    // ---------- Interpretación de una pestaña ----------

    /**
     * Lee un informe de existencias, con las columnas del informe del sistema. Costo y precio
     * son opcionales: sin ellas, la app funciona sin valores en pesos.
     *
     * @return array{items: array<string, array>, duplicados: array}|null  null si no es un informe.
     */
    public static function parsearExistencias(array $filas): ?array
    {
        $inicio = null;
        $encabezado = [];
        foreach ($filas as $n => $f) {
            if (preg_match('/^art[ií]culo$/u', mb_strtolower(self::texto($f[0] ?? null)))) {
                $inicio = $n;
                $encabezado = array_map(fn ($v) => mb_strtolower(self::texto($v)), $f);
                break;
            }
        }
        if ($inicio === null) {
            return null;
        }
        $col = function (string $re) use ($encabezado): ?int {
            foreach ($encabezado as $i => $t) {
                if (preg_match($re, $t)) {
                    return $i;
                }
            }
            return null;
        };
        $iE = $col('/^exist/');
        if ($iE === null) {
            return null;
        }
        $iD = $col('/^descrip/');
        $iU = $col('/^ubic/');
        $iC = $col('/^clase/');
        $iP = $col('/^p\.?v\.?p\.?$/');
        $iCo = $col('/^cost.*medio/');
        $celda = fn (array $f, ?int $i) => $i === null ? null : ($f[$i] ?? null);

        $items = [];
        $duplicados = [];
        foreach ($filas as $n => $f) {
            if ($n <= $inicio) {
                continue;
            }
            $c = mb_strtoupper(self::texto($f[0] ?? null));
            if ($c === '' || str_starts_with($c, '***')) {
                continue;
            }
            $x = self::numero($celda($f, $iE));
            if (isset($items[$c])) {
                $duplicados[] = $c;
                $items[$c][3] += $x;
                continue;
            }
            $items[$c] = [
                $c, self::texto($celda($f, $iD)), self::texto($celda($f, $iU)), $x,
                $iCo === null ? null : self::numero($celda($f, $iCo)),
                $iP === null ? null : self::numero($celda($f, $iP)),
                self::texto($celda($f, $iC)),
            ];
        }
        return ['items' => $items, 'duplicados' => array_slice($duplicados, 0, 500)];
    }

    /** @return array{bloques: array, rechazadas: array, total: int}|null  null si no tiene los encabezados. */
    public static function parsearHoja(string $nombre, array $filas, array &$apariciones): ?array
    {
        $inicio = null;
        $encabezado = [];
        foreach ($filas as $n => $f) {
            $bajo = array_map(fn ($v) => mb_strtolower(self::texto($v)), $f);
            if (in_array('id', $bajo, true) && preg_grep('/^c[oó]digo$/u', $bajo)) {
                $inicio = $n;
                $encabezado = $bajo;
                break;
            }
        }
        if ($inicio === null) {
            return null;
        }
        $col = function (string $re) use ($encabezado): ?int {
            foreach ($encabezado as $i => $t) {
                if (preg_match($re, $t)) {
                    return $i;
                }
            }
            return null;
        };
        $iId = $col('/^id$/');
        $iB = $col('/^c[oó]digo$/u');
        $iD = $col('/^producto|^descrip/');
        $iQ = $col('/^cant/');
        $iE = $col('/^estado/');
        $iU = $col('/^ubic/');
        $iF = $col('/^fecha/');
        $celda = fn (array $f, ?int $i) => $i === null ? null : ($f[$i] ?? null);

        $bloques = [];
        $actual = 0;
        $bloques[$actual] = ['guardado' => '', 'lineas' => []];
        $rechazadas = [];
        $total = 0;
        foreach ($filas as $n => $f) {
            if ($n <= $inicio) {
                continue;
            }
            $id = self::texto($celda($f, $iId));
            if (preg_match('/GUARDADO EL:\s*(.+)$/i', $id, $g)) {
                $bloques[++$actual] = ['guardado' => self::fecha($g[1]), 'lineas' => []];
                continue;
            }
            if (!array_filter($f, fn ($v) => self::texto($v) !== '')) {
                continue;
            }
            $total++;
            $l = [
                'c' => mb_strtoupper($id),
                'b' => preg_replace('/\s+/', '', self::texto($celda($f, $iB))),
                'd' => self::texto($celda($f, $iD)),
                'q' => self::numero($celda($f, $iQ)),
                'e' => self::texto($celda($f, $iE)),
                'u' => mb_strtoupper(self::texto($celda($f, $iU))),
                't' => self::fecha($celda($f, $iF)),
                'r' => '',
            ];
            $base = implode('|', [$l['c'], $l['b'], $l['q'], $l['u'], $l['t']]);
            $apariciones[$base] = ($apariciones[$base] ?? 0) + 1;
            $l = ['k' => 'x' . substr(sha1($base . '|' . $apariciones[$base]), 0, 20)] + $l;

            $motivo = null;
            if ($l['c'] === '') {
                $motivo = 'Sin código de artículo';
            } elseif (!preg_match(self::FORMATO_ARTICULO, $l['c'])) {
                $motivo = 'Código con formato inválido';
            } elseif ($l['q'] <= 0) {
                $motivo = 'Cantidad vacía o cero';
            }
            if ($motivo !== null) {
                $rechazadas[] = ['hoja' => $nombre, 'fila' => $n, 'c' => $l['c'], 'd' => $l['d'], 'q' => $l['q'], 'motivo' => $motivo];
                continue;
            }
            $bloques[$actual]['lineas'][] = $l;
        }
        return [
            'bloques' => array_values(array_filter($bloques, fn ($b) => $b['lineas'])),
            'rechazadas' => $rechazadas,
            'total' => $total,
        ];
    }

    private static function texto($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v)) {
            return floor($v) === $v && abs($v) < 1e15 ? sprintf('%.0f', $v) : (string) $v;
        }
        return trim((string) $v);
    }

    private static function numero($v): float
    {
        if (is_float($v) || is_int($v)) {
            return (float) $v;
        }
        $t = (string) preg_replace('/\s/', '', self::texto($v));
        if (str_contains($t, ',')) {
            $t = str_replace(',', '.', str_replace('.', '', $t));
        }
        return (float) $t;
    }

    public static function fecha($v): string
    {
        if (is_float($v) && $v > 20000 && $v < 80000) {
            return gmdate('Y-m-d H:i:s', (int) round(($v - 25569) * 86400));
        }
        $t = self::texto($v);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?#', $t, $m)) {
            return sprintf('%s-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $t, $m)) {
            return sprintf('%s-%s-%s %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
        }
        return $t;
    }

    // ---------- Escritura ----------

    /** Envía la cola al Apps Script. Si falla, las filas quedan pendientes para el próximo intento. */
    public static function enviarCola(): array
    {
        $url = cfg()['script_url'] ?? '';
        $token = cfg()['script_token'] ?? '';
        if ($url === '' || $token === '') {
            return ['enviadas' => 0, 'motivo' => 'sin script'];
        }
        $pdo = db();
        $enviadas = 0;
        do {
            $filas = $pdo->query('SELECT id, tipo, payload FROM salida_planilla WHERE enviado_el IS NULL ORDER BY id LIMIT 100')->fetchAll();
            if (!$filas) {
                break;
            }
            $ids = implode(',', array_map(fn ($f) => (int) $f['id'], $filas));
            $items = array_map(fn ($f) => ['tipo' => $f['tipo'], 'datos' => json_decode($f['payload'], true)], $filas);
            try {
                self::enviar($url, ['token' => $token, 'items' => $items]);
            } catch (Throwable $e) {
                $st = $pdo->prepare("UPDATE salida_planilla SET intentos = intentos + 1, ultimo_error = ? WHERE id IN ($ids)");
                $st->execute([mb_substr($e->getMessage(), 0, 300)]);
                return ['enviadas' => $enviadas, 'error' => $e->getMessage()];
            }
            $st = $pdo->prepare("UPDATE salida_planilla SET enviado_el = ?, ultimo_error = NULL WHERE id IN ($ids)");
            $st->execute([ahora()]);
            $enviadas += count($filas);
        } while (count($filas) === 100);
        return ['enviadas' => $enviadas];
    }

    private static function enviar(string $url, array $cuerpo): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => aJson($cuerpo),
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=utf-8'],
            CURLOPT_FOLLOWLOCATION => true, // el Web App responde con un 302 que se sigue por GET
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
        ]);
        $r = curl_exec($ch);
        if ($r === false) {
            throw new RuntimeException('No pude conectar con el script: ' . curl_error($ch));
        }
        $j = json_decode((string) $r, true);
        if (!is_array($j) || ($j['ok'] ?? false) !== true) {
            throw new RuntimeException('El script rechazó el envío: ' . (is_array($j) ? (string) ($j['error'] ?? 'sin detalle') : 'respuesta inesperada'));
        }
    }
}
