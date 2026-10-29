# Historial bloqueado por una marcación pendiente duplicada

La lectura directa de llamadas funcionaba, pero la conciliación automática podía lanzar un error MySQL 1062 cuando un intento pendiente coincidía con una PBX que ya pertenecía a otra marcación. La restricción única conservaba correctamente el vínculo original; la excepción detenía la consulta completa del marcador, Mis llamadas y detalle de resultado. El fallo dependía de los datos de una cuenta, sin relación demostrada con el sistema operativo o navegador.

La comprobación de Hostinger confirmó un candidato ya vinculado en la extensión 150. Las lecturas directas devolvieron 223 llamadas para esa cuenta. Se conservan la referencia, el vínculo original y el intento pendiente: no se fusionan, eliminan ni reasignan registros.

## Cambio

Sólo cambia `TelefoniaMarcacionesVentasService::vincular()`. El error 1062 del UPDATE que intenta ocupar una referencia ya vinculada se convierte en una excepción de dominio. La conciliación ya maneja estas excepciones como evidencia insuficiente y continúa con los demás intentos. La validación del propietario, extensión, destino, ventana temporal y exclusión de Vinculación permanece igual. Los errores SQL distintos de 1062 siguen propagándose. El statement se cierra también cuando hay error.

La publicación sustituye únicamente ese servicio sobre el hash confirmado `4259242d993ba567a201637988d404f9b5bad3e92d323558887f934bb92eefea`. No ejecuta SQL, ni cambia el teléfono del navegador, formulario, catálogo, webhooks, reproducción o grabaciones.

## Pruebas

- MariaDB local desechable: reproduce el error con el servicio original exacto de Hostinger y confirma la continuidad con el servicio corregido.
- Conserva ambos intentos y el vínculo anterior; historial, bandeja y detalle accesibles; resultado guardado después del cierre real.
- Otro intento válido de la misma cuenta se concilia correctamente. Un error SQL 1644 de prueba no se oculta.
- Ejecuta también las regresiones existentes de permisos, propietarios, eventos PBX, WhatsApp, cierre, cancelación y resultados definitivos: 93 comprobaciones DB. Cubre mysqli con y sin excepciones automáticas.
- Publicador selectivo: hashes, LF/CRLF, respaldo, idempotencia, rechazo de variantes desconocidas y restauración exacta. Protege archivos de captura, telefonía y un audio de prueba.

La herramienta `diagnosticar_historial_ventas.php` sólo lee. Sustituye la vinculación por una consulta que detecta candidatos ya vinculados, dentro de una transacción de sólo lectura. Que siga mostrando ese candidato después de instalar es esperado: la corrección conserva el intento y evita que su conflicto bloquee la cuenta.
