# Conversión de las plantillas personalizadas de oficios

La plantilla adjunta «OFICIO ll EMBAJADAS REDMEX2026 (1) (1) OFICIAL 4 (3)»
coincide exactamente con `oficio_20260911_115147_e2e9fb4003a1.docx` del catálogo.
SHA256: `f227897c24b7fa08145a6f38b529e208c5c383011647af2a7f9999f0751bc45f`.
No se añade otra copia del documento ni se publica su conversión en Git.

## Causa y alcance

El renderer publicado en `efe01ebd` rechazaba los encabezados/pies separados,
recortes y giro del sello de Embajadas. Además exigía que imágenes y fuentes
de las personalizadas tuvieran los mismos bytes que la plantilla general.
Se reprodujeron esos rechazos con los DOCX del catálogo. La búsqueda de la
referencia de la captura en los logs de Hostinger no encontró resultados;
por tanto no se atribuye a ese log un código de error que no se obtuvo.

Se modifica exclusivamente `app/services/OficioRedmexPdfRenderer.php`:

- Lee las referencias a encabezado/pie y los márgenes del DOCX seleccionado.
- Conserva sus imágenes, recortes, rotación, resaltados y sombreado.
- Utiliza la imagen alternativa guardada en el propio DOCX para el cuadro
  del pie; no duplica ambas representaciones ni inventa su contenido.
- Dibuja las imágenes del cuerpo junto al párrafo de anclaje.
- Comprueba el desbordamiento con el ancho visible del texto, excluyendo
  espacios finales de la justificación, y admite las sangrías dentro de página.
- Conserva las fuentes adjuntas y valida sus permisos de incrustación/glyphs.
  La cursiva Times New Roman no está adjunta en Embajadas: utiliza la fuente
  estándar PDF Times cursiva. Puede haber pequeñas diferencias de métricas
  respecto a Word; no se declara identidad de rasterizado con Word.

La plantilla general conserva su perfil y sus recursos. No se cambia la
preparación del DOCX, la selección, los endpoints, permisos, folios, estados,
base de datos, pantallas, descarga, envío ni los conversores de Convenios.
Los originales y las instantáneas preparadas no se reescriben.
No requiere Word, LibreOffice, procesos PHP ni servicios externos.

No es un conversor Word universal. Continúan rechazándose las estructuras
que no puede representar, como varias secciones, columnas múltiples, objetos
o recursos externos. Los borradores históricos ajenos al catálogo pueden
contener otras estructuras no soportadas y no se migran en esta entrega.
La comprobación cubre las siete plantillas del catálogo disponibles, no
plantillas futuras o archivos distintos que sólo compartan el mismo nombre.

## Validación local

PHP 8.2.12, Dompdf 3.1.6 y dependencias existentes. Se deshabilitaron
`proc_open,exec,shell_exec,system,passthru,popen` en las conversiones PHP.
No se consultó la BD real ni se enviaron documentos o correos.

- `tests/oficios_personalizados_pdf_test.py`: siete plantillas del catálogo
  y una instantánea preparada de Embajadas. PDF carta, texto completo
  comparado con el XML y hashes originales intactos. El DOCX adjunto da una
  página y seis imágenes. Se inspeccionó su página renderizada y se comparó
  visualmente con Word local, incluido sello, firma y pie.
- `tests/oficios_redmex_renderer_test.php`: preparación general/personalizada,
  instantáneas, documentos incompatibles, paginación y limpieza temporal.
  El rasterizado de la plantilla general es idéntico al de la versión anterior.
- `tests/oficios_pdf_http_test.py`: endpoints PHP reales en réplica local,
  sesión real y BD simulada. Vista previa, generación y descarga de Embajadas;
  mismo folio, DOCX exacto, permisos, idempotencia y fallos sin transición de estado.
- `tests/oficios_personalizados_publicador_test.py`: LF/CRLF, check sin instalar,
  apply, probe sin BD, restauración, idempotencia y rechazo de ediciones ajenas.

La validación web en Hostinger queda pendiente de instalar y abrir la vista
previa en la sesión autorizada. Una prueba CLI no sustituye esa comprobación.

## Publicación selectiva

`tools/publicar_oficios_personalizados_hostinger.sh` sólo actualiza el renderer
si coincide con una de las dos versiones publicadas conocidas. Un renderer
personalizado se rechaza; no se fuerza la sustitución de otros archivos.
Comprueba que el servicio instalado ya llama a `OficioRedmexPdfRenderer`.

SHA256 del publicador:
`a2b7849ef1887484aeacc4d06a215926bfe18f7170b2c4d1f8375989c596046a`.

Tras descargar el publicador de la revisión indicada en la entrega:

```bash
bash "$HOME/impe-publicadores/oficios-personalizados.sh" --check
bash "$HOME/impe-publicadores/oficios-personalizados.sh" --apply
bash "$HOME/impe-publicadores/oficios-personalizados.sh" --probe
```

`--apply` guarda respaldo privado y muestra el comando de restauración.
`--probe` busca por hash la copia exacta de Embajadas ya guardada, la convierte
en un temporal fuera del sitio y elimina el resultado; no genera un oficio
del CRM ni altera su estado. Si no encuentra esa copia exacta, lo informa.

Reabrir la vista previa desde el CRM y comprobar generación y descarga.
No hay que volver a subir ni editar las plantillas.
