# Restauración exclusiva de la tipografía de Ecard

El diagnóstico de Hostinger confirmó VERSION_ECARD=20260920-14, SHA256 6b77dae491cb9ff3009d357b7e469683b2f41199c1fa4e489bdbff49c73e33fc y disponibilidad de FreeType, DejaVuSans.ttf y DejaVuSans-Bold.ttf. El hash coincide exactamente con la versión del generador conservada en 050428d9. La corrección de 469f39eb no estaba instalada en ese archivo del servidor.

El historial (21513d15 y 49639833) ya establecía tamaños ajustables de 38 pt para la institución, 44 pt para la fecha y 34 pt para hora/modalidad en la plantilla Manuel. El problema era que resolverFuente sólo buscaba Arial del sistema o rutas Linux concretas; al no encontrarlas, texto() usaba imagestring(...,5), cuyo tamaño fijo no utiliza el tamaño solicitado. Se reproduce la captura con PRUEBA 2 y 12:37 PM. Con los recursos del proyecto, la altura real de fecha pasa de 11 a 54 píxeles y la de hora de 11 a 34 en la imagen de 1200x1212.

El generador corregido busca primero las fuentes TrueType distribuidas con Dompdf, verifica su carga y evita producir otra imagen bitmap si falta FreeType. Conserva los tamaños históricos, la plantilla y los datos. La versión 20261026-tipografia-1 cambia tanto la clave del JPEG guardado como la URL de vista previa que ya construye AgendaReunionService. La siguiente generación no reutiliza el archivo de letra pequeña y elimina sólo versiones anteriores de esa reunión. No modifica correos que ya se enviaron.

## Publicación de un solo archivo

`tools/publicar_ecard_tipografia_hostinger.sh`, SHA256 `75d954c5ffecd9892b0ac0f9b87a954ba1d8aa3ef680b46eb7cee6ead0e933cc`. Modos --check, --apply, --probe y --restore RUTA. El publicador no descarga dependencias, no ejecuta SQL y no modifica notificaciones, aliados, configuración ni datos de reuniones. Publica exclusivamente los bloques revisados de app/services/EcardReunionService.php. Admite la versión exacta diagnosticada, el generador ya corregido y contenido exterior personalizado; rechaza cambios incompatibles. Incluye respaldo privado y comando de restauración.

Después de --check y --apply, ejecutar --probe. Este modo carga el generador del sitio sin llamar al constructor ni abrir BD, renderiza una Ecard ficticia en el temporal del sistema y comprueba la altura de los píxeles de fecha/hora. Retira su imagen temporal al terminar, incluso si la validación falla; no escribe en storage/mail/ecards. Debe informar VERSION_ECARD=20261026-tipografia-1 y una prueba de texto legible. Luego recargar la agenda y volver a abrir la vista previa. Hasta recibir la salida de aplicación/prueba y contrastar la vista del servidor, la publicación queda pendiente.

## Verificación local

- tests/ecard_tipografia_test.php usa el archivo exacto diagnosticado en Hostinger y el historial aprobado. Reproduce el JPEG antiguo, mide fecha/hora antes y después, comprueba la versión de caché y su reutilización posterior, conserva otra reunión y compara píxel a píxel fondos, logos y retrato. Todo se genera en un directorio ficticio, sin BD ni envío de mensajes.
- tests/ecard_tipografia_publicador_test.py prueba LF/CRLF, check sin escritura, un solo archivo modificado, aplicación idempotente, restauración exacta, rechazo de cambios posteriores y personalizaciones incompatibles. Ejecuta --probe con un Database que falla si alguien intenta construirlo y comprueba que no se escriba caché pública. También fuerza un fallo de plantilla para verificar la limpieza del temporal.
- tools/diagnosticar_ecard_tipografia.php contiene la misma prueba de imagen que el publicador incorpora de forma autocontenida. Sólo se ejecuta por CLI y no se instala como endpoint web con este publicador.
