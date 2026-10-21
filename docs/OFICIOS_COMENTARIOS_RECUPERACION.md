# Recuperación limitada de comentarios DOCX en oficios

20/10/2026. Rama conservada: `fix/ventas-etapa2-audio-operacion`.

Esta entrega atiende la instrucción de mantener PHPWord + Dompdf y recuperar
`Comment with id 0 isn't referenced in document`. No integra un proveedor API.

## Qué corrige

El lector instalado de PHPWord lanza `InvalidArgumentException` cuando un
comentario no tiene referencia registrada. La plantilla general contiene seis
comentarios sin una referencia utilizable y un marcador de rango incompleto.
Además, el servicio impedía usar el renderer PHP al exigir `proc_open` antes
de seleccionar un conversor.

- Sólo el camino de `generarPdf()` de oficios permite ahora usar PHPWord/Dompdf
  sin procesos externos. Si `proc_open` está bloqueado no intenta ejecutar Word
  ni LibreOffice. No habilita funciones ni cambia configuración de Hostinger.
- El primer intento de lectura es normal. Sólo ante la excepción exacta de
  comentario no referenciado se analiza el paquete XML y se confirma la
  inconsistencia del ID informado. Otros errores no activan esta recuperación.
- El saneador crea otra copia temporal exclusiva; nunca abre para escritura
  el DOCX de origen. Resuelve destinos por tipos de relaciones OOXML y namespaces,
  incluidos destinos relativos, sin extraer archivos ZIP ni aceptar rutas externas.
- Omite únicamente definiciones y marcadores de comentarios inconsistentes.
  Conserva los comentarios válidos, incluida una referencia puntual sin rango.
  Pruna los índices de anotaciones relacionados por `paraId`/`durableId`; mantiene
  las partes y sus relaciones, incluso cuando su colección queda vacía.
- Nunca borra párrafos o runs padres, texto de oficio, tablas, imágenes,
  encabezados, pies, estilos o fuentes. Los comentarios editoriales inválidos
  omitidos no forman parte del PDF; permanecen intactos en el original.
- Reintenta la lectura **una sola vez**. La copia saneada permanece hasta terminar
  el render para que se puedan leer sus imágenes; luego se elimina en `finally`.
  Un segundo error retorna fallo, sin más reintentos.
- Se comprueba firma PDF, final `%%EOF`, `startxref` y presencia de página. Una
  salida vacía o HTML se rechaza y no se devuelve como documento válido.
- Los errores nuevos registran fase, clase, códigos internos y contadores;
  no registran el contenido DOCX, nombres, credenciales ni mensajes arbitrarios.

