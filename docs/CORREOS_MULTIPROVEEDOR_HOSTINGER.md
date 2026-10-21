# Correos en Hostinger: implementación y configuración pendiente

Rama de revisión: `codex/correos-autorizacion-cuentas`, basada en `origin/main` (`a90b6ade`). Incluye la corrección del publicador del PR #121. Sin fusión automática, despliegue, migraciones ni servicios contratados.

El rechazo original de Gmail ocurría antes de contactar con el proveedor: Marketing sólo seleccionaba su cuenta central o un buzón Hostinger. El historial muestra la retirada del SMTP por usuario (`c97c0970`, `afc1e988`) y la restricción del remitente (`ae918749`). Esa ruta ya se sustituyó por cuentas autorizadas; su presencia en GitHub no prueba que esté publicada en Hostinger. No hay evidencia aún de una restricción saliente del plan.

La recuperación es prioritaria e independiente de Marketing/OAuth. Si faltaba `MAIL_HOST`, el valor predeterminado era Hostinger incluso con usuario Gmail; ahora se elige `smtp.gmail.com` para Gmail y se conserva cualquier host explícito. Web y diagnóstico leen la misma cuenta dedicada o los `MAIL_*` anteriores. Un archivo dedicado inválido detiene el envío; no toma credenciales comerciales ni otra cuenta. Se conserva el destinatario registrado, el contenido y los 30 minutos; LoginController sólo actualiza la contraseña después de aceptación SMTP. Esto corrige la selección de configuración, pero no sustituye una clave ausente o rechazada por Google ni acredita envío desde Hostinger.

## Configuración privada que falta proporcionar

Ubicación Hostinger: `$HOME/domains/fundacionrededumex.org/impe-private/`, hermana de `public_html`. Directorio 0700 y PHP privados 0600, accesibles por el usuario del proceso PHP. No pegar contraseñas ni tokens en chats, comandos del historial o Git. Usar el editor privado del servidor. Los ejemplos contienen datos ficticios y no se publican como configuración activa.

* **Recuperación Gmail:** conservar los `MAIL_*` existentes si son válidos, u opcionalmente crear `password_reset_mail.local.php` a partir del ejemplo homónimo de `config/`. Esta cuenta es independiente del campo De y de las cuentas comerciales. SMTP `smtp.gmail.com:587`, TLS y contraseña de aplicación cuando la cuenta la permita; no la contraseña normal. Se conserva contraseña temporal, vigencia de 30 minutos y flujo de LoginController.
* **Hostinger:** conservar `hostinger_mail_config.php`/`HOSTINGER_MAIL_API_TOKEN`; el buzón debe aparecer en `GET /api/v1/me` y pertenecer al usuario del CRM o tener autorización explícita en el registro privado. El token de Marketing permanece limitado a su perfil. No se sustituye el buzón por otro.
* **Gmail SMTP e institucionales:** `mail_accounts.local.php`, ejemplo `config/mail_accounts.local.example.php`. Cada dirección tiene transporte, credenciales propias, `activo` e IDs reales de usuarios autorizados. Gmail puede usar contraseña de aplicación si sus políticas lo permiten. Institucional: host/puerto/TLS del proveedor. No se heredan credenciales de recuperación. Los alias requieren autorización administrativa explícita y aceptación del proveedor. `limits.per_user_hour` configura entre 1 y 500 intentos por usuario/hora; por defecto 60.
* **OAuth, actualmente sin aplicaciones configuradas:** crear privadamente `mail_oauth.local.php` siguiendo `config/mail_oauth.local.example.php`: `providers.google`/`providers.microsoft`, `enabled`, `client_id`, `client_secret`, `redirect_uri` y, para Microsoft, `tenant`. Mantener `enabled=false` hasta tener una aplicación Web registrada y consentida. No se registraron aplicaciones ni se autorizaron cuentas por el usuario.
* **Retorno OAuth exacto:** `https://fundacionrededumex.org/index.php?controller=correoConexion&action=callback`. Registrar esa URL HTTPS en el proveedor. No usar la URL de vista previa ni una redirección suministrada por el navegador.
* **Google OAuth nuevo:** habilitar Gmail API y consentir `openid email https://www.googleapis.com/auth/gmail.send`. El envío usa HTTPS y MIME real; se comprueba que Google confirme la dirección como verificada y que coincida con De. No solicita lectura/eliminación de correo. Google puede requerir verificación de la aplicación antes del uso público. Las cuentas SMTP/OAuth ya administradas conservan su configuración; no se convierten automáticamente a API.
* **Microsoft OAuth:** permiso delegado `https://outlook.office.com/SMTP.Send` y `offline_access`, aplicación compatible con el tipo de cuenta y SMTP AUTH permitido para el buzón. Microsoft 365 usa `smtp.office365.com:587/TLS`; Outlook/Hotmail, `smtp-mail.outlook.com:587/TLS`. No deshabilitar políticas de seguridad globales para habilitarlo.

