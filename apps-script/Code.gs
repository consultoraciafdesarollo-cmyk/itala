// Recibe del servidor de ITALA los conteos cargados en la app y los escribe en la pestaña
// App Conteos. Nunca toca las pestañas Historico*. La instalación está en INSTALAR.md.

const HOJA = 'App Conteos';
const COLUMNAS = ['ID', 'Codigo', 'Producto', 'Cantidad', 'Estado', 'Ubicacion', 'Fecha y Hora', 'Responsable', 'Ticket', 'Anulada', 'Clave'];
const COL_TICKET = 9;
const COL_ANULADA = 10;
const COL_CLAVE = 11;
const MAX_ITEMS = 100;

function doPost(e) {
  try {
    const cuerpo = JSON.parse(e.postData.contents);
    const token = PropertiesService.getScriptProperties().getProperty('TOKEN');
    if (!token || cuerpo.token !== token) return responder({ ok: false, error: 'token' });
    if (!Array.isArray(cuerpo.items) || cuerpo.items.length > MAX_ITEMS) return responder({ ok: false, error: 'items' });
    const candado = LockService.getScriptLock();
    candado.waitLock(20000);
    try {
      aplicar(cuerpo.items);
    } finally {
      candado.releaseLock();
    }
    return responder({ ok: true });
  } catch (err) {
    return responder({ ok: false, error: String(err) });
  }
}

function aplicar(items) {
  const hoja = obtenerHoja();
  const ultima = hoja.getLastRow();
  const filaPorClave = new Map();
  if (ultima > 1) {
    hoja.getRange(2, COL_CLAVE, ultima - 1, 1).getValues().forEach((f, i) => filaPorClave.set(String(f[0]), i + 2));
  }

  const nuevas = [];
  const marcas = [];
  for (const item of items) {
    const d = item.datos || {};
    if (item.tipo === 'linea') {
      if (!d.k || filaPorClave.has(d.k)) continue; // un reintento no duplica
      filaPorClave.set(d.k, ultima + 1 + nuevas.length);
      nuevas.push([d.c, d.b, d.d, d.q, d.e, d.u, d.t, d.r, d.et ? 'SI' : '', '', d.k].map(limpiar));
    } else if (item.tipo === 'marca') {
      marcas.push(d);
    } else {
      throw new Error('tipo desconocido');
    }
  }

  if (nuevas.length) {
    const faltan = ultima + nuevas.length - hoja.getMaxRows();
    if (faltan > 0) hoja.insertRowsAfter(hoja.getMaxRows(), faltan);
    hoja.getRange(ultima + 1, 1, nuevas.length, COLUMNAS.length).setNumberFormat('@').setValues(nuevas);
  }
  for (const m of marcas) {
    const fila = filaPorClave.get(m.k);
    if (fila) hoja.getRange(fila, m.tipo === 'anular' ? COL_ANULADA : COL_TICKET).setValue('SI');
  }
}

function obtenerHoja() {
  const libro = SpreadsheetApp.getActiveSpreadsheet();
  let hoja = libro.getSheetByName(HOJA);
  if (hoja) return hoja;
  hoja = libro.insertSheet(HOJA);
  // Formato de texto: si no, Sheets convierte códigos como 3/07 en fechas.
  hoja.getRange(1, 1, hoja.getMaxRows(), COLUMNAS.length).setNumberFormat('@');
  hoja.getRange(1, 1, 1, COLUMNAS.length).setValues([COLUMNAS]).setFontWeight('bold');
  hoja.setFrozenRows(1);
  return hoja;
}

// Evita que un texto cargado en la app se interprete como fórmula.
function limpiar(v) {
  const t = v === null || v === undefined ? '' : String(v);
  return /^[=+\-@]/.test(t) ? "'" + t : t;
}

function responder(datos) {
  return ContentService.createTextOutput(JSON.stringify(datos)).setMimeType(ContentService.MimeType.JSON);
}
