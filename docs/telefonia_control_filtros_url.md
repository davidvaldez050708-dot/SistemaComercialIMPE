# URL de los filtros automáticos de Control de llamadas

Al cambiar fecha, rol, usuario, dirección u orden, la URL terminaba en `/[object%20HTMLInputElement]?controller=telefonia&action=index…` y Hostinger mostraba una página 404. El formulario contiene un campo oculto `name="action"` del router. Ese control oculta la propiedad DOM `form.action`, de modo que `new URL(filters.action, …)` recibía un elemento HTML en lugar de la dirección del formulario.

Se obtiene ahora la dirección desde `filters.getAttribute('action')`, con la URL actual como respaldo cuando no existe atributo. El campo oculto action=index sigue serializándose con FormData y no cambia el contrato del router. Se conservan el debounce de 240 ms, los seis filtros, actualización completa de métricas/ranking/historial mediante navegación al servidor, validación de hasta 90 días, prevención de envíos iguales y Limpiar filtros.

Sólo se publica `public/javascript/telefonia_control.js`, cuya base exacta de producción tiene SHA-256 `599e413b3114c91fcc3f0490a6f30064a95366281153a095027de4194ff6eb9a`. No se modifican la vista personalizada de producción, estilos, permisos, controladores, consultas, estadísticas guardadas, resultados de llamadas, configuración o motor telefónico. El cliente de filtros de Ventas auditado ya usa getAttribute y se conserva intacto.

Pruebas:

- `node tests/telefonia_control_filtros_url_browser.cjs`: renderiza la vista PHP real con datos sintéticos, carga el cliente completo en Edge/Playwright y reproduce la colisión DOM. Verifica la navegación de los seis filtros, parámetros del router, raíz y subcarpeta, nueva respuesta del servidor, debounce, ausencia de duplicados, rangos inválidos y Limpiar filtros. No conecta a SQL, PBX ni al sitio real. PHP_BINARY, PLAYWRIGHT_MODULE_PATH y CHROME_PATH permiten seleccionar los runtimes locales.
- `python tests/telefonia_control_filtros_url_publicacion.py`: sitio sintético con la base auditada exacta, CHECK sin escrituras, versiones desconocidas, respaldo, APPLY idempotente, restauración bloqueada ante personalizaciones posteriores y fallo de publicación. La configuración privada permanece intacta.

La entrega `tools/publicar_telefonia_filtros_url.py` usa un manifiesto propio fijado por SHA-256. Ofrece `--check`, `--apply` y `--restore RUTA_EXACTA`, con `--site` y `--backup-root` fuera de public_html. Respalda el original, verifica los hashes, sustituye atómicamente el archivo y conserva la reversión. Si Node.js está disponible comprueba además la sintaxis; de lo contrario verifica el hash del JavaScript ya probado. No ejecuta código PHP del CRM ni SQL.

Después de aplicar, volver a Control de llamadas desde una ruta válida del CRM y cargar el cliente nuevo con Ctrl+F5. Recargar la página 404 anterior no corrige una dirección que ya está mal escrita en la barra del navegador. Comprobar primero Rol y Dirección: la dirección debe conservar `index.php?controller=telefonia&action=index` y los valores de los filtros.
