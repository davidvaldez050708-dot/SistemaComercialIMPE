# Información enviada por WhatsApp

El usuario confirmó que el resultado se usará **después de hablar con el prospecto**. Se añade `INFORMACION_ENVIADA_WHATSAPP` al catálogo compartido, con etiqueta «Información enviada por WhatsApp». Es una declaración manual del asesor; no inicia un envío ni acredita entrega o lectura en WhatsApp.

Se incluye en `esConversacion`, por lo que el guardado exige la conexión PBX propia existente y los reportes/desempeño lo incluyen en conversaciones confirmadas con la misma regla. La distribución y los filtros de resultados, historial, supervisión y PDF usan el catálogo compartido. El selector conserva su HTML/CSS. La próxima llamada sigue siendo opcional para este resultado. Se mantiene la prohibición de sobrescribir resultados y las observaciones adicionales independientes.

Se añade al conjunto de resultados que atienden la devolución de la llamada correspondiente. Se conservan las comprobaciones de usuario, referencia, extensión, versión y estado de la devolución. No se cambia el resto del flujo telefónico.

Versiones confirmadas por SSH:

- TelefoniaResultadoVentasService.php: `0123ff766762d574fde8cde5bdcda8aa6da8c788604307f5be0c21dcf031bb6c` (`08451cb7`). Sólo se añaden constante, opción y clasificación.
- TelefoniaDevolucionVentasService.php: `af37f65048e00ee1f1df6017ad079716854e98cd4312bb0bf087de908d555fc3` (`77d57ada`). Sólo se amplía el conjunto de resultados que atienden la devolución.

`tests/ventas_whatsapp_db.py` utiliza una copia efímera del snapshot telefónico publicado y el helper original de pendientes, superpone los dos servicios modificados y ejecuta los escenarios PBX existentes con casos adicionales. Usa una base local desechable, nunca la configuración del CRM. Pasaron 43 comprobaciones: conexión obligatoria, autorización por propietario, guardado definitivo, seguimiento opcional, devolución atendida, conteo y tasa de contacto sin duplicados, distribución de resultados, selector HTML y escenarios telefónicos anteriores.

El publicador `tools/publicar_ventas_resultado_whatsapp.py` instala exclusivamente esos dos servicios con versiones exactas y respaldo privado; no ejecuta SQL ni modifica las grabaciones. `tests/publicacion_ventas_resultado_whatsapp_test.py` comprueba LF/CRLF, variantes desconocidas, check sin escrituras, aplicación repetible y restauración protegida. PHP lint aprobado.
