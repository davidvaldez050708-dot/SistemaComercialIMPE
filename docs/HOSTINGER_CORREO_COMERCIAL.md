# Correo comercial exclusivamente Hostinger

Base: `origin/main` a90b6ade. Recuperación/login/SMTP y sus archivos permanecen idénticos a main; el publicador tampoco los reemplaza en instalaciones que tengan una versión posterior.

**Diagnóstico actual recibido por SSH:** `hostinger_mail_config.php` ausente; `mail_central.local.php` legible; `marketing_mail.local.php` ausente; token válido y un único buzón utilizable devuelto por la consulta: `d.institucional2@rededucativamexico.org`. Esto supera el fallo anterior de token ausente. No acredita que existan otros buzones dentro de esa orden ni que el token los incluya. El formato del diagnóstico corresponde a la entrega anterior; no se han comprobado todos los hashes del código web desplegado.

**Alcance de Hostinger:** el contrato oficial de `GET /api/v1/me` devuelve `data.mailboxes[].address/resourceId` y no define parámetros de paginación para este endpoint. Los tokens Mail API están limitados a una orden y pueden limitarse a buzones concretos. Si falta `superior286@rededucativamexico.org`, el administrador debe verificar que el buzón existe en la misma orden y que el token lo incluye. Para acceso dinámico a todos los buzones de esa orden, utilizar el alcance de orden sin restricción a una selección de buzones, si Hostinger lo permite en esa cuenta. Un token de otra orden no los autoriza aunque compartan dominio. No se usan endpoints de hPanel ni autorizaciones inventadas.

**Token central:** `HOSTINGER_MAIL_API_TOKEN` en entorno o en `hostinger_mail_config.php`, resuelto por `config/private_config.php` (sin modificar ese helper). Se conserva compatibilidad con `api_token` de `mail_central.local.php` (`transport=hostinger_api`) y el token comercial legacy. Precedencia de valores no vacíos: entorno, archivo central API, constante Mail API, configuración comercial legacy. Con una credencial de mayor prioridad no se ejecutan los archivos antiguos de credenciales. No se cambia de token automáticamente tras un rechazo: el diagnóstico indica su fuente efectiva para corregir una variable de entorno antigua. Se respetan desactivaciones y restricciones administrativas; no se leen credenciales de recuperación ni se usa el token general de hPanel.

Guardar nuevas claves exclusivamente en `$HOME/domains/fundacionrededumex.org/impe-private/` (fuera de public_html), PHP privado 0600. No introducir el token en Git, navegador, chat ni argumentos de comandos.

Tras aplicar el publicador comercial:

```bash
# Si el token todavía no está en una ubicación reconocida: entrada oculta,
# validación por GET /me antes de guardar y respaldo de configuración anterior.
bash "$HOME/domains/fundacionrededumex.org/public_html/tools/configurar_hostinger_comercial.sh"
# Comprobación sin enviar mensajes ni consultar SMTP:
php "$HOME/domains/fundacionrededumex.org/public_html/tools/diagnosticar_hostinger_comercial.php" --check
```

El configurador conserva sin sobrescribir una configuración existente que ya es válida y devuelve buzones institucionales. Para sustituir expresamente un token de alcance insuficiente, usar `bash tools/configurar_hostinger_comercial.sh --reemplazar-token` desde la raíz del CRM. Si existe `HOSTINGER_MAIL_API_TOKEN` en entorno, tiene prioridad: actualizarlo allí al rotar una clave. El configurador escribe sólo `mail_central.local.php` privado, valida antes de guardar y respalda la versión anterior. Aplicar/restore del publicador no carga ni cambia credenciales. Guardar los comandos exactos de restauración impresos por ambas herramientas.

**Autorización dinámica:** todos los buzones reales de `@rededucativamexico.org` devueltos por Hostinger, sin una lista de direcciones en código ni registros por buzón. Dominio e identidad se comprueban conjuntamente; se rechazan correspondencias ambiguas entre dirección e ID. Se conserva el campo De y su datalist. Se actualiza al abrir el modal, al volver al campo después de 30 segundos y antes de validar un envío. Ante error se vacían las opciones antiguas y se conserva el mensaje de configuración; una respuesta atrasada no reemplaza una lista más reciente. No se modifica diseño, CSS, dimensiones ni tipografía.

Un usuario activo con permisos actuales utiliza el servicio compartido. Se respetan las restricciones previas de `mail_accounts.local.php`, y las opcionales `allowed_senders`, `user_senders` y `role_senders`, siempre en intersección con la API. No se crean ni exigen configuraciones por usuario. El diagnóstico SSH nuevo identifica qué restricciones están configuradas; **la salida real recibida aún no permite determinar cuáles existen en producción**. El endpoint web informa cantidades API/institucionales/permitidas y versión, sin token ni resourceId. Los destinatarios mantienen cualquier dominio válido.

**Envío:** `POST /api/v1/mailboxes/{resourceId}/send`, usando el remitente exacto y comprobándolo de nuevo antes de transmitir. Sólo HTTP 204 es aceptación; no acredita entrega. GET puede indicar token ausente, 401/403, limitación 429 o falta de buzones. Se registran método/estado HTTP y códigos seguros, sin token, cuerpo ni respuesta cruda. Un POST incierto no causa fallback ni reenvío automático. Los adjuntos, firma, borradores e historial reutilizan los servicios actuales. El destinatario sólo necesita formato válido, sin filtros por proveedor.

**Publicación:** único publicador de esta entrega `tools/publicar_correo_comercial_hostinger.sh`, con `--check`, `--apply`, `--restore`. Lista cerrada de diez archivos comerciales. Compatible con main y revisiones comerciales anteriores conocidas; ante personalización incompatible aborta antes de escribir. No utilizar el antiguo publicador multiproveedor para esta entrega. Comandos con revisión/hash fijos en el PR.

