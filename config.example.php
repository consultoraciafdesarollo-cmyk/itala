<?php

// Copiar a ~/domains/<sitio>/itala/config.php, fuera del repo y de la carpeta web.
// Nunca versionar el archivo real: el repositorio es público.

return [
    'db' => '/home/USUARIO/domains/SITIO/itala/itala.sqlite',
    'backups' => '/home/USUARIO/backups-itala',
    'sheet_id' => '',       // ID de la planilla "Historico Stock Itala"
    'script_url' => '',     // URL del Web App de Apps Script (ver apps-script/INSTALAR.md)
    'script_token' => '',   // mismo valor que la propiedad TOKEN del script
];
