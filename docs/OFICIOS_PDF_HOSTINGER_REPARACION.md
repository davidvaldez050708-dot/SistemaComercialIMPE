# Oficios REDMEX: conversión PHP conectada al flujo real

Base: `origin/main` (`fd5fd282`). Rama: `codex/oficios-pdf-hostinger-main`.
Estado: corrección funcional y publicador preparados; validación en Hostinger pendiente.
No se ha desplegado, fusionado ni modificado producción.

## Causa comprobada y límite del diagnóstico

El publicador del PR #118 sólo integraba preparación DOCX. Main seguía convirtiendo
mediante el lector Word2007 de PHPWord y exigía `proc_open` incluso antes de elegir motor.
El renderizador REDMEX probado en el PR #117 no estaba conectado al main ni incluido
en aquella publicación de dos archivos.

El lector PHPWord de main, con la plantilla original disponible, reproduce localmente:
`InvalidArgumentException: Comment with id 0 isn't referenced in document`.
La recuperación anterior mediante PHPWord producía dos páginas A4 y perdía recursos.
No basta con recuperar comentarios o quitar el requisito de procesos externos.

La usuaria volvió a proporcionar el mensaje visible del modal, no el detalle del log
web. Por tanto **no se atribuye a comentarios la excepción actual de Hostinger sin
evidencia del servidor**. Se revisó el diagnóstico de `9eb43322`:
`docs/OFICIOS_PDF_BLOQUEO_HOSTINGER.md`, que no existe aún en main. Dicho diagnóstico
acredita PHP CLI 8.2.34 sin `proc_open` y ausencia de LibreOffice en el PATH; no demuestra
que PHP web comparta esa configuración ni acredita una conversión web satisfactoria.

## Corrección funcional incluida

Sólo tres archivos de aplicación:

1. `app/services/OficioDocxPdfService.php`: `generarPdf()` prepara una copia temporal
   del DOCX seleccionado y llama directamente a `OficioRedmexPdfRenderer`. Este flujo
   no intenta Word, LibreOffice, comandos externos ni el lector PDF de PHPWord.
   Comprueba dependencias PHP, PDF no vacío, firma, página y cierre/xref antes de entregar.
   La ruta de una selección inválida no se sustituye silenciosamente por la general.
2. `app/services/OficioRedmexPdfRenderer.php`: recuperado del renderizador validado en
   `f4a530e8`, con registros por fase. Lee ZIP/XML y usa Dompdf directamente: imágenes,
   fuentes embebidas, posición de los grupos, tamaño carta y contenido del propio DOCX.
   No reconstruye el texto desde la BD ni sustituye párrafos por contenido fijo.
3. `app/controllers/OficioVinculacionController.php`: conserva la llamada aprobada que
   distingue primera preparación de una instantánea `generacion_*` ya preparada.

Se mantiene la preparación aprobada del PR #118 y las muestras corregidas de `f4a530e8`.
TemplateProcessor sigue preparando las personalizadas; el general conserva sus variables.
Los originales nunca se modifican. Las instantáneas ya preparadas conservan sus bytes.

`OficioPdfService`, los dos endpoints PDF, permisos, selección de biblioteca, BD, folios,
estados y ruta de 13 pasos no cambian. Los endpoints existentes reciben ahora el resultado
del motor conectado al servicio. `convertirArchivoAPdf()` y sus conversores compartidos
con Convenios conservan exactamente el código de main.

## Motor y fidelidad

| Aspecto | Prueba local ejecutada | Hostinger |
| --- | --- | --- |
| Motor usado para los candidatos PDF | Dompdf 3.1.6 directo desde PHP, sin Word/LibreOffice | Mismo código preparado para publicar |
| Entorno | Windows, PHP 8.2.12 CLI y servidor HTTP PHP local | PHP CLI 8.2.34 comunicado; PHP web por confirmar |
| Restricciones | `proc_open,exec,shell_exec,system,passthru,popen` deshabilitados; 128 MB | No se habilita ninguna función bloqueada |
| Dependencias | ZipArchive, DOM, GD, MBString, Dompdf y php-font-lib existentes; PHPWord para preparación | Publicador comprueba dependencias y Dompdf 3.1.6 |
| Referencia visual | Exportaciones Word del mismo DOCX ficticio aprobado | Word sólo es referencia de QA, no requisito del CRM |
| Plantilla general | SHA-256 `9c4750cc6f72bb2b182daaefca4d5a9cec67b13f3c4e960c031100a9e56341ad` | Se exige el perfil verificado; no se modifica para ocultar defectos |

