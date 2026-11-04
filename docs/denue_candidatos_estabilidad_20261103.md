# DENUE: certificado y recuperación de candidatos

## Causa confirmada

La prueba desde Hostinger devolvió `http=0`, `curl_errno=60`, sin JSON, en 0.18 segundos. El fallo se produce antes de consultar los candidatos; no indica que el municipio esté vacío ni que haya un filtro incorrecto.

Se reprodujo con PHP/cURL local: `SSL certificate problem: unable to get local issuer certificate`. El certificado de `www.inegi.org.mx` es de Sectigo OV R36 y entró en vigencia el 2 de noviembre de 2026. La conexión autenticada mediante el almacén de Windows obtuvo un único certificado entregado por el sitio; falta el intermedio. Al proporcionar la cadena completa de CA, PHP verifica correctamente el host y recibe HTTP 404 en la raíz de la API, en lugar de error TLS. Esa prueba no usa token y demuestra conexión HTTPS, no una importación ni una consulta autenticada completa de candidatos.

La corrección antigua de DENUE sí está instalada: `DenueService.php` coincide con SHA-256 `0cbde0224b5fe53171024505ac49c80fd8f9bcaad3070a5d11b6f548b6bc66e7`. Era para lotes de cuantificación/reintentos, no completaba esta cadena TLS. La búsqueda automática además tenía otro transporte: 18 peticiones sin reintento con hasta cinco tandas de 16 segundos. El controlador convertía esos fallos en el mismo mensaje genérico.

## Cambio

La prueba de final de paginación desde Hostinger confirmó HTTP 200, `text/plain; charset=utf-8` y el cuerpo JSON de 20 bytes `"No hay resultados."`. El cliente de la primera entrega esperaba un arreglo y marcaba esa respuesta válida como `FORMATO`, provocando avisos parciales ante sectores vacíos. Se corrige ese fallo del cliente y se prueba el formato exacto. HTML, cuerpos vacíos, `null`, objetos vacíos y cadenas desconocidas siguen siendo errores; no se convierten en un final válido.

- Almacén público de CA de Mozilla más intermedio oficial de Sectigo, verificado contra las raíces. Sólo se utiliza para el HTTPS oficial de INEGI desde DENUE. No se cambia el reloj, la configuración global de PHP/cURL, la PBX ni otras APIs.
- Certificado y nombre del host siempre se verifican. No hay `--insecure`, verificación desactivada ni confianza directa en el certificado hoja. Renovar el certificado hoja bajo la misma cadena no requiere renovar este paquete.
- Transporte con reintentos acotados ante fallos temporales, respeto de `Retry-After` y presupuesto de espera por petición. El modal consulta un rango de 100 registros de la API por cada petición, con continuación automática si necesita más coincidencias para completar sus diez tarjetas visibles.
- Un rechazo de autorización dentro de HTTP 200 se distingue de resultados válidos o un vacío real. Los fallos permanentes de certificado/autorización no se reintentan ni se ocultan con caché vieja.
- La búsqueda del modal recorre rangos reales de DENUE sin el corte anterior de 250/300 candidatos ni los pequeños rangos por perfil. Consulta todos los sectores y tamaños para los filtros seleccionados, conservando el filtro de tipo y las etiquetas de oportunidad. No obliga a descargar un estado entero antes de abrir la primera página.
- Caché reanudable por usuario/sesión, estado, municipio, tipo y término. Cada rango se confirma íntegramente antes de avanzar. Un fallo se reintenta desde ese rango; no se acepta una lista parcial como catálogo terminado ni se oculta con datos de otra consulta.
- Las páginas anteriores se leen desde caché. «Siguiente» permite continuar con el resto de DENUE sin tope artificial de candidatos. El total se presenta como exacto únicamente cuando se agota la API; antes se informa que hay más registros por consultar.
- El controlador conserva autorizaciones territoriales, verifica municipios y enriquece sólo la página visible con el estado real de los seguimientos. Se mantiene la validación al iniciar un seguimiento. Secretarías sigue utilizando su catálogo específico.

## Compatibilidad

El manifiesto acepta las variantes comprobadas del controlador, incluida la instalada `cd319b9bdcf1d75a27ff9b3244e7478303b9e51d5eceebe776cff31b24ad414f`. En esa variante sólo cambia el método `buscarCandidatos`; conserva íntegros la cartera territorial y los demás métodos. No se instala la versión antigua completa del controlador.

El manifiesto contiene ocho archivos de ejecución. La actualización cambia seis archivos: cliente HTTP, servicio DENUE, controlador, vista, JavaScript de paginación y servicio de consulta reanudable nuevo. El certificado y la política TLS ya instalados se comprueban sin sustituirse. Acepta la versión inicial y la instalada por la primera corrección DENUE, preservando la variante de cartera territorial. Las variantes desconocidas detienen todo antes de publicar. `--check` sólo verifica compatibilidad y sintaxis. `--apply` guarda respaldo privado, instala de forma selectiva y comprueba integridad. `--restore` restaura únicamente si los archivos siguen siendo los instalados por esta entrega.

