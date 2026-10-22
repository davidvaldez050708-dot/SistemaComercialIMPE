# Correo comercial exclusivamente Hostinger

Base: `origin/main` a90b6ade. Recuperación/login/SMTP y sus archivos permanecen idénticos a main; el publicador tampoco los reemplaza en instalaciones que tengan una versión posterior.

**Causa comprobada:** el servidor respondió `archivo_php=NO` para `public_html/config/hostinger_mail_config.php`. No puede leer desde esa ruta el token que el usuario creía configurado. Ese resultado no comprueba permisos ni rechazo de Hostinger. La implementación anterior además exigía cuentas individuales y mezclaba SMTP/OAuth con el transporte comercial.

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

**Pruebas:** API simulada, token desde entorno/archivos, restricciones, Marketing/Analista/Cuenta Clave, destinatarios Gmail/Outlook/institucionales, adjunto íntegro, rechazos/incertidumbre, borrador e historial sin duplicados, endpoint protegido y comparación de archivos de recuperación con main. Publicación/restauración en sitios ficticios, preservando hashes de recuperación y configuración privada.

**Pendiente en Hostinger:** cargar/comprobar el token real y ejecutar desde Marketing dos envíos autorizados (Gmail e institucional), uno con documento adjunto; confirmar recepción e integridad, historial y borrador. Comprobar también el envío de otro perfil autorizado. No se recibieron credenciales ni se dispone de SSH autenticado del agente. No hay entrega real confirmada y no se declara completa esa validación. No probar restablecimiento de usuarios reales: la protección de recuperación se verifica por comparación de archivos, sin cambiar contraseñas.

Fuente: [esquema oficial Hostinger Mail API](https://raw.githubusercontent.com/hostinger/mail-api/main/openapi.json), Account / Send.
