# Correos en Hostinger: configuración y verificación pendiente

Rama: `codex/correos-multiproveedor-hostinger`, basada en `origin/main` (`fd5fd282`). Sin despliegue ni migraciones.

El bloqueo mostrado ocurría antes de contactar al proveedor: Marketing sólo aceptaba su cuenta central o buzones Hostinger. El historial Git muestra que se retiró la selección SMTP por usuario (`c97c0970`, `afc1e988`) y después se exigió coincidencia del remitente (`ae918749`). El envío local no demuestra que direcciones arbitrarias estuvieran autenticadas. No hay evidencia todavía de un bloqueo saliente de Hostinger.

## Configuración privada necesaria

Usar la carpeta hermana de `public_html`, `impe-private/`, o `IMPE_PRIVATE_CONFIG_DIR`. Propietario: usuario PHP/SSH correspondiente; directorio 0700 y archivos 0600. No pegar claves en chats, comandos del historial ni Git. Editar los archivos privadamente. No copiar ejemplos sin sustituir sus valores ficticios.

* **Recuperación Gmail:** conservar `MAIL_*` existentes si ya son válidos. Opcionalmente crear `password_reset_mail.local.php` siguiendo `config/password_reset_mail.local.example.php`; tiene prioridad sólo para recuperación. Gmail: `smtp.gmail.com`, 587/TLS, dirección completa y clave de aplicación habilitada mediante verificación en dos pasos, u OAuth autorizado. No usar la contraseña normal. Se conserva la contraseña temporal y vigencia de 30 minutos del flujo actual; no se rediseñó LoginController.
* **Hostinger de empleados:** conservar `hostinger_mail_config.php`/`HOSTINGER_MAIL_API_TOKEN`. El buzón debe figurar en `GET /api/v1/me` y coincidir con el correo del usuario activo del CRM. No se elige otro buzón como sustituto. El token privado de Marketing sigue limitado a su perfil. También se puede autorizar explícitamente una cuenta mediante el registro siguiente.
* **Gmail comercial y otras cuentas:** crear `mail_accounts.local.php` siguiendo `config/mail_accounts.local.example.php`. Cada dirección tiene transporte, credenciales propias, `activo` e IDs de usuarios autorizados. No hereda credenciales de recuperación. El administrador realiza esta conexión; no se añadió una pantalla ni una tabla de cuentas.
* **Microsoft:** OAuth delegado, `client_id`, secreto cuando la app lo requiera y `refresh_token` ya consentido. Scope `https://outlook.office.com/SMTP.Send` y `offline_access`; SMTP AUTH permitido para el buzón. Microsoft 365: `smtp.office365.com:587/TLS`; Outlook/Hotmail: `smtp-mail.outlook.com:587/TLS`. No se registraron apps ni se obtuvo consentimiento por el usuario.
* **Gmail OAuth:** `oauth_provider=google`, scope `https://mail.google.com/` y autorización previa con refresh token. Renovación HTTPS y XOAUTH2 sin dependencias nuevas.
* **Institucional:** host/puerto/TLS y credenciales propios que indique su administrador. Los alias requieren autorización privada explícita y aceptación del proveedor. No se restringe el dominio del destinatario.

El estado OAuth y los controles de reenvío se guardan fuera del sitio, por defecto en `impe-private/mail-state/`, con archivos 0600 y exclusión mediante `flock`. Se admite `IMPE_MAIL_STATE_DIR` fuera de todos los directorios públicos. Esta carpeta contiene tokens: no publicarla, borrarla para reintentar ni compartirla. Un clúster requeriría almacenamiento y bloqueo compartidos; esta implementación corresponde al servidor único actual.

## Cambios y significado de los resultados

`MailService` usa la cuenta dedicada y el transporte SMTP compartido. `CorreoSalidaInstitucionalService` selecciona cuentas autorizadas y usa SMTP/OAuth o Hostinger API. Marketing y los dos despachadores de correo de Vinculación usan ese transporte. Los demás consumidores existentes ya pasaban por él. No se modifican generación de oficios, Telefonía, permisos, tablas ni estilos del modal; sólo su indicación sobre la cuenta conectada.

Se conservan cuerpo, adjuntos e historial existentes. `ACEPTADO` significa aceptación SMTP/API; **no confirma recepción**. `RECHAZADO` permite corregir la cuenta; `DESCONOCIDO` bloquea el reenvío automático. No hay cambio de proveedor después de un POST incierto. Marketing conserva un borrador rechazado y devuelve el mismo registro ante solicitudes repetidas. Si el proveedor acepta pero falla la escritura de historial/adjuntos, hay que conciliar manualmente antes de reenviar. No existe una transacción atómica entre correo y MySQL.

El formulario actualizado identifica cada redacción. Clientes anteriores y otros consumidores sin clave explícita tienen una protección de 60 segundos contra repeticiones idénticas; un resultado desconocido permanece bloqueado. Un nuevo envío intencional o una nueva redacción no acredita que el anterior haya fallado. Los estados técnicos no incluyen el cuerpo del correo. Los logs nuevos sólo contienen etapa, clase, estado y códigos sanitizados.