No ejecuta SQL, no consulta la DB, no importa candidatos reales y no modifica datos de Seguimiento, prospectos, asignaciones, Aliados, horarios ni telefonía. La caché de DENUE se renueva durante las búsquedas normales.

## Verificación

- `tests/denue_http_local.py`: cURL real contra servidor local ficticio; 503 recuperado, JSON inválido, 403 y rechazo de token con HTTP 200, vacío válido, 429/Retry-After, 18 timeouts con presupuesto global y opciones TLS.
- `tests/denue_candidatos_resiliencia.php`: deduplicación, límites geográficos/términos, consulta por nombre, caché fresca/corrupta/vencida, respuesta parcial, preservación de copia completa y rechazo de autorización.
- `tests/denue_consulta_paginada.php`: 1,005 registros, páginas posteriores al 300, filtros cuyas primeras coincidencias están después del 1,000, reanudación del rango fallido, deduplicación y apéndices no confirmados.
- `tests/denue_paginacion_browser.cjs`: vista PHP real, 32 páginas accesibles, continuación automática, recuperación de HTTP 503, cambio de municipio y modal móvil.
- `tests/publicacion_denue_estabilidad.py`: cuatro variantes exactas (incluida la ya instalada), comprobación sin escrituras, bloqueo de una variante ajena antes de instalar, huellas de salida, instalación repetida y reversión exacta sin pisar ediciones posteriores.
- Paginación de candidatos PHP/JS, configuración privada ficticia y 21 comprobaciones unitarias de horario de contacto.
- Sintaxis PHP y `git diff --check`.

Las pruebas automatizadas utilizan datos ficticios, red local y carpetas temporales; no exigen credenciales reales ni DB. El workflow `DENUE - certificados y candidatos estables` ejecuta las regresiones y la publicación aislada.

## Comprobación desde Hostinger

### Carga del JavaScript de candidatos

Después de instalar la paginación, el mensaje «Recarga la página para cargar la búsqueda de candidatos» revela que la vista no recibió la utilidad nueva del footer. La prueba de navegador anterior inyectaba el archivo en su cabecera y no detectaba esa dependencia. Se reprodujo el fallo retirando esa inyección. La vista ahora carga su propio script con SHA-256 del contenido y captura esa referencia antes de DOMContentLoaded; un footer personalizado ausente o uno que vuelva a cargar el script antiguo ya no bloquea la búsqueda. La prueba usa la vista real y ambos escenarios, además de las 32 páginas y los filtros. No se modifica el footer global ni sus personalizaciones.

El paquete acepta la vista de 27e6c244 ya instalada. Sobre esa entrega sólo cambia `app/views/seguimiento_vinculacion/estado.php`: los otros siete archivos se verifican y quedan intactos. Se prueba expresamente ese caso con instalación y reversión en carpetas temporales.

Después de descargar el paquete, `tools/diagnosticar_denue_tls.php --site RUTA --ca ARCHIVO_PEM` permite comparar la confianza predeterminada con la cadena completa usando el token privado ya configurado. Sólo muestra códigos HTTP/cURL y banderas de formato/autorización; no imprime token, teléfonos, establecimientos ni cuerpos de respuesta. No modifica el CRM.

La comprobación desde Hostinger con cadena completa devolvió HTTP 200, cURL 0, JSON válido y un establecimiento en 0.66 segundos. Otra comprobación de paginación real en Tláhuac obtuvo 100 registros en el rango 1–100 y otros 100 en 101–200, sin identificadores repetidos.

Con la versión instalada: probar Ciudad de México/Benito Juárez y Tláhuac, cambiar municipio y tipo, buscar por nombre y recorrer páginas posteriores a la primera carga. Ante un fallo temporal debe reanudar el rango pendiente. Si DENUE permanece inaccesible, debe mostrar un error real conservando el avance; no anunciar un catálogo terminado, un total inventado o un vacío válido. No se promete disponibilidad absoluta de un tercero.

Para revertir usar el mismo publicador con `--site`, `--backup-root` y `--restore RUTA_DEL_RESPALDO`. No hacer `git pull` en `public_html`.

## Compatibilidad del instalador con Python de Hostinger

La primera ejecución se detuvo durante `--check` porque `capture_output` requiere Python 3.7. Se reemplazó por `stdout=PIPE` y `stderr=PIPE`, compatibles con Python 3.6, y se añadió una comprobación explícita de la versión mínima. El fallo ocurrió antes de instalar archivos en el sitio.

El ciclo completo del instalador se probó con Python 3.6.8 oficial y PHP 8.2 real, además de Python 3.11. CI repite la ejecución del intérprete antiguo descargado de python.org con su SHA-256 comprobado. El manifiesto y los cinco archivos del paquete de ejecución permanecen idénticos; sólo se adapta el publicador, su prueba y CI.

Fuentes primarias: [API de DENUE](https://www.inegi.org.mx/servicios/api-denue/), [verificación TLS de curl](https://curl.se/docs/sslcerts.html), [intermedios de Sectigo](https://www.sectigo.com/knowledge-base/detail/Sectigo-Intermediate-Certificates). Procedencia de la cadena en `config/certificates/inegi-ca-chain.md`.
