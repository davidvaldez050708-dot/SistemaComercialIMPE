# Correo comercial exclusivamente Hostinger

Base: `origin/main` a90b6ade. Recuperación/login/SMTP y sus archivos permanecen idénticos a main; el publicador tampoco los reemplaza en instalaciones que tengan una versión posterior.

**Causa comprobada:** el servidor respondió `archivo_php=NO` para `public_html/config/hostinger_mail_config.php`. El 22/10/2026 se intentó un envío real desde el formulario del CRM publicado: lo rechazó antes de salir indicando que no dispone de una configuración de salida válida. Por separado, el token sí se validó contra Hostinger y se confirmó una recepción real en Gmail. La implementación anterior además exigía cuentas individuales y mezclaba SMTP/OAuth con el transporte comercial.

**Token existente:** `HOSTINGER_MAIL_API_TOKEN` en entorno o en `hostinger_mail_config.php`, resuelto por `config/private_config.php`. No se utiliza `HOSTINGER_API_TOKEN` general de hPanel. Se conserva compatibilidad con `api_token` de `mail_central.local.php` (`transport=hostinger_api`) y `hostinger_mail_api_token` de `marketing_mail.local.php`. Precedencia: entorno, archivo central API, constante Mail API, configuración comercial legacy. No lee credenciales de recuperación.

Guardar nuevas claves exclusivamente en `$HOME/domains/fundacionrededumex.org/impe-private/` (fuera de public_html), PHP privado 0600. No introducir el token en Git, navegador, chat ni argumentos de comandos.

Tras aplicar el publicador comercial:

```bash
# Si el token todavía no está en una ubicación reconocida: entrada oculta,
# validación por GET /me antes de guardar y respaldo de configuración anterior.
bash "$HOME/domains/fundacionrededumex.org/public_html/tools/configurar_hostinger_comercial.sh"
# Comprobación sin enviar mensajes ni consultar SMTP:
php "$HOME/domains/fundacionrededumex.org/public_html/tools/diagnosticar_hostinger_comercial.php" --check
```

El configurador escribe únicamente `mail_central.local.php` privado; no modifica ninguna configuración de recuperación. Si existe `HOSTINGER_MAIL_API_TOKEN` en entorno, tiene prioridad: actualizarlo allí al rotar una clave. Aplicar/restore del publicador no carga ni cambia credenciales. Guardar los comandos exactos de restauración impresos por ambas herramientas.

**Autorización:** API oficial `GET /api/v1/me` → `data.mailboxes[].address/resourceId`. De conserva su campo y ofrece estas direcciones con datalist. No sustituye silenciosamente un correo ni permite Gmail/Outlook como remitentes. Un usuario activo con permisos actuales de envío utiliza los buzones centrales sin conexión personal. Se respetan restricciones existentes de `mail_accounts.local.php`; opcionalmente el archivo central limita `allowed_senders`, `user_senders[ID]`, `role_senders[rol/ID]`, siempre en intersección con la API. No hay autorizaciones por dominio supuesto ni tokens individuales. Los antiguos enlaces OAuth comerciales se redirigen sin borrar datos o claves anteriores.

**Envío:** `POST /api/v1/mailboxes/{resourceId}/send`, usando el remitente exacto y comprobándolo de nuevo antes de transmitir. Sólo HTTP 204 es aceptación; no acredita entrega. GET puede indicar token ausente, 401/403, limitación 429 o falta de buzones. Se registran método/estado HTTP y códigos seguros, sin token, cuerpo ni respuesta cruda. Un POST incierto no causa fallback ni reenvío automático. Los adjuntos, firma, borradores e historial reutilizan los servicios actuales. El destinatario sólo necesita formato válido, sin filtros por proveedor.

**Publicación:** único publicador de esta entrega `tools/publicar_correo_comercial_hostinger.sh`, con `--check`, `--apply`, `--restore`. Lista cerrada de diez archivos comerciales. Compatible con main y revisiones comerciales anteriores conocidas; ante personalización incompatible aborta antes de escribir. No utilizar el antiguo publicador multiproveedor para esta entrega. Comandos con revisión/hash fijos en el PR.

La integración de ejecución está en `codex/hostinger-comercial-central`, commit `49e37787eb124787231d485a23fce835974ff7d8`, [PR #126](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/126). Esta revisión del publicador contiene los mismos endpoints oficiales y el transporte utilizado en la prueba directa. No basta con subir el código: PHP de Hostinger necesita el token en su propia configuración privada. Las credenciales locales no se transfieren con Git.

Para descargar y comprobar el publicador por SSH, sin modificar el CRM:

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/49e37787eb124787231d485a23fce835974ff7d8/tools/publicar_correo_comercial_hostinger.sh" \
  -o "$HOME/impe-publicadores/hostinger-comercial-49e37787.sh" &&
printf '%s  %s\n' \
  '70fc7dfcdb69d72bf6ff6a39520e53aa3ad52e09706edf004d23b414f9bce516' \
  "$HOME/impe-publicadores/hostinger-comercial-49e37787.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/hostinger-comercial-49e37787.sh" --check
```

Sólo si `--check` termina correctamente, publicar selectivamente y conservar el comando exacto de restauración que imprime:

```bash
bash "$HOME/impe-publicadores/hostinger-comercial-49e37787.sh" --apply
```

Después ejecutar el configurador y el diagnóstico indicados arriba. El configurador pedirá el token de forma oculta directamente en SSH; nunca se pega en el chat ni se guarda en Git. Al abrir **Correos → Redactar correo**, elegir en **De** un buzón de la lista autorizada y completar destinatario, asunto y mensaje. Esta integración envía por API desde ese buzón; no requiere abrir ni iniciar sesión en el correo web de Hostinger.

**Pruebas:** API simulada, token desde entorno/archivos, restricciones, Marketing/Analista/Cuenta Clave, destinatarios Gmail/Outlook/institucionales, adjunto íntegro, rechazos/incertidumbre, borrador e historial sin duplicados, endpoint protegido y comparación de archivos de recuperación con main. Publicación/restauración en sitios ficticios, preservando hashes de recuperación y configuración privada.

**Prueba real completada (22/10/2026):** el usuario ingresó temporalmente el token de forma oculta y cifrada en Windows. `GET /api/v1/me` respondió HTTP 200 y autorizó un buzón institucional. Tras autorización expresa se realizó un único `POST /send` usando `CorreoHostingerApiService`, con destinatario Gmail y datos de prueba: HTTP 204. Se verificó mediante el conector Gmail el remitente, destinatario, asunto y código del mensaje recibido con etiqueta `INBOX`. La copia temporal cifrada del token fue eliminada. No se publican direcciones personales, credenciales ni identificadores del buzón. Esta prueba fue directa por API, fuera del CRM: confirma proveedor y recepción, pero no valida el flujo desplegado, los adjuntos ni su historial.

**Pendiente en Hostinger:** publicar la integración y guardar el token privado en el servidor; después ejecutar desde Marketing dos envíos autorizados (Gmail e institucional), uno con documento adjunto, confirmar recepción e integridad, historial y borrador, y comprobar otro perfil autorizado. No se dispone de SSH autenticado del agente. No probar restablecimiento de usuarios reales: la protección de recuperación se verifica por comparación de archivos, sin cambiar contraseñas. No se realizó fusión ni despliegue automático.

Fuente: [esquema oficial Hostinger Mail API](https://raw.githubusercontent.com/hostinger/mail-api/main/openapi.json), Account / Send.