## Pruebas ejecutadas, sin correo a Internet

| Prueba dirigida | Evidencia local |
|---|---|
| Recuperación | Cuenta independiente, plantilla/vigencia y TLS; envío a SMTP local efímero |
| Gmail/institucional | Selección SMTP y remitente exacto simulados |
| Microsoft/Google OAuth | Endpoints, scopes, renovación/caché y XOAUTH2 simulados |
| Hostinger API | Buzón exacto, contenido/adjuntos, aceptación y rechazo/incertidumbre simulados |
| Destinatarios y adjuntos | SMTP local recibe MIME con dominio externo, acentos, texto y archivo íntegro |
| Seguridad TLS | Certificado no confiable rechazado antes de AUTH/DATA |
| Historial/borradores | Servicio con BD en memoria: guardar/recuperar, propiedad, rechazo y registro único |
| Publicador | Sitio ficticio: check sin escritura, respaldo/restore, CRLF, configuración y cambios locales conservados |

Archivos de prueba: `tests/correos_smtp_test.php`, `correos_cuentas_test.php`, `correos_marketing_test.php`, `correos_smtp_tls_test.py` y `correos_publicador_test.py`. No se ejecutó CI global. Las pruebas con BD en memoria no sustituyen una comprobación del historial/descargas contra MySQL real.

## Hostinger: comprobaciones manuales pendientes

Después de revisar código y configurar privadamente las cuentas, desde la carpeta del CRM:

```bash
php tools/diagnosticar_correo_hostinger.php --recovery --probe
php tools/diagnosticar_correo_hostinger.php --account='CUENTA_CONECTADA' --user=ID_AUTORIZADO --probe
# Para DNS, añadir --domain=DOMINIO --dkim=SELECTOR_REAL
```

El diagnóstico sólo funciona por CLI. `--probe` comprueba DNS, TLS/AUTH SMTP o autorización API; no envía mensajes. OAuth puede renovar su estado privado. Si falla, comprobar CA/OpenSSL, DNS, puerto saliente, política del buzón y restricciones del plan con Hostinger. No desactivar TLS ni habilitar funciones de ejecución de procesos.

Sólo con un destinatario de prueba autorizado, añadir `--send-test --to=DESTINO_AUTORIZADO` para transmitir un mensaje ficticio. No ejecutarlo dos veces si el resultado es desconocido. Este comando no crea historial comercial ni cambia contraseñas. Probar también la recuperación desde la aplicación con una cuenta de prueba propia y verificar login/vigencia. En Marketing y cada perfil autorizado: comprobar borrador, convocatoria, recepción en inbox/spam, asunto/cuerpo, firma, adjuntos descargables e historial. Repetir con Gmail, Hostinger, Microsoft e institucionales únicamente cuando sus cuentas estén conectadas. Revisar cabeceras del mensaje recibido para SPF/DKIM/DMARC y alineación; presencia DNS por sí sola no acredita entrega.

**Todos los envíos y recepciones reales desde Hostinger están pendientes.** No se dispone de sesión SSH, credenciales privadas ni destinatario autorizado. No se afirma que producción esté reparada ni que los archivos hayan llegado a destinatarios reales.

## Publicación manual y reversible

Único publicador de esta entrega: `tools/publicar_correos_multiproveedor_hostinger.sh`, autocontenido, lista cerrada de 14 archivos. Obtenerlo de esta rama revisada fuera de `public_html`. No hacer `git pull` directamente sobre producción. Se conservan credenciales y configuraciones privadas; el parche de `config/mail_config.php` sólo corrige la precedencia de `MAIL_USERNAME`. Una versión incompatible se rechaza sin sobrescribirla.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
bash /RUTA_REVISADA/publicar_correos_multiproveedor_hostinger.sh --check
# Sólo después de revisar OK y autorizar la publicación:
bash /RUTA_REVISADA/publicar_correos_multiproveedor_hostinger.sh --apply
# Restaurar con la ruta EXACTA de respaldo impresa por --apply:
bash /RUTA_REVISADA/publicar_correos_multiproveedor_hostinger.sh --restore /RUTA_EXACTA_RESPALDO
```

No se ejecutó publicación ni fusión. `--restore` comprueba integridad y rechaza pisar ediciones posteriores. Las credenciales, el estado privado de correo y la BD quedan fuera del respaldo de código; restaurar código no deshace mensajes transmitidos. Tras publicar/restaurar, invalidar OPcache según la configuración de PHP web y recargar el navegador.

Fuentes oficiales: [Gmail y claves de aplicación](https://support.google.com/accounts/answer/185833), [XOAUTH2 Gmail](https://developers.google.com/workspace/gmail/imap/xoauth2-protocol), [OAuth SMTP Microsoft](https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth/), [SMTP AUTH Microsoft 365](https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission), [Hostinger Mail API](https://api.mail.hostinger.com/).
