# Relevamiento de repuestos ITALA

App para relevar el stock de repuestos: compara lo contado con las existencias del sistema y genera los lotes de ajuste. Publicada en https://itala-stock.ciafconsultora.com.ar

PHP 8.2 sin framework ni dependencias, con SQLite. Las pantallas son `public/index.html` y hablan con `public/api.php`.

## Cómo funciona

- **Conexión:** la app no importa nada. El servidor lee todas las pestañas de la planilla de Google cada minuto (`src/Planilla.php`).
- **Conteos:** todas las pestañas con encabezados `ID` y `Codigo`.
- **Existencias (opcional):** una pestaña con encabezado `Articulo`. Sin ella, la app muestra solo conteos y oculta valores, Cierres y Foto 0.
- **Lo cargado en la app:** se guarda en la base y sale por una cola hacia la pestaña `App Conteos`, mediante un Apps Script (`apps-script/`). Si Google falla, la cola reintenta sola.
- **A la planilla nunca van costos ni cierres.**

La app no tiene login: cualquiera con la URL ve los datos y carga conteos. Por eso el sitio no se indexa y la base se respalda todos los días.

## Correr en local

```bash
cp config.example.php /ruta/fuera/del/repo/config.php   # y completar las rutas
export ITALA_CONFIG=/ruta/fuera/del/repo/config.php
php bin/migrar.php
php -S 127.0.0.1:8080 -t public
```

## Publicar

`git push origin main`. El cron del servidor hace el pull en menos de un minuto, migra la base y deja el resultado en `~/domains/<sitio>/itala/cron.log`.

| Qué | Dónde (en el servidor) |
|---|---|
| Clone y docroot | `~/domains/<sitio>/public_html` |
| Configuración, base, log y `cron.sh` | `~/domains/<sitio>/itala/` |
| Respaldos diarios (14 días) | `~/backups-itala/` |
| Cron | hPanel → Avanzado → Cron Jobs, cada minuto, `~/domains/<sitio>/itala/cron.sh` |

`config.php`, la base y los Excel nunca van al repo: es público.
