<?php
declare(strict_types=1);

// API JSON de la app. No hay login: por decisión del proyecto, entra cualquiera con la URL.
// No se envía Access-Control-Allow-Origin y los POST exigen application/json, para que
// otro sitio no pueda operar desde el navegador de quien usa la app.

require dirname(__DIR__) . '/src/bootstrap.php';

const MAX_CUERPO = 8 * 1024 * 1024;
const PAUSA_SINCRONIZAR = 20; // segundos entre pedidos manuales

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function responder(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    echo aJson($datos);
    exit;
}

function fallar(string $codigo, int $http): never
{
    responder(['error' => $codigo], $http);
}

function leerCuerpo(): array
{
    $tipo = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_starts_with($tipo, 'application/json')) {
        fallar('tipo_no_admitido', 415);
    }
    $crudo = (string) file_get_contents('php://input', false, null, 0, MAX_CUERPO + 1);
    if (strlen($crudo) > MAX_CUERPO) {
        fallar('demasiado_grande', 413);
    }
    $b = json_decode($crudo, true);
    if (!is_array($b)) {
        fallar('invalid_argument', 400);
    }
    return $b;
}

function sincronizarAhora(): array
{
    if (time() - (int) Datos::leer('planilla_intento', 0) < PAUSA_SINCRONIZAR) {
        return ['ok' => true, 'limitado' => true];
    }
    Planilla::sincronizar();
    return ['ok' => true];
}

try {
    if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        ob_start('ob_gzhandler');
    }
    $accion = (string) ($_GET['accion'] ?? '');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($metodo === 'GET') {
        switch ($accion) {
            case 'estado':
                responder(Datos::estado((int) ($_GET['v'] ?? -1)));
            case 'maestro':
                responder(Datos::maestro());
            case 'foto0':
                responder(Datos::foto0());
            case 'delta':
                responder(Datos::delta((string) ($_GET['id'] ?? '')));
        }
        fallar('accion_desconocida', 404);
    }

    if ($metodo !== 'POST') {
        fallar('metodo_no_admitido', 405);
    }
    $acciones = [
        'foto0' => [Datos::class, 'guardarFoto0'],
        'linea' => [Datos::class, 'linea'],
        'linea_marca' => [Datos::class, 'lineaMarca'],
        'ajuste' => [Datos::class, 'ajuste'],
        'ajuste_marca' => [Datos::class, 'ajusteMarca'],
    ];
    $cuerpo = leerCuerpo();
    if ($accion === 'sincronizar') {
        responder(sincronizarAhora());
    }
    if (!isset($acciones[$accion])) {
        fallar('accion_desconocida', 404);
    }
    responder($acciones[$accion]($cuerpo));
} catch (DatoInvalido $e) {
    fallar($e->getMessage(), 400);
} catch (PDOException $e) {
    error_log('itala: ' . $e->getMessage());
    fallar('unavailable', 503);
} catch (Throwable $e) {
    error_log('itala: ' . $e->getMessage());
    fallar('error', 500);
}