La corrección está en `codex/hostinger-comercial-central`, commit **`63b91494d1f9dde1ad256e45f73ac4394b2db2c5`**, [PR #126](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/126). Versión de diagnóstico: `hostinger-comercial-buzones-v2`. Publicador `hostinger-comercial-v2-8fd03e45b6f4d1cb`. Es una entrega nueva: **no usar el publicador de 49e37787 para instalar esta corrección**. El token ya configurado en el servidor se conserva.

Para descargar y comprobar el publicador por SSH, sin modificar el CRM:

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/63b91494d1f9dde1ad256e45f73ac4394b2db2c5/tools/publicar_correo_comercial_hostinger.sh" \
  -o "$HOME/impe-publicadores/hostinger-comercial-63b91494.sh" &&
printf '%s  %s\n' \
  'c200a14404d0c090ea11b4cfa44b53133eae542343755e447ea988808ae0da48' \
  "$HOME/impe-publicadores/hostinger-comercial-63b91494.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/hostinger-comercial-63b91494.sh" --check
```

Sólo si `--check` termina correctamente, publicar selectivamente y conservar el comando exacto de restauración que imprime:

```bash
bash "$HOME/impe-publicadores/hostinger-comercial-63b91494.sh" --apply
```

Después comprobar el token existente y, si se desea, una dirección específica, sin enviar mensajes:

```bash
php "$HOME/domains/fundacionrededumex.org/public_html/tools/diagnosticar_hostinger_comercial.php" --check --remitente=superior286@rededucativamexico.org
```

Debe mostrar la versión v2, la fuente del token, cantidades de buzones, las restricciones y hashes de los archivos comerciales. El configurador sólo hace falta si no hay credencial utilizable o se sustituye expresamente su alcance. Nunca pegar el token en el chat ni guardarlo en Git. Si PHP web tiene variables/configuración distintas de SSH, comparar el endpoint de remitentes de la sesión web; un `--check` CLI correcto no prueba por sí solo el entorno web.

Recargar el CRM después de publicar; sus recursos JS ya usan `?v=filemtime` en el layout existente (sin modificarlo). Revisar OPcache del PHP web cuando corresponda. Al abrir **Correos → Redactar correo**, elegir en **De** un buzón de la lista y completar destinatario, asunto y mensaje. Se envía por API desde ese buzón, sin abrir el correo web. Si `/me` sigue devolviendo sólo un buzón, la corrección no fabrica los restantes.

**Pruebas dirigidas:** `hostinger_comercial_test.php` (API simulada, token privado, perfiles, destinatarios externos, adjunto íntegro, rechazos/incertidumbre, borrador e historial sin duplicados); `hostinger_buzones_dinamicos_test.php` (1→3 buzones institucionales, alta/revocación, dominio exacto, dirección/ID, filtros, precedencia y configuración idempotente); `hostinger_buzones_interfaz_test.js` (lista nueva, error sin opciones antiguas, contenido De conservado y respuestas fuera de orden). Comparación de recuperación/login/SMTP y llamadores con main antes y después: sin diferencias. Publicador probado con `--check`, `--apply`, `--restore`, idempotencia y rechazo de personalizaciones/ediciones posteriores en copias ficticias de main y del PR #126 instalado, preservando credenciales y recuperación. Sin CI global ni restablecimientos reales.

**Archivos de la corrección:** `CorreoHostingerApiService.php`, `CorreoCuentaConectadaService.php`, `correos_marketing.js`, configurador y diagnóstico comercial, publicador selectivo, tres pruebas comerciales y este documento. No cambia el servicio de envío POST, el controlador ni la vista respecto de la entrega anterior; se conserva el transporte del PR #126. El publicador contiene la lista cerrada de diez archivos de integración comercial para permitir tanto instalación inicial como actualización desde el PR #126.

**Prueba real completada (22/10/2026):** el usuario ingresó temporalmente el token de forma oculta y cifrada en Windows. `GET /api/v1/me` respondió HTTP 200 y autorizó un buzón institucional. Tras autorización expresa se realizó un único `POST /send` usando `CorreoHostingerApiService`, con destinatario Gmail y datos de prueba: HTTP 204. Se verificó mediante el conector Gmail el remitente, destinatario, asunto y código del mensaje recibido con etiqueta `INBOX`. La copia temporal cifrada del token fue eliminada. No se publican direcciones personales, credenciales ni identificadores del buzón. Esta prueba fue directa por API, fuera del CRM: confirma proveedor y recepción, pero no valida el flujo desplegado, los adjuntos ni su historial.

**Estado de aceptación:** A/B: token del servidor reconocido y un buzón devuelto según el diagnóstico SSH recibido. C/D/E/G/H/I: cubiertos con simulación dirigida; falta comprobarlos en el CRM web publicado. F: envío real y recepción Gmail comprobados previamente por API directa; el flujo web corregido sigue pendiente. J: comparación de archivos protegidos completada antes y después. Falta autorizar más buzones en Hostinger si existen, publicar v2 y comprobar desde el CRM un único envío a un destinatario de prueba autorizado con adjunto, recepción/integridad, historial y otro perfil. No hay SSH autenticado del agente, y la consulta del endpoint web durante esta revisión redirigió a login por sesión expirada. No se declara completada la validación de producción. Sin fusión ni despliegue automático.

Fuente: [esquema oficial Hostinger Mail API](https://raw.githubusercontent.com/hostinger/mail-api/main/openapi.json), Account / Send.
