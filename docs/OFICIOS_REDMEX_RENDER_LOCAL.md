# Oficios REDMEX: conversión local controlada

Rama: `codex/oficios-render-institucional`, basada en `ec63cc36`.
Sin despliegue, migraciones, cambios de permisos, dependencias nuevas ni APIs.
La recuperación de comentarios existente no se modifica ni se vuelve a implementar.

## Flujo comprobado

- El selector de **Generar oficio** lo construye `public/javascript/oficios_vinculacion.js`.
  Ofrece plantilla general, biblioteca y carga DOCX para esta generación. La carga de
  biblioteca recibe un nombre y conserva el original como `oficio_FECHA_HORA_ID.docx`.
- Los endpoints `plantillas`, `subirPlantilla`, `eliminarPlantilla` y `generarBorrador`
  existen y son alcanzables. No hay editor del cuerpo en el CRM: se modifica en Word
  y se sube el DOCX, sin añadir una funcionalidad nueva de edición web.
- La biblioteca registra nombre y `archivo_docx` en la estructura existente
  `plantillas_vinculacion`; el oficio guarda su `plantilla_oficio_id`. Se utiliza
  el modelo existente, sin cambios de esquema ni consultas de escritura adicionales.
- Elegir una plantilla guardada crea una copia `generacion_*.docx`. La carga directa
  ahora conserva también `original_generacion_*.docx`, y prepara únicamente la copia.
  Una biblioteca previamente guardada conserva su `oficio_*.docx` original.
  La carga directa ocupa dos copias DOCX; ambas deben incluirse en el respaldo habitual.
- `generacion_*.docx` es la instantánea preparada vinculada al oficio. La primera
  preparación solicita explícitamente `generarDocx(vista, true)`; las descargas
  posteriores devuelven los mismos bytes. El PDF lee esa instantánea sin volver a
  sustituir destinatario, cuerpo, analista o modificaciones manuales desde la BD.
- El listado de biblioteca excluye `generacion_*`. Eliminar una plantilla de biblioteca
  la desactiva; las copias ya vinculadas a oficios siguen disponibles. Una selección
  ausente o una ruta inválida produce error, nunca otro oficio general silencioso.
- Inconsistencia corregida: el JavaScript ya tenía una rama para el expediente/detalle,
  pero el layout sólo lo incluía en `action=estado`. Ahora detalle carga los dos scripts
  de oficios y sus estilos de vista previa. No se añaden scripts de otros módulos.

## Permisos conservados

| Perfil | Comportamiento de los controles existentes |
| --- | --- |
| Analista responsable | Con `oficios.generar` y `seguimientos_vinculacion.operar_propios`, prepara y genera PDF de sus seguimientos que cumplen requisitos; puede cargar/seleccionar plantillas. |
| Cuenta Clave | Consulta los seguimientos autorizados por territorio/supervisión y documentos; no sustituye al Analista responsable. Los permisos reales vienen de la sesión existente. |
| Administrador | Consulta transversal y administración de biblioteca; la política existente le restringe la operación `oficios.generar`. No se amplían permisos. |

Los endpoints mantienen validación de sesión, POST, permiso y pertenencia. Las pruebas
HTTP de auxiliares usan un modelo falso: no conectan ni escriben en la BD. Se prueban
rechazos de sesión ausente, Cuenta Clave y Administrador en generación; la comprobación
con sesiones y asignaciones reales en Hostinger sigue pendiente.

## Renderizador y límites explícitos

`OficioRedmexPdfRenderer` lee ZIP/XML en modo de sólo lectura. Usa Dompdf directamente
para convertir el contenido del **DOCX preparado**, sin texto institucional fijo ni
reconstrucción desde la BD. No usa el lector Word2007 que descartaba agrupaciones.

El perfil aprobado es `storage/templates/oficio_general_redmex.docx`, SHA-256:
`9c4750cc6f72bb2b182daaefca4d5a9cec67b13f3c4e960c031100a9e56341ad`.

- Carta 612 × 792 pt, márgenes del modelo y recursos institucionales originales.
  Lee las coordenadas y transformaciones de los grupos, y conserva los PNG/JPEG
  originales; no dibuja aproximaciones del sello, firma o logotipos.
- Comprueba que recursos y geometría del documento coinciden con el perfil. Utiliza
  las imágenes del documento seleccionado; no las sustituye silenciosamente por otras.
- Conserva párrafos, saltos de línea, negritas, cursivas, subrayado simple, color,
  alineación, sangrías e interlineado compatibles con estilo Normal.
- Admite tablas simples de ancho fijo, sin bordes, celdas combinadas ni tablas anidadas,
  con márgenes de celda explícitos a cero. Las demás tablas se rechazan.
- Extrae fuentes TrueType embebidas de forma temporal; usa las fuentes originales del
  modelo compartido si faltan en un DOCX del mismo perfil. Comprueba identidad de las
  fuentes incluidas, permisos técnicos de incrustación y cobertura de glifos. No instala
  ni distribuye las fuentes como archivos independientes. No sustituye familias.