Opcionales de instalación: `IMPE_PRIVATE_CONFIG_DIR` cambia la carpeta de configuración; `IMPE_MAIL_STATE_DIR` cambia el estado técnico privado. Debe quedar fuera de todos los directorios públicos. Por defecto: `impe-private/mail-state/`. Contiene tokens, límites y evidencia técnica con archivos 0600 y bloqueo `flock`; no publicarlo ni borrarlo para forzar reintentos. Un clúster requeriría bloqueo/almacenamiento compartido; esta implementación corresponde al servidor único actual.

## Comportamiento y alcance

Conectar cuenta se abre en una página separada. Del modal sólo se retiró la leyenda solicitada bajo De; sus campos y funcionamiento se conservan. Un aviso nuevo de Marketing sustituye al anterior para que los reintentos no acumulen notificaciones. El error de cuenta no conectada permite abrirla en otra pestaña sin perder cuerpo ni archivos seleccionados. Puede acceder Marketing o un usuario que ya tenga permisos operativos de correo; no se crean permisos ni se convierte al Administrador en operador. Cada autorización está ligada al usuario, proveedor, correo y sesión; state de un solo uso, PKCE S256, caducidad de diez minutos, CSRF en inicio/desconexión y máximo diez inicios por diez minutos. Los tokens sólo circulan entre servidor y proveedor, se renuevan en el servidor y no llegan a JavaScript. Desconectar limpia esa conexión y su caché local; el consentimiento externo también puede revocarse desde la cuenta del proveedor.

Los transportes comparten la selección de identidad y los recursos MIME de PHPMailer: SMTP autenticado, Microsoft XOAUTH2, Gmail API y Hostinger API. La recuperación conserva su cuenta dedicada. No se cambian generación de oficios, PDF, Telefonía, reglas comerciales, usuarios ni tablas. El publicador incluye la integración de correo de Vinculación ya aprobada en main.

Marketing conserva las tablas y el historial. La evidencia nueva se guarda fuera de la BD, por usuario y registro: `PENDIENTE`, `EN_PROCESO`, `ACEPTADO`, `FALLIDO` o `DESCONOCIDO`. La bandeja/detalle muestran esos estados cuando existen; los históricos no se reescriben. Si el estado auxiliar no se puede leer, el historial continúa disponible y muestra incertidumbre. `ENVIADO` sigue siendo el estado de compatibilidad de la tabla, sólo tras aceptación y persistencia de los adjuntos. No se genera evidencia de entrega confirmada: SMTP/API no acreditan por sí mismos recepción en el buzón final.

Las solicitudes repetidas de Marketing devuelven el mismo registro. Un rechazo explícito conserva el borrador; una operación incierta no se reenvía ni cambia de proveedor. Los clientes antiguos y otros consumidores sin clave explícita tienen una protección de 60 segundos contra repeticiones idénticas; la incertidumbre no expira automáticamente. Correo y MySQL no comparten transacción: si se acepta el mensaje pero falla su registro, la bandeja indica registro incompleto y se requiere conciliación manual. No borrar estados privados para reenviar.

Archivos funcionales añadidos: `CorreoConexionController`, vista `correos/conexion.php`, servicios `CorreoConexionOAuthService`, `CorreoGmailApiTransport`, `CorreoLimiteService` y `CorreoRegistroEstadoService`. Cambios limitados al resolver/transporte/estado de correo, Marketing, su JavaScript, enlace fuera del modal y rutas/CSRF de correo en `index.php`. Las configuraciones privadas están ignoradas por Git; los ejemplos contienen valores ficticios. No se instala ni modifica vendor.

## Evidencia disponible y pruebas pendientes

| Prueba dirigida | Evidencia disponible |
|---|---|
| Recuperación | Gmail con host implícito, precedencia de PHP privado/entorno/cuenta dedicada, host/SSL explícitos, ausencia de credenciales sin simular envío; contenido/destinatario/vigencia y SMTP local real con TLS |
| Hostinger API | Buzón exacto, archivos, rechazo e incertidumbre simulados |
| Gmail API | HTTP simulado; identidad verificada, MIME real, To/From, imagen CID y adjunto íntegros |
| Google/Microsoft OAuth | Consentimiento/intercambio simulados: state, PKCE, sesión, dueño, caducidad, replay, cancelación y desconexión |
| SMTP/XOAUTH2 | PHPMailer real contra servidor local efímero, certificado no confiable rechazado, AUTH LOGIN y XOAUTH2/MIME correctos |
| Marketing | BD en memoria: borradores, propiedad, contenido, estados, aceptación e incertidumbre sin duplicados |
| Acceso/interfaz | CSRF/rol/método/acciones internas rechazados; sólo retirada autorizada de la leyenda del modal |
| Publicador | Sitios ficticios anteriores, main-v1 y cuentas-v2: check sin escritura, respaldo/restore, idempotencia y preservación de archivos privados/CRLF/comentarios |

