# Ecard obligatoria en correos de reunión

El envío de confirmación y reprogramación conserva una Ecard obligatoria. Sin archivo se genera la plantilla institucional seleccionada, con los datos de la reunión. El usuario puede reemplazarla únicamente en ese correo mediante un JPG o PNG, revisar la vista previa ampliada y volver a «Usar predeterminada». No cambia la configuración general ni las Ecards de otras reuniones. El selector de plantilla se oculta mientras se utiliza una imagen propia.

El servidor valida la carga HTTP real, errores de subida, bytes (5 MB), tipo detectado (JPG/PNG), dimensiones (6,000 por lado y 12 MP) y decodificación GD. Recodifica la imagen con nombre controlado, sin metadatos ni contenido añadido. No acepta rutas del cliente, SVG o HTML. El indicador de imagen esperada evita enviar silenciosamente la predeterminada si se pierde el archivo. La imagen de reemplazo se guarda temporalmente en storage/mail/ecards/temporales y se elimina en finally tras el transporte síncrono, también en error. No se guarda como nueva plantilla.

Los permisos, identificación del remitente, destinatario, validación de reunión/fecha, transporte, firma y protección de envío existente permanecen. Se suministra exactamente una imagen embebida CID ecard-reunion. La agenda sólo registra el envío después de que el transporte lo acepte, como antes. Los correos de cancelación y seguimiento no cambian.

## Compatibilidad

Ocho hashes de Hostinger identificados exactamente. El commit de compatibilidad 6d0b5faa incorpora al repositorio las variantes ya instaladas de agenda.php, agenda_reunion.js y EcardReunionService.php. La publicación no sustituye el generador ni el JS de agenda: son dependencias de sólo lectura.

El paquete escribe seis archivos: controlador y servicio del correo, vista de agenda, nuevo validador de imagen, CSS y JS nuevos. Verifica cinco dependencias: generador, JS de agenda, JS de envío, CSS de agenda y refinamientos. No toca configuración privada, correo de seguimiento, telefonía, asignaciones o datos históricos. No requiere SQL, migración ni datos nuevos.

## Pruebas

- HTTP multipart real con controlador/servicio actuales y adaptadores simulados de transporte/BD: predeterminada, PNG/JPEG, reprogramación, una sola imagen, permisos, reunión inválida, archivo perdido, formato, bytes/dimensiones, recodificación, fallo de generación/transporte y limpieza. No envía correo ni abre una base de datos.
- Vista del modal y tarjeta generadas por los archivos instalados, selector/imagen propia, botón de restauración, FormData del JS de envío real, archivo inválido, reapertura y 1366/390/320 px.
- Paquete en réplica desechable con hashes exactos: check sin escrituras, variantes y dependencias desconocidas, integridad, idempotencia, archivos nuevos, permisos públicos 644 y restore protegido. Probado con Python 3.6.8 y moderno.

## Instalación y reversión

Descargar publicador y manifiesto del mismo commit. Ejecutar --check antes de --apply con --site y --backup-root fuera del sitio. Requiere PHP 8.1+, GD, fileinfo y mbstring. Mantiene permisos de PHP existentes y publica CSS/JS como 644. El respaldo contiene los tres originales; la restauración elimina los tres archivos nuevos y rechaza ediciones posteriores.

Para volver: usar el mismo publicador/manifiesto con --restore /ruta/del/respaldo y los mismos site/backup-root. No desplegar la rama completa: incluye variantes de producción sincronizadas sólo como referencia.