- Los encabezados y pies se repiten en documentos extensos. Desde la segunda página se
  reserva el espacio superior del membrete; se reserva siempre la zona inferior del pie.
  Esta paginación segura puede diferir de Word en documentos largos: el modelo original
  tiene los gráficos anclados al cuerpo y no auténticas partes de encabezado/pie repetido.
  Nunca se reduce la tipografía ni se recorta el texto para forzar una página.
- Mantiene unido el cierre con el sello. Comprueba coordenadas de texto y sello y rechaza
  desbordamientos. Valida PDF antes de entregarlo al servicio existente.
- Rechaza relaciones externas, DTD/entidades, paquetes excesivos, otras secciones o tamaños,
  columnas, gráficos adicionales, imágenes/efectos no compatibles, estilos no admitidos,
  cuadros de texto visibles, campos dinámicos, revisiones y encabezados/pies alternativos.

**No es un conversor universal DOCX.** Un documento puede compartir la marca REDMEX pero
usar otra geometría, firma, fuentes o estructura. En ese caso conserva la descarga DOCX
y explica que no puede producir un PDF local fiel; no entrega el PDF degradado de respaldo.
Una plantilla real disponible con partes `header1.xml`/`footer1.xml` fue rechazada de forma
segura. Esos otros diseños necesitarían su propio perfil y otra comparación con Word.

La ruta compartida `convertirArchivoAPdf()` no cambia y no utiliza este renderizador.
El guardado y actualización a `GENERADO` siguen en `OficioPdfService`, después de conversión
satisfactoria. Los ZIP se cierran también al rechazar el perfil y se liberan los ciclos de métricas de fuentes antes de eliminar temporales (verificado en Windows). No se regenera un PDF ya guardado ni se borran documentos existentes.

## Evidencia visual y pruebas

En `output/pdf/oficios-redmex/` se entregan:

| Muestra ficticia | Resultado local y referencia Word |
| --- | --- |
| `general.pdf` | Una página carta, texto completo, membrete, sello y pie originales. |
| `personalizado.pdf` | Cuerpo institucional completo e idéntico al general, incluido el párrafo de validez oficial. Cambian únicamente folio, destinatario, cargo, institución y datos del analista en sus campos existentes. |
| `preparado.pdf` | Mismo cuerpo institucional; conserva la edición de nombre/teléfono del analista en la instantánea preparada, aunque la vista aporte otros valores. |
| `paginacion.pdf` | 65 párrafos adicionales completos; tres páginas carta, recursos repetidos y cierre sin solapamientos. |

Los tres `*-word.pdf` son exportaciones del mismo DOCX ficticio mediante Word local,
utilizado sólo como referencia de QA. El CRM en Hostinger no depende de esa computadora.
Los `*-comparacion.png` muestran Word a la izquierda y el PDF local a la derecha.
`comparacion.json` registra hashes, tamaños, páginas, fuentes y métricas de diferencia.

La preparación de muestras anterior sustituía un párrafo institucional por texto técnico
y añadía una tabla de prueba en `tests/oficios_redmex_renderer_test.php`. Tras la primera
corrección, seguía sustituyendo el párrafo de validez oficial. Ésta era la fuente exacta
del contenido incorrecto de las muestras entregadas; no lo insertaba el servicio del CRM.
Se retiró esa composición y se personalizan únicamente campos dinámicos existentes.

Se revisaron en modo lectura la plantilla general y los 22 DOCX de oficios personalizados
disponibles localmente: ninguno contiene las frases técnicas señaladas en su XML principal.
Hay documentos históricos de otras modalidades sin ese párrafo literal; no se modifican
ni se presupone que deban convertirse al modelo general. No se consultó la BD ni Hostinger.

La prueba compara el XML completo de todos los párrafos institucionales con el original,
incluidos los formatos y el cierre; comprueba estilos, fuentes, imágenes, relaciones,
tamaño, márgenes, 33 párrafos y ausencia de tablas añadidas. También valida sustituciones
autorizadas, descarga inmutable de `generacion_*`, conservación de otra modalidad y
ausencia de textos técnicos en las muestras. Estas comprobaciones son de QA, no filtros
del CRM. El servicio sigue respetando el contenido de la plantilla seleccionada.

La comparación de PDF exige que todo el cuerpo institucional coincida con el general,
incluido el texto de validez oficial, y que las personalizaciones y la firma preparada
correspondan a cada DOCX. No se aplica una sustitución global del cuerpo ni se oculta texto.

La extracción de texto coincide ignorando espacios en las tres muestras y conserva las
familias tipográficas. La revisión visual confirma los elementos institucionales y la
distribución. **No son idénticos píxel a píxel**: existen pequeñas diferencias de posición,
métrica tipográfica y rasterizado. En puntos de control (asunto, tabla, cuerpo y firma)
se observaron diferencias pequeñas de posición. Las métricas de píxel no se
presentan como garantía automática de fidelidad; se revisaron las páginas renderizadas.

