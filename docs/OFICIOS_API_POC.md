# Oficios REDMEX: ensayo de conversión mediante un proveedor HTTPS

20/10/2026. Rama conservada: `fix/ventas-etapa2-audio-operacion`.
Se consultó el diagnóstico `9eb43322` y no se sustituyeron sus conclusiones.

**Resultado:** CloudConvert pasó una prueba visual con la copia ficticia de la
plantilla general, utilizando su convertidor web sin registro ni pagos. La
prueba autenticada mediante API y la autorización del proveedor siguen pendientes.
No se integró ni desplegó un conversor, y los oficios del CRM siguen sin restaurar.

## Evaluación de proveedores

| Aspecto | CloudConvert, primera opción | Microsoft Graph, alternativa |
| --- | --- | --- |
| Interfaz | API v2 por HTTPS, trabajos import/upload → convert → export/url. | Archivo almacenado en OneDrive/SharePoint; `content?format=pdf`. |
| Motor | El catálogo público consultado devolvió `office` versión `2.1`, base de dos créditos. También aparecen otros motores; no se propone cambiar a ellos. | Conversión del servicio Microsoft; sin prueba autenticada en esta tarea. |
| Cuenta/credencial | Requiere cuenta y API key Bearer con `task.read` y `task.write`; `user.read` sólo si se autoriza consultar saldo. | Requiere cuenta/almacenamiento, registro de aplicación y token OAuth con permisos de archivos. Para carga delegada, la documentación indica `Files.ReadWrite`; el modo aplicación exige permisos más amplios y consentimiento. |
| Restricción relevante | Sandbox limitado a archivos de una lista autorizada; no sirve para acreditar nuestra plantilla propia. Para probarla por API se necesita la API real. | La documentación de conversión consultada indica máximo de 2 MB. La copia de prueba tiene 3.953.717 bytes, por encima de ese límite. |
| Fidelidad observada | Ensayo web satisfactorio en una página carta. Equivalencia de la API autenticada todavía pendiente. | No evaluada visualmente; no se subió ningún archivo a Microsoft. |
| Decisión | Candidato para el siguiente ensayo, sujeto a autorización y cuenta. | No adecuado para esta copia según el límite documentado; no se quitaron fuentes ni imágenes para hacerlo caber. |

El límite de **carga** de 250 MB en Graph no debe confundirse con el límite
documentado de **conversión**. Tampoco se supone que un resultado web identifica
por sí mismo la versión interna del motor: la página no la mostró. La solicitud
de ensayo API preparada pide explícitamente `office` / `2.1` y deberá compararse
con la misma referencia.