Pruebas en `tests/correos_*`: sólo dirigidas, sin CI global. No sustituyen MySQL, navegador autenticado y proveedores reales de producción. **No hay envíos desde Hostinger ni recepciones reales confirmados.** Faltan credenciales privadas, aplicaciones/consentimiento OAuth para quien elija esa ruta, acceso al servidor y destinatario de prueba autorizado.

Después de publicar y configurar privadamente, desde la carpeta del CRM:

```bash
php tools/diagnosticar_correo_hostinger.php --recovery --probe
php tools/diagnosticar_correo_hostinger.php --account='CUENTA_CONECTADA' --user=ID_AUTORIZADO --probe
# Para DNS institucional, añadir --domain=DOMINIO --dkim=SELECTOR_REAL
```

El diagnóstico sólo funciona por CLI. Probe consulta DNS/TLS/AUTH, identidad Google o autorización Hostinger sin enviar mensajes; OAuth puede renovar su estado privado. Si falla, comprobar CA/OpenSSL, puerto/HTTPS saliente, políticas del buzón y restricciones del plan con evidencia del servidor. No modificar `disable_functions`, instalar ejecutables ni desactivar TLS.

Ejecutar siempre `php` antes de la ruta del diagnóstico; ejecutarla directamente produce un error de permisos que no diagnostica SMTP. Los códigos seguros `MAIL_RESET_IDENTITY`/`MAIL_SMTP_CONFIG` indican identidad o credenciales/configuración incompletas; `MAIL_RESET_CONFIG`, archivo dedicado inválido. Un código SMTP 535 requiere revisar la autenticación privada con el proveedor. No pegar claves en el chat. La cuenta Gmail de recuperación no necesita una aplicación OAuth de los empleados cuando usa su SMTP y clave de aplicación autorizada.

Sólo con un destinatario autorizado, añadir `--send-test --to=DESTINO_AUTORIZADO` para un mensaje ficticio; no repetir si el resultado es incierto. El comando no modifica contraseñas ni crea historial comercial. Para pruebas completas: recuperación desde la aplicación con cuenta propia de prueba; Hostinger/Gmail/Microsoft/institucional desde cada perfil con permiso; convocatoria, borrador, asunto/cuerpo, firma, adjuntos descargables e historial MySQL; recepción inbox/spam y cabeceras SPF/DKIM/DMARC. La presencia de DNS por sí sola no demuestra entrega. Ningún correo a contactos comerciales sin autorización.

## Publicación manual selectiva

Único publicador: `tools/publicar_correos_multiproveedor_hostinger.sh`, lista cerrada de 21 archivos. Admite la instalación anterior, main-v1 y cuentas-v2 (`69d65f41`), sin sobrescribir personalizaciones incompatibles. El diagnóstico auxiliar se instala si falta; restore retira también su carpeta si fue creada y sigue vacía. Configuraciones privadas y BD quedan fuera de la publicación y el respaldo de código.

Descargarlo de la rama revisada fuera de public_html; cuando se apruebe la integración en main, puede descargarse de main. Usar la revisión/hash publicada junto al PR. No hacer git pull directamente en producción.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
bash "$HOME/impe-publicadores/publicar_correos_multiproveedor_hostinger.sh" --check
# Sólo después de revisar OK y autorizar publicación:
bash "$HOME/impe-publicadores/publicar_correos_multiproveedor_hostinger.sh" --apply
# Restaurar con el comando exacto de la línea Rollback que imprime --apply.
```

No se ejecutó publicación ni fusión. Restore verifica integridad y rechaza pisar ediciones posteriores. Restaurar código no deshace correos ni cambia credenciales/tokens/BD. Publicar en un momento sin envíos activos, invalidar OPcache según el PHP web y recargar el navegador. Revisar que monitorización/logs de acceso no archiven parámetros del retorno OAuth; los logs del CRM no incluyen tokens ni respuestas privadas del proveedor.

Fuentes oficiales: [contraseñas de aplicación Gmail](https://support.google.com/accounts/answer/185833), [OAuth Web Google](https://developers.google.com/identity/protocols/oauth2/web-server), [Gmail messages.send](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/send), [OAuth SMTP Microsoft](https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth/), [Hostinger Mail API](https://api.mail.hostinger.com/).
