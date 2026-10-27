# Corrección del envío del importador Excel

La revisión mostraba «Selecciona un territorio asignado» aun cuando había un estado seleccionado. `setBusy(true)` deshabilitaba estado, analista y archivo antes de ejecutar `new FormData(form)`. HTML omite los controles deshabilitados: llegaban el CSRF y la operación, pero no el territorio ni el XLSX. El servidor rechazaba la petición antes de revisar asignaciones o leer el archivo.

La corrección captura `FormData` antes de bloquear los controles. No habilita territorios adicionales ni modifica el controlador o el servicio de importación. El estado y el analista siguen validados en el servidor al analizar y al confirmar.

También se corrigen estas inconsistencias del formulario:

- Una revisión sin instituciones nuevas mantiene la confirmación oculta y deshabilitada; no conserva un token utilizable en el cliente.
- Si una confirmación falla, el formulario descarta su token y exige analizar de nuevo. El servidor consume ese token antes de intentar guardar. Repetirlo no era una recuperación válida.
- Ante una respuesta interrumpida o un error de servidor, no se asegura que no hubo guardado: se indica revisar los seguimientos antes de analizar otra vez. No se hace una confirmación automática ni un reintento oculto.
- Se conservan las selecciones y el archivo ante errores para poder analizar nuevamente.
- Las peticiones identifican el transporte Fetch para recibir la respuesta JSON del CRM cuando la sesión o el CSRF caducan.
- Envíos nativos del formulario usan el mismo análisis; cargar dos veces el script no duplica sus listeners.
- La versión del JavaScript usa `filemtime`, evitando conservar el código anterior bajo el mismo identificador estático de caché.

El diseño y el CSS quedan iguales. No se cambian el archivo adjunto, las reglas de duplicados, las etapas históricas, los permisos ni los datos existentes. Analizar continúa siendo una revisión; crear registros exige la confirmación explícita del usuario.

## Publicación

El paquete `publicar_seguimiento_excel_territorio.py` y su manifiesto publican únicamente:

1. `public/javascript/seguimiento_importacion_excel.js`.
2. `app/views/seguimiento_vinculacion/index.php`.

Los dos hashes previos fueron contrastados con Hostinger. El publicador admite LF y CRLF, comprueba todo antes de escribir, guarda un respaldo privado y revierte las escrituras si falla la aplicación. Una variante desconocida aborta sin sobrescribirla.

Después de descargar/verificar ambos componentes desde la revisión fija de la entrega, ejecutar en su carpeta privada:

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
RESPALDOS="$HOME/respaldos-impe-importador-territorio"
python3 publicar_seguimiento_excel_territorio.py --site "$SITIO" --backup-root "$RESPALDOS" --check
python3 publicar_seguimiento_excel_territorio.py --site "$SITIO" --backup-root "$RESPALDOS" --apply
```

No ejecuta SQL. Para restaurar, usar el mismo paquete con `--restore RUTA_EXACTA_DEL_RESPALDO`, conservando los mismos `--site` y `--backup-root`. La restauración rechaza cambios posteriores para no sobrescribir otras personalizaciones.

## Validación

- Reproducción con el JavaScript original: faltan territorio y archivo en el cuerpo multipart, con el mensaje del incidente.
- Prueba de navegador con vista, estilos y plantilla XLSX reales: bytes del archivo intactos, territorio/analista/CSRF presentes, bloqueo de doble clic, análisis separado de confirmación, revisión de filas, reinicio de controles, errores 409/500, archivo ausente o mayor de 2 MB, invalidación de revisión al cambiar los datos y rechazo del territorio fuera del alcance simulado.
- Vista de analista, supervisor y alcance global en 1366/390 px. El transporte de esa prueba simula las respuestas del controlador; no acredita una importación en Hostinger ni escribe datos.
- Prueba PHP existente de plantilla oficial, catálogos territoriales y pasos históricos 00–13.
- Prueba del publicador con LF/CRLF, versiones desconocidas, idempotencia, respaldo y restauración; el controlador se conserva byte a byte.

Tras instalar, recargar el CRM, abrir Importar instituciones, seleccionar el mismo territorio y Excel y pulsar Analizar. Revisar el resumen y las filas antes de confirmar. Si el Excel contiene valores inválidos, deben aparecer por fila como resultado de la validación; seleccionar un estado no evita las comprobaciones de su contenido.