Fuentes oficiales consultadas:
[CloudConvert Office to PDF](https://cloudconvert.com/apis/office-to-pdf),
[autenticación, motores y sandbox](https://cloudconvert.com/docs/getting-started/introduction),
[catálogo de operaciones](https://cloudconvert.com/docs/api-reference/operations),
[conversión Graph](https://learn.microsoft.com/en-us/graph/api/driveitem-get-content-format?view=graph-rest-1.0),
[carga y permisos Graph](https://learn.microsoft.com/en-us/graph/api/driveitem-put-content?view=graph-rest-1.0).

## Cuenta y costos

La usuaria confirmó que no dispone de cuenta en ninguno de los dos proveedores.
No se registró ninguna cuenta, no se aceptó una suscripción ni se guardaron claves.

La tabla oficial de CloudConvert muestra 10 créditos diarios gratuitos y un costo
base Office→PDF de dos créditos, con incrementos por duración. Esto permite estimar
**hasta cinco conversiones cortas al día**, no diez oficios garantizados. Es una
estimación a partir de la tabla, sujeta al saldo y condiciones de la cuenta.
Los paquetes y suscripciones se cotizan según volumen; no se cotizó ni contrató
un plan para producción. Una vista previa y una generación separadas podrían
consumir dos conversiones si no reutilizan el mismo resultado validado.
[Precios oficiales](https://cloudconvert.com/pricing).

Graph depende de la cuenta, licencia y almacenamiento. Las API estándar se
incluyen en la licencia dentro de sus límites, pero no se ha acreditado un costo
cero para un entorno Microsoft que aquí no existe ni se ha contratado.
[Modelo de costos Graph](https://learn.microsoft.com/en-us/graph/metered-api-overview).

## Ensayo realizado

1. Se conservó `storage/templates/oficio_general_redmex.docx`, SHA-256
   `9c4750cc6f72bb2b182daaefca4d5a9cec67b13f3c4e960c031100a9e56341ad`.
2. `tools/oficios_api_poc_preparar.php` llama al servicio existente de preparación
   DOCX/PHPWord/TemplateProcessor, sin acceso a la BD. Genera un destinatario,
   institución, contacto y folio expresamente ficticios (`PRUEBA/0000/20-10/26`).
3. Sólo en la copia de ensayo se anonimizan autores, comentarios y metadatos.
   Se conservan los seis comentarios y todas las partes del paquete: no se
   eliminan para ocultar un defecto del conversor. Las imágenes y las nueve
   fuentes incrustadas se conservan byte por byte. No hay relaciones externas.
4. Se revisó la copia y se preparó una referencia Word local de esa misma copia.
   Se contrastó con la referencia Word anterior para comprobar que se mantiene
   la distribución institucional. Word sólo se usó como referencia de ensayo;
   la futura operación del CRM no dependerá de esta computadora.
5. Se seleccionó únicamente la copia ficticia en la web oficial de CloudConvert,
   sin iniciar sesión ni crear cuenta. La conversión terminó como `FINISHED` y
   se descargó el PDF. No se enviaron oficios reales, registros del CRM ni el
   folio real del incidente. La copia contiene los logos, sello y pie corporativos
   necesarios para comprobar su representación.
6. Se comprobaron estructura y texto con pypdf, dimensiones con pdfinfo,
   imágenes de página con pdftoppm y comparación visual a 1005 × 1300 píxeles.
   Se usaron herramientas locales ya disponibles; no se instalaron dependencias.

| Comprobación | Referencia Word | CloudConvert web |
| --- | --- | --- |
| Páginas | 1 | 1 |
| Tamaño | Carta, 612 × 792 puntos | Carta, 612 × 792 puntos |
| PDF | Firma y estructura válidas | Firma y estructura válidas |
| Familias tipográficas declaradas | Arial, Cambria, Times New Roman y sus variantes presentes | Mismo conjunto |
| Logotipos/encabezado | Referencia | Coinciden visualmente; diferencia de píxeles cero en la región comparada |
| Pie, márgenes, alineación y distribución | Referencia | Sin desplazamientos o pérdidas visibles en este ensayo |
| Sello/contacto ficticio/folio | Referencia | Conservados |
| Texto extraído | Referencia | Mismos caracteres al ignorar espacios; hay diferencias de segmentación de espacios |

Sólo el 0,03490 % de los píxeles de la página supera una diferencia de 16/255
en algún canal; el error absoluto medio es 0,03294/255. Estas métricas respaldan
la inspección visual, no prueban fidelidad universal ni reemplazan la revisión.
Poppler informó en el candidato avisos de fuentes de presentación `Symbol` y
`ArialUnicode`; no se observó pérdida visible, pero deben mantenerse como punto
de revisión en otras plantillas o visores. No se ocultan esos avisos.

SHA-256 para verificar los artefactos locales:

```text
DOCX ficticio: ae6244a5205737cd4b3ad85fa3d7f0df9ae0f2234e64be65916ab7df8ac814f9
Word PDF:     3d5a9b45d753525c645a5c0546b1763f54e477b3255c3cffef1a2c697c310acb
Web PDF:      45fb6ab0ad9d24e057968465ea9e53a1b3548a527d81cc7246d4c07da9e45915
```

Los artefactos de esta ejecución se conservan localmente bajo
`tmp/poc-redmex-bcec95784f74/`: DOCX, manifiesto, solicitud API sin credencial,
referencia Word, PDF web, captura de finalización y `comparacion/` con informe
JSON e imagen. Quedan fuera de Git y de cualquier despliegue. La preparación de
un nuevo ensayo usa otra carpeta exclusiva y otros hashes de paquete.

## Privacidad y temporales

CloudConvert procesa el archivo completo, incluyendo posibles metadatos,
comentarios y elementos embebidos. Una futura operación con oficios reales
requerirá autorización expresa para ese proveedor y datos, elección de región
y revisión de condiciones de tratamiento. El ensayo ficticio no constituye esa
autorización. No se publican enlaces firmados de descarga en el repositorio.

La documentación de trabajos y privacidad establece borrado automático de datos
hasta 24 horas después de finalizar y permite eliminar el trabajo con sus datos.
La página de seguridad describe borrado al terminar el procesamiento: para el
diseño se adopta el límite documentado más conservador, sin prometer eliminación
instantánea. La copia ficticia de la prueba web queda sujeta a esa retención;
no se ha verificado su borrado remoto. La futura API deberá solicitar la limpieza
de su propio trabajo y registrar sólo el resultado de limpieza, sin documentos,
claves ni enlaces firmados en logs.
[Trabajos y borrado](https://cloudconvert.com/docs/api-reference/jobs),
[privacidad](https://cloudconvert.com/privacy),
[seguridad/regiones](https://cloudconvert.com/security).

## Integración mínima prevista, todavía no implementada

| Archivo | Cambio sujeto a autorización y ensayo API satisfactorio |
| --- | --- |
| Nuevo `app/services/OficioCloudConvertAdapter.php` | HTTPS autenticado; carga privada del DOCX preparado; seguimiento de trabajo; descarga y validación; limpieza. |
| `app/services/OficioDocxPdfService.php` | En el camino exclusivo de `generarPdf()` de oficios, reemplazar sólo la etapa de conversión y su exigencia de `proc_open`; conservar el llenado DOCX. No cambiar el conversor compartido de `convertirArchivoAPdf()`, usado por Convenios. |
| `app/services/OficioPdfService.php` | Aceptar únicamente un resultado validado y asociado a la solicitud antes de guardar y marcar `GENERADO`; preservar reutilización, folios, estados y autorización existentes. |
| Configuración privada fuera de Git y del sitio | Clave API, proveedor/versiones autorizadas y límites. La documentación sólo contendría nombres de opciones, nunca secretos. |

Los dos endpoints PDF, modal, botones, plantillas, modelo de folios, correos y
ruta de 13 pasos no necesitan rediseño. Antes de modificar cualquier componente
compartido se verificará que sólo el camino de oficios use el adaptador.

El contrato del adaptador deberá comprobar un identificador aleatorio por
solicitud y SHA-256 del DOCX, correlación de `tag`, job/task IDs y un único archivo
esperado. Deberá limitar bytes y tiempos acumulados, validar TLS, hosts HTTPS de
descarga, MIME y firma/final PDF, rechazar respuestas vacías/HTML y respuestas de
otro trabajo, y limpiar temporales en éxito y error. Reintentos o timeouts no
podrán duplicar folios, sobrescribir otro oficio ni cambiar a `GENERADO`.
Una caché privada vinculada al hash del DOCX/proveedor/versión deberá evitar
cobrar otra vez la misma vista previa y generación, sin servir contenido obsoleto.

Estos son requisitos de la integración futura, **no garantías de código ya
integrado**. El script de comparación valida el ensayo local; no es un adaptador
de producción ni introduce dependencias Python en Hostinger.

## Pruebas dirigidas y pendientes

Pasaron: sintaxis PHP; preparación con funciones de procesos/red deshabilitadas;
plantilla original, recursos, tamaños y comentarios conservados; metadatos
ficticios; rechazo de PDF sin firma, con otra paginación/tamaño o con acciones
automáticas; comparación local y revisión visual del resultado web. No se usó
la CI global. El preparador y comparador no hacen peticiones de red.

Ejecutar `php tools/oficios_api_poc_preparar.php` prepara sólo el ensayo local y
su JSON de trabajo, **no** envía el archivo. El comparador y las pruebas Python
se ejecutan con el runtime local que ya incluye pypdf, Pillow y NumPy, sin agregar
paquetes al proyecto:

```text
python tests/oficios_api_poc_test.py
python tools/oficios_api_poc_comparar.py REFERENCIA.pdf CANDIDATO.pdf --pdftoppm RUTA_PDFTOPPM --salida CARPETA_DE_COMPARACION
```

Pendientes antes de integración:

1. Autorizar CloudConvert y disponer de una cuenta/clave privada, sin contratar
   ni activar recargas de pago por defecto. La usuaria no tiene cuenta aún.
2. Ejecutar el JSON preparado mediante API autenticada con `office` / `2.1` y
   repetir la comparación sobre el mismo DOCX ficticio. No sustituirla por el
   sandbox de archivos ajenos ni afirmar que esta llamada ya se realizó.
3. Probar en Hostinger cURL/OpenSSL/CA, salida HTTPS y límites de carga/tiempo
   del PHP web, sin `proc_open` ni cambios de configuración general.
4. Probar las plantillas personalizadas seleccionadas con copias ficticias;
   el resultado de la plantilla general no certifica todas las demás.
5. Con autorización e implementación revisable, probar vista previa, generación,
   descarga, conservación del folio, fallos de proveedor sin cambios de estado,
   concurrencia, reintentos, limpieza y ausencia de efectos en Convenios.

La autorización para un ensayo ficticio y para abrir una cuenta no autoriza
automáticamente el envío de oficios reales. No se ha modificado producción.