Referencia normativa utilizada para distinguir comentarios puntuales de rangos
incompletos: [CommentReference — Microsoft Open XML](https://learn.microsoft.com/en-us/dotnet/api/documentformat.openxml.wordprocessing.commentreference?view=openxml-3.0.1).

## Aislamiento y archivos de aplicación

1. `app/services/OficioDocxPdfService.php`: selección del renderer PHP para
   oficios, recuperación acotada, validación y diagnóstico coherente.
2. `app/services/OficioDocxComentarios.php`: saneador de copias temporales.

El método público `convertirArchivoAPdf()`, usado por Convenios, conserva su
exigencia y comportamiento anteriores. Los parámetros privados nuevos tienen
valor predeterminado `false`; la recuperación sólo se activa desde oficios.
Se probaron también esos consumidores privados sin recuperación.

No se modifican permisos, endpoints, botones, folios, tablas, estados, plantillas,
correos, Telefonía ni otros módulos. El servicio de persistencia conserva su
retorno de error antes del UPDATE a `GENERADO`; no se accedió a BD de producción.
La vista previa y generación existentes reciben el resultado por la misma interfaz.

## Pruebas dirigidas

```text
php -d disable_functions=proc_open,exec,shell_exec,system,passthru,popen tests/oficio_comentarios_recuperacion.php
php -d disable_functions=proc_open,exec,shell_exec,system,passthru,popen tests/oficio_comentarios_recuperacion.php --mock
python tests/publicacion_oficios_comentarios_test.py
```

Se verificaron:

- Flujo general con marcadores, catálogo personalizado y documento personalizado
  ya preparado (`generacion_…docx`), sin asignar folios nuevos. Las pruebas usan
  carpetas aisladas y datos ficticios; no escriben en las plantillas reales.
- Conversión directa de la plantilla general real: el error se recupera, se genera
  un PDF válido y el SHA-256 del original permanece igual. Se elimina la copia.
- Conversión local de dos DOCX personalizados existentes, de catálogo y procesado:
  PDF válido en ambos, originales intactos. No certifica todas las plantillas.
- Comentario válido conservado; destino de comentarios no convencional;
  prefijo XML alternativo; texto, tabla, imágenes, estilos, encabezado y pie sin
  alteración de sus partes ajenas a comentarios; rechazo de traversal, DTD y XML
  incompleto. No se confunde un comentario puntual válido con un rango incompleto.
- Un solo reintento ante un segundo error de comentarios; ningún reintento ante
  errores ajenos; rechazo de HTML/PDF falso; limpieza y mensajes sin datos privados.
- Publicador sobre réplicas: `--check` sin cambios, `--apply`, `--restore`, LF/CRLF,
  personalización compatible, operación idempotente, conflictos y rechazo de
  ediciones posteriores. Se simula una interrupción para comprobar rollback.

También se verificó sintaxis PHP/Bash. No se ejecutó la CI global. Las pruebas
locales utilizan PHP 8.2.12 con funciones de procesos deshabilitadas; el PHP
8.2.34 web de Hostinger debe validarse después de una aplicación manual.

## Contenido y fidelidad: limitación confirmada

La recuperación conserva el contenido XML del oficio y los recursos del paquete;
sólo omite las anotaciones editoriales inválidas en la copia. En la comparación
con el mismo DOCX ficticio usado anteriormente, el PDF recuperado conserva el
texto principal, datos y folio, pero **no conserva toda la presentación ni todos
los elementos gráficos de Word**:

- Dos páginas A4 en lugar de una página carta.
- Encabezado/logotipos y sello no reproducidos como en Word.
- Pie desplazado y recortado; aparecen cifras adicionales del renderizador.
- Cambios de márgenes, espaciados y distribución.

Son limitaciones observadas localmente de PHPWord/Dompdf, no arreglos pendientes
del saneador. El writer DomPDF instalado fija A4; no se editó `vendor/` ni la
plantilla para ocultar estos defectos. No se afirma fidelidad institucional ni
restauración completa del formato REDMEX. En Hostinger quedan pendientes la
revisión visual de la plantilla realmente seleccionada, vista previa, generación,
descarga y confirmación de estados/folios con una cuenta autorizada. No se ha
desplegado esta entrega automáticamente.

## Publicación selectiva y reversible

El único publicador es `tools/publicar_oficios_comentarios_hostinger.sh`.
Contiene el parche y el auxiliar revisados; no descarga más código ni usa SQL.
Aplica sólo los dos archivos de aplicación indicados. Conserva personalizaciones
compatibles del servicio y rechaza conflictos o un auxiliar existente diferente.
El respaldo se guarda fuera del sitio; no se reemplazan plantillas ni configuración.

Antes de aplicar, considerar la limitación visual descrita arriba. En SSH, copiar:

```bash
umask 077
export IMPE_OFICIOS_ENTREGA="$(mktemp -d "$HOME/impe-oficios-entrega.XXXXXX")"
curl --fail --location --proto '=https' --tlsv1.2 \
  'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/fix/ventas-etapa2-audio-operacion/tools/publicar_oficios_comentarios_hostinger.sh' \
  --output "$IMPE_OFICIOS_ENTREGA/publicar_oficios_comentarios_hostinger.sh"
printf '%s  %s\n' \
  'd0b4d00c9e6ec9a0167b57187179e9096ff95ada9f9ea670880cae410015a05b' \
  "$IMPE_OFICIOS_ENTREGA/publicar_oficios_comentarios_hostinger.sh" | sha256sum --check
```

Si falla la descarga o la huella no muestra `OK`, detenerse. Revisar sin aplicar:

```bash
bash "$IMPE_OFICIOS_ENTREGA/publicar_oficios_comentarios_hostinger.sh" --check
```

La raíz predeterminada es `$HOME/domains/fundacionrededumex.org/public_html`.
Si difiere, indicar explícitamente la raíz correcta mediante
`IMPE_OFICIOS_PDF_PUBLIC`; el script exige encontrar el servicio y `index.php`.
No usar el publicador de Telefonía para esta entrega.

Sólo tras revisar un `--check` compatible y decidir aplicar esta corrección:

```bash
bash "$IMPE_OFICIOS_ENTREGA/publicar_oficios_comentarios_hostinger.sh" --apply
```

`--apply` imprime la ruta exacta del respaldo y una orden `Rollback: bash … --restore …`.
Guardar y usar **esa orden exacta** para restaurar. No inventar una ruta de respaldo.
La restauración recupera el servicio anterior y retira el auxiliar si antes no
existía; se niega a sobrescribir archivos editados después de la publicación.

El `--check` valida sintaxis y dependencias de PHP CLI, no representa una
conversión por PHP web ni garantiza el formato en producción. No se genera un
oficio real durante la preparación o publicación del código.
