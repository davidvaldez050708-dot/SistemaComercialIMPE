# DENUE: certificado y recuperación de candidatos

## Causa confirmada

La prueba desde Hostinger devolvió `http=0`, `curl_errno=60`, sin JSON, en 0.18 segundos. El fallo se produce antes de consultar los candidatos; no indica que el municipio esté vacío ni que haya un filtro incorrecto.

Se reprodujo con PHP/cURL local: `SSL certificate problem: unable to get local issuer certificate`. El certificado de `www.inegi.org.mx` es de Sectigo OV R36 y entró en vigencia el 2 de noviembre de 2026. La conexión autenticada mediante el almacén de Windows obtuvo un único certificado entregado por el sitio; falta el intermedio. Al proporcionar la cadena completa de CA, PHP verifica correctamente el host y recibe HTTP 404 en la raíz de la API, en lugar de error TLS. Esa prueba no usa token y demuestra conexión HTTPS, no una importación ni una consulta autenticada completa de candidatos.

La corrección antigua de DENUE sí está instalada: `DenueService.php` coincide con SHA-256 `0cbde0224b5fe53171024505ac49c80fd8f9bcaad3070a5d11b6f548b6bc66e7`. Era para lotes de cuantificación/reintentos, no completaba esta cadena TLS. La búsqueda automática además tenía otro transporte: 18 peticiones sin reintento con hasta cinco tandas de 16 segundos. El controlador convertía esos fallos en el mismo mensaje genérico.

## Cambio

- Almacén público de CA de Mozilla más intermedio oficial de Sectigo, verificado contra las raíces. Sólo se utiliza para el HTTPS oficial de INEGI desde DENUE. No se cambia el reloj, la configuración global de PHP/cURL, la PBX ni otras APIs.
- Certificado y nombre del host siempre se verifican. No hay `--insecure`, verificación desactivada ni confianza directa en el certificado hoja. Renovar el certificado hoja bajo la misma cadena no requiere renovar este paquete.
- Transporte de candidatos con cuatro consultas simultáneas como máximo, dos intentos sólo ante fallos temporales, respeto de `Retry-After` y presupuesto global de 25 segundos. Se conservan sectores, estratos, ponderaciones y límites del catálogo actual.
- Un rechazo de autorización dentro de HTTP 200 se distingue de resultados válidos o un vacío real. Los fallos permanentes de certificado/autorización no se reintentan ni se ocultan con caché vieja.
- Una consulta parcial conserva los datos recibidos y puede complementarse con una copia del mismo ámbito de máximo 24 horas, con aviso visible. No borra la última copia completa ni cambia su fecha para aparentar actualidad.
- Caché por estado, municipio y término normalizado; escrituras atómicas para evitar JSON truncado. Los resultados se deduplican y se mantienen las validaciones al iniciar un seguimiento.
- El controlador conserva los filtros/autorizaciones y presenta los avisos del servicio. Un fallo sin resultados explica su causa en lugar de mostrar un falso «sin candidatos».

## Compatibilidad

El manifiesto acepta las variantes comprobadas del controlador, incluida la instalada `cd319b9bdcf1d75a27ff9b3244e7478303b9e51d5eceebe776cff31b24ad414f`. En esa variante sólo cambia el método `buscarCandidatos`; conserva íntegros la cartera territorial y los demás métodos. No se instala la versión antigua completa del controlador.

La publicación contiene cinco archivos de ejecución: cadena CA, política TLS, transporte HTTP de candidatos, servicio y controlador. Las variantes desconocidas detienen todo antes de publicar. `--check` sólo verifica compatibilidad y sintaxis. `--apply` guarda respaldo privado, instala de forma selectiva y comprueba integridad. `--restore` restaura únicamente si los archivos siguen siendo los instalados por esta entrega.

No ejecuta SQL, no consulta la DB, no importa candidatos reales y no modifica datos de Seguimiento, prospectos, asignaciones, Aliados, horarios ni telefonía. La caché de DENUE se renueva durante las búsquedas normales.

## Verificación

- `tests/denue_http_local.py`: cURL real contra servidor local ficticio; 503 recuperado, JSON inválido, 403 y rechazo de token con HTTP 200, vacío válido, 429/Retry-After, 18 timeouts con presupuesto global y opciones TLS.
- `tests/denue_candidatos_resiliencia.php`: deduplicación, límites geográficos/términos, consulta por nombre, caché fresca/corrupta/vencida, respuesta parcial, preservación de copia completa y rechazo de autorización.
- `tests/publicacion_denue_estabilidad.py`: dos variantes exactas, comprobación sin escrituras, bloqueo de una variante ajena antes de instalar, huellas de salida, instalación repetida y reversión exacta sin pisar ediciones posteriores.
- Paginación de candidatos PHP/JS, configuración privada ficticia y 21 comprobaciones unitarias de horario de contacto.
- Sintaxis PHP y `git diff --check`.

Las pruebas automatizadas utilizan datos ficticios, red local y carpetas temporales; no exigen credenciales reales ni DB. El workflow `DENUE - certificados y candidatos estables` ejecuta las regresiones y la publicación aislada.

## Comprobación desde Hostinger

Después de descargar el paquete, `tools/diagnosticar_denue_tls.php --site RUTA --ca ARCHIVO_PEM` permite comparar la confianza predeterminada con la cadena completa usando el token privado ya configurado. Sólo muestra códigos HTTP/cURL y banderas de formato/autorización; no imprime token, teléfonos, establecimientos ni cuerpos de respuesta. No modifica el CRM.

Con la versión instalada: probar Ciudad de México/Benito Juárez, cambiar a otro municipio, empresas/instituciones y búsqueda por nombre, después paginar e iniciar un seguimiento de prueba autorizado. Ante un fallo externo real debe conservar candidatos disponibles y explicar si utiliza una copia; una primera búsqueda sin copia no puede garantizar resultados si INEGI permanece caído. No se promete disponibilidad absoluta de un tercero.

Para revertir usar el mismo publicador con `--site`, `--backup-root` y `--restore RUTA_DEL_RESPALDO`. No hacer `git pull` en `public_html`.

## Compatibilidad del instalador con Python de Hostinger

La primera ejecución se detuvo durante `--check` porque `capture_output` requiere Python 3.7. Se reemplazó por `stdout=PIPE` y `stderr=PIPE`, compatibles con Python 3.6, y se añadió una comprobación explícita de la versión mínima. El fallo ocurrió antes de instalar archivos en el sitio.

El ciclo completo del instalador se probó con Python 3.6.8 oficial y PHP 8.2 real, además de Python 3.11. CI repite la ejecución del intérprete antiguo descargado de python.org con su SHA-256 comprobado. El manifiesto y los cinco archivos del paquete de ejecución permanecen idénticos; sólo se adapta el publicador, su prueba y CI.

Fuentes primarias: [API de DENUE](https://www.inegi.org.mx/servicios/api-denue/), [verificación TLS de curl](https://curl.se/docs/sslcerts.html), [intermedios de Sectigo](https://www.sectigo.com/knowledge-base/detail/Sectigo-Intermediate-Certificates). Procedencia de la cadena en `config/certificates/inegi-ca-chain.md`.
