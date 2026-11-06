# Conectar la escritura en la planilla

Esto hace que lo cargado en la app de relevamiento se copie a la planilla **Historico Stock Itala**, en una pestaña nueva llamada **App Conteos**. Las pestañas `Historico*` no se tocan. Lleva unos 5 minutos y se hace una sola vez.

**Quién lo hace:** alguien con acceso de edición propio a la planilla, con su cuenta de Google. No alcanza con editar mediante el link compartido, porque el script escribe en nombre de esa cuenta.

**Antes de empezar:** pedí el token a quien administra la app. Es una clave larga y no hay que compartirla.

## Pasos

1. Abrí la planilla y entrá a **Extensiones → Apps Script**.
2. Borrá lo que haya en el editor y pegá el contenido de [Code.gs](Code.gs). Guardá.
3. Andá a **Configuración del proyecto** (ícono de engranaje) → **Propiedades de la secuencia de comandos** → **Agregar propiedad**:
   - Propiedad: `TOKEN`
   - Valor: el token que te pasaron
4. Guardá las propiedades.
5. Arriba a la derecha, **Implementar → Nueva implementación**.
6. En el engranaje de "Seleccionar tipo", elegí **Aplicación web**, con esta configuración:
   - Ejecutar como: **Yo**
   - Quién tiene acceso: **Cualquier persona**
7. Tocá **Implementar** y autorizá los permisos que pide Google.
8. Copiá la **URL de la aplicación web** y pasásela a quien administra la app.

## Si cambiás el script más adelante

Hay que publicar la versión nueva, o sigue corriendo la anterior: **Implementar → Gestionar implementaciones → editar → Versión: Nueva versión → Implementar**. La URL no cambia.

## Cómo saber que anda

Entrá a la app, pestaña **Planilla**. En el panel de la planilla tiene que decir que todo lo cargado ya está en la planilla. Si hay un error, aparece ahí mismo, y los conteos siguen guardados y se reintentan solos.
