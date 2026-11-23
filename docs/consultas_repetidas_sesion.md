# Consultas repetidas y espera de sesión

El perfil de producción del 23 de noviembre de 2026 registró `telefonia.detalleVenta`
con mediana de 1032.02 ms y 251 consultas en la muestra máxima; `reminder.pendientes`
con mediana de 690.47 ms y 428 consultas en la muestra máxima. Un sondeo de
disponibilidad esperó 683.82 ms para abrir la sesión, con acción de sólo 1.56 ms.
La muestra no permite atribuir toda la demora de navegación a estos dos endpoints,
ni mide la cola del servidor anterior al inicio de PHP. SQL es cantidad de comandos,
no duración acumulada de SQL. El mecanismo de admisión no está instalado en producción.

## Cambios

- `TelefoniaVentasLlamadasService::sincronizar` obtiene los datos de transferencias
  candidatas en la misma consulta agrupada. Antes consultaba otra vez cada solicitud,
  incluso las que descartaba por no ser rutas comerciales. Se conserva la selección
  de una única solicitud por llamada, usuario, extensión, referencia y estado, y las
  validaciones PHP de la ruta antes de actualizar. No se modifica el resto del backfill,
  las exclusiones de limpieza, la conversión UTC, las evidencias ni las llamadas a SDK.
- `detalleVenta` libera la sesión después de autorizar la petición GET y antes de
  sincronizar. Conserva todas las ramas instaladas: pendientes, solicitud, SDK y referencia.
- La campana libera la sesión después de los permisos. Para los perfiles que consultan
  observaciones de seguimientos, espera a guardar su deduplicación en sesión y luego
  libera. No se cambia el conjunto de notificaciones, sus entregas registradas en SQL,
  las conexiones independientes ni el orden de construcción de servicios instalados.

## Verificación

`py -3 tests/consultas_repetidas_test.py` comprueba los bloques reales suministrados,
las variantes locales, PHP lint, CRLF, idempotencia, preservación de personalizaciones,
Python 3.6, publicación y restauración, rechazo de conflictos antes de escribir, y
procesos PHP concurrentes con una sesión real. Comprueba que los analistas conservan
los avisos mostrados y que la lectura del detalle deja navegar a la segunda petición.

`php tests/consultas_metadata_db_test.php <clave-de-la-base-local-de-pruebas>` utiliza
sólo MariaDB local en el puerto 33472 y un esquema desechable de nombre fijo. Compara
el bloque instalado con el nuevo sobre 225 llamadas sintéticas: produce exactamente
las mismas cinco actualizaciones y reduce 227 comandos a 6. Incluye rutas de
Vinculación, usuario/extensión/referencia distintos, dos solicitudes para una llamada,
JSON inválido, llamadas salientes y metadata ya asignada. Repetir no altera resultados.
No se conecta con el proveedor ni envía mensajes o inicia llamadas.

Esta reducción es de un bloque bajo datos sintéticos; no es una medición de la
mejora total del sitio. Debe compararse el perfil posterior y la navegación real.

## Publicación selectiva

`tools/preparar_consultas_repetidas.py` genera el JSON y el publicador a partir del
estado previo y los cambios revisados. El publicador admite `--check`, `--apply` y
`--restore RUTA`, con `--site .../public_html --backup-root .../impe-private`.
Comprueba y valida los tres archivos antes de escribir, respalda fuera de la web,
conserva bloques ajenos, detecta cambios concurrentes y revierte ante errores de escritura.

Si el perfil temporal ya está instalado, `--apply` lo activa una hora y guarda un
inicio de medición posterior. `php tools/informe_consultas_repetidas.php RUTA_PUBLIC_HTML`
lee sólo las muestras a partir de esa publicación; no mezcla los máximos anteriores.
Volver a aplicar reinicia esta ventana de comparación. El perfil sigue siendo temporal,
sin usuarios, parámetros de petición, SQL, tokens o contraseñas en los registros.

Los archivos probados son copias de los métodos enviados por el usuario, no un
volcado del servidor. El publicador debe rechazar cualquier bloque instalado que
ya haya cambiado, antes de sustituirlo.