General, personalizado del mismo modelo y preparado: una página carta 612 × 792 pt,
texto completo, mismas familias tipográficas, logos, sello y pie originales. Se comparó
todo el cuerpo institucional, incluido el párrafo de validez oficial. Las personalizaciones
permanecen en los campos existentes. No se añaden párrafos o tablas técnicas ni se recorta
texto para forzar una página. Las tres páginas comparativas se revisaron visualmente.

Persisten pequeñas diferencias de métricas/rasterizado frente a Word; no se afirma
identidad píxel a píxel. Los documentos largos conservan contenido y recursos repetidos,
pero su paginación puede diferir de Word. No se declara conversión universal: otras
geometrías, fuentes, encabezados/pies o estructuras incompatibles se rechazan sin PDF
degradado, conservando DOCX e históricos. Un diseño personalizado de otra modalidad
requiere validar su propio perfil. No se contratan APIs ni se envían documentos a terceros.

## Diagnóstico seguro

Cada conversión registra `OFICIO_PDF` con `request_id`, motor, fase, versión/SAPI PHP,
dependencias y banderas de existencia/lectura/escritura/limpieza. Los fallos del renderer
añaden clase de excepción y un código cerrado, por ejemplo `REDMEX_PAGE_SIZE`.
El mensaje al usuario incorpora una referencia sin rutas o trazas internas.

Fases: START, SOURCE/DEPENDENCIES/TEMPORARY/PREPARE, CONVERT, PROFILE/DOCX/GRAPHICS/HTML/
FONTS/RENDER/OUTPUT, VALIDATE, COMPLETE y CLEANUP. No todas se registran al tener éxito;
la fase interna se registra cuando falla. `COMPLETE` confirma PDF válido; `CLEANUP`
confirma `temp_removed:true`. Los temporales y fuentes se retiran también al fallar.

Se retiraron del diagnóstico de preparación los nombres de archivo, variables arbitrarias,
texto extraído y rutas de trazas. No se registran destinatarios, instituciones, correos,
cuerpo completo, contenido PDF ni credenciales.

## Pruebas dirigidas ejecutadas

```text
php -d memory_limit=128M -d disable_functions=proc_open,exec,shell_exec,system,passthru,popen tests/oficios_redmex_renderer_test.php
python tests/oficios_pdf_http_test.py
python tests/oficios_pdf_publicacion_test.py
python tools/oficios_redmex_comparar.py --pdftoppm RUTA_PDFTOPPM
```

- DOCX/PDF general, personalizado y preparado: contenido, campos autorizados, recursos,
  formato del original, otra modalidad sin sustitución global, documentos incompatibles,
  original intacto y limpieza. Documento largo con 65 párrafos adicionales completos.
- HTTP autenticado **local**, usando copias idénticas de los endpoints/controlador/
  servicios reales, sesiones y cookies reales y sólo una BD simulada en una réplica.
  Vista previa devuelve 200 `application/pdf` inline; generación conserva folio y registra
  GENERADO después del PDF; descarga devuelve attachment válido; DOCX continúa disponible.
  Repetir generación reutiliza el PDF y no repite la actualización.
- HTTP local: 401 sin sesión, 403 sin acceso al seguimiento, 405 en método incorrecto,
  conversión incompatible sin archivos ni cambios de estado, fallo de registro sin PDF
  nuevo persistente y fallo de temporales sin rutas expuestas. Logs sin datos de prueba
  privados, con motor, fase, excepción y limpieza. No se verifican territorios ni BD real.
- Publicación en réplicas de main, PR #118 ya publicado, CRLF, personalizaciones y
  auxiliar anterior conocido. Check sin escrituras, apply/restore exactos, idempotencia,
  rechazo de conflictos/auxiliares desconocidos y protección de ediciones posteriores.