Pruebas dirigidas, sin CI global:

```text
php -d memory_limit=128M -d disable_functions=proc_open,exec,shell_exec,system,passthru,popen tests/oficios_redmex_renderer_test.php
python tests/oficios_carga_seleccion_test.py
python tests/publicacion_oficios_redmex_test.py
python tools/oficios_redmex_comparar.py --pdftoppm RUTA_PDFTOPPM
```

Para esta corrección de contenido se ejecutan únicamente la preparación y las tres
muestras, sin repetir la batería de conversión, carga HTTP ni publicación:

```text
php -d memory_limit=128M -d disable_functions=proc_open,exec,shell_exec,system,passthru,popen tests/oficios_redmex_renderer_test.php --solo-preparacion
php -d memory_limit=128M -d disable_functions=proc_open,exec,shell_exec,system,passthru,popen tests/oficios_redmex_renderer_test.php --solo-muestras
python tools/oficios_redmex_comparar.py --pdftoppm RUTA_PDFTOPPM
```

Las referencias Word de las muestras corregidas se exportan de nuevo desde los mismos
DOCX ficticios. No cambian controlador, servicio de preparación del CRM, conversor,
plantillas almacenadas ni archivos publicados por el script selectivo. Por tanto esta
corrección de muestras no requiere un despliegue adicional; el publicador existente
permanece intacto. Los PDF históricos tampoco se sobrescriben.

El ensayo HTTP prueba subida multipart real, biblioteca, copia seleccionada, separación
original/preparado, selección inexistente, rechazo de PHP/HTML/vacío/>20 MB y guardas de
permisos, con un registro falso aislado. La interfaz se comprobó en una página local con
el JavaScript y CSS reales y respuestas de prueba, sin operar el CRM ni emitir folios reales.
`selector-prueba.png` muestra la biblioteca y el campo de carga accesibles en detalle.

Dompdf probado: **3.1.6**. PHP local: 8.2.12, con procesos deshabilitados y 128 MB durante
la prueba; PHP de Hostinger informado: 8.2.34. No se han realizado ensayos remotos.
La conversión no tiene tarifa por documento ni envía contenido a terceros.

## Publicación selectiva y reversible: preparada, NO ejecutada

`tools/publicar_oficios_redmex_hostinger.sh` es autocontenido y sólo publica seis archivos:

1. `app/services/OficioRedmexPdfRenderer.php` (nuevo).
2. `app/services/OficioPreviewService.php` (parche).
3. `app/views/layout/dashboard_head.php` (parche de estilos de vista previa en detalle).
4. `app/views/layout/dashboard_footer.php` (parche de scripts de oficios en detalle).
5. `app/controllers/OficioVinculacionController.php` (parche).
6. `app/services/OficioDocxPdfService.php` (parche).

Aplica parches a los cinco archivos existentes para conservar otras personalizaciones,
incluso las de Telefonía en layouts compartidos. Verifica hashes de la entrega, plantilla
aprobada, dependencias, versión Dompdf y sintaxis PHP. Rechaza conflictos y enlaces;
preserva LF/CRLF y permisos. El archivo nuevo no sobrescribe otra implementación distinta.
No publica muestras, pruebas, vendor, plantillas, configuración privada ni archivos SQL.

Guardar el script descargado fuera de `public_html` y ejecutar manualmente:

```bash
bash publicar_oficios_redmex_hostinger.sh --check
# Sólo después de revisar el OK y la comparación visual:
bash publicar_oficios_redmex_hostinger.sh --apply
# Restaurar usando EXACTAMENTE la ruta que imprimió --apply:
bash publicar_oficios_redmex_hostinger.sh --restore /ruta/exacta/del/respaldo
```

Raíz predeterminada: `$HOME/domains/fundacionrededumex.org/public_html`.
Puede ajustarse con `IMPE_OFICIOS_PDF_PUBLIC`. Respaldos fuera del sitio en
`$HOME/respaldos-crm`, ajustable con `IMPE_OFICIOS_PDF_BACKUPS`.
`--check` no publica ni crea respaldo. `--apply` respalda antes de escribir y revierte
los archivos alcanzados ante una interrupción. `--restore` rehúsa sobrescribir archivos
editados después de la publicación. Aplicación/restauración repetidas son idempotentes.

Pendiente en Hostinger: ejecutar `--check`, confirmar carga de fuentes e imágenes y límites
PHP web, comparar las tres muestras y validar con sesiones reales la selección, vista previa,
generación/descargas, fallos sin transición a GENERADO y conservación de PDF existentes.
No se activa ninguna API, cuenta, costo ni despliegue automático.

La desofuscación de fuentes embebidas sigue el formato WordprocessingML descrito por
[Microsoft, Font Part](https://learn.microsoft.com/en-us/openspecs/office_standards/ms-oe376/1663dabc-5d98-463f-889e-bcd9b77c3d34).