Muestras ficticias y comparaciones: `output/pdf/oficios-redmex/`. Los `*-word.pdf` son
referencias previas aprobadas; los PDF candidatos se generaron con el PHP de esta rama.
No se ejecutó CI global ni se probaron otros módulos.

## Publicación preparada

Usar únicamente `tools/publicar_oficios_pdf_hostinger.sh` para esta reparación. El anterior
publicador de contenido no instala ni conecta el motor PDF.

```bash
bash "$HOME/publicar_oficios_pdf_hostinger.sh" --check
# Sólo tras revisar el resultado compatible:
bash "$HOME/publicar_oficios_pdf_hostinger.sh" --apply
# Para revertir, pegar la ruta exacta impresa después de Respaldo:
read -r -p "Ruta exacta del respaldo: " IMPE_RESPALDO_OFICIOS
bash "$HOME/publicar_oficios_pdf_hostinger.sh" --restore "$IMPE_RESPALDO_OFICIOS"
```

Descargar/guardar el script fuera de `public_html`. Raíz predeterminada:
`$HOME/domains/fundacionrededumex.org/public_html`, ajustable con `IMPE_OFICIOS_PDF_PUBLIC`.
Respaldos fuera del sitio: `$HOME/respaldos-crm`, ajustables con `IMPE_OFICIOS_PDF_BACKUPS`.
No ejecutar `git pull` ni copiar el árbol completo para publicar esta corrección.

El publicador sólo instala el renderer y parchea controlador/servicio. Reconoce los
fragmentos de preparación ya publicados con el PR #118, preserva otras personalizaciones,
LF/CRLF y permisos, valida sintaxis y respalda antes de escribir. Restaura ante errores.
Un auxiliar desconocido o un conflicto se rechazan antes de escribir. No publica vendor,
plantillas, pruebas, muestras, configuración, SQL ni endpoints de diagnóstico.

## Procedimiento pendiente en Hostinger (pruebas E–H reales)

1. Descargar el publicador de esta entrega, ejecutar `--check`, revisar y después aplicar
   manualmente. Guardar la ruta exacta del respaldo. No se ha ejecutado desde esta tarea.
2. Iniciar sesión como Analista responsable y abrir un oficio existente en borrador,
   con folio y sin PDF previamente guardado. Anotar folio y estado. Los PDF históricos
   existentes se reutilizan; no se eliminan para forzar conversiones.
3. Abrir **Vista previa del oficio**. En Red del navegador comprobar el endpoint existente
   `public/oficio_vista_previa_documento.php?seguimiento_id=ID`: HTTP 200, `application/pdf`,
   inline. Comprobar una página carta para el modelo general, logos, sello, pie, destinatario,
   párrafos completos y folio correcto. Vista previa no debe marcar GENERADO.
4. En el registro PHP de Hostinger localizar las líneas `OFICIO_PDF` de esa solicitud:
   `engine:REDMEX_DOMPDF`, SAPI web real, COMPLETE con result ok y CLEANUP con temp_removed
   true. Un OK del PHP CLI no sustituye esta comprobación del PHP web.
5. Usar **Revisar y generar PDF**. Confirmar mismo folio y estado GENERADO sólo después
   del éxito. Descargar mediante el botón existente: endpoint
   `public/oficio_pdf_documento.php?seguimiento_id=ID&descargar=1`, HTTP 200, PDF válido,
   attachment y contenido del oficio solicitado.
6. Repetir vista previa/descarga con un personalizado preparado del mismo formato
   institucional y comprobar sus modificaciones autorizadas contra su DOCX. Repetir la
   generación exitosa debe reutilizar el archivo y conservar el folio.
7. Si aparece un error, conservar la referencia del modal y revisar sólo las líneas
   `OFICIO_PDF` de ese request_id. No compartir el log completo ni documentos reales.
   Un rechazo de perfil exige revisar ese diseño; no sustituirlo por un general.

No se inducen fallos ni se crean oficios ficticios en producción para ensayar estados.
La prueba transaccional con MySQL real, las versiones/límites del PHP web y la fidelidad
del documento concreto del servidor permanecen pendientes de esa comprobación.
Hasta entonces no se declara reparada la generación PDF en Hostinger.
