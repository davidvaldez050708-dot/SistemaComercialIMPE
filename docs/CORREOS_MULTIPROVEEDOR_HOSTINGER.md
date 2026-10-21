# Correo institucional automático y recuperación

Rama `codex/correo-institucional-automatico`, desde `origin/main` a90b6ade. Sin fusión ni despliegue automático.

Los empleados registrados con Hostinger utilizan su propio buzón mediante la API compartida existente, sin conectar cuentas individualmente. Un empleado registrado con Gmail puede enviar con su Gmail real mediante SMTP y una clave de aplicación propia configurada privadamente, o mediante autorización Google. La recuperación utiliza una cuenta dedicada independiente; el modal público no demuestra envío.

## Causa comprobada y activación privada en Hostinger

El diagnóstico recibido de producción devuelve `MAIL_RESET_IDENTITY` y `MAIL_CENTRAL_CONFIG` antes de intentar SMTP/API. Por tanto, PHP y PHPMailer están disponibles, pero el proceso CLI no encuentra una identidad de recuperación ni una configuración comercial utilizables. No demuestra un bloqueo de red ni un rechazo del proveedor. El usuario conserva la clave de aplicación de recuperación y el token específico de Mail API; deben guardarse en la carpeta privada del servidor, no en Git.

Tras publicar esta revisión, ejecutar en SSH (entrada oculta de claves; no pegarlas en el chat):

```bash
bash "$HOME/domains/fundacionrededumex.org/public_html/tools/configurar_correo_hostinger.sh" --recovery sistemaredimpe@gmail.com
bash "$HOME/domains/fundacionrededumex.org/public_html/tools/configurar_correo_hostinger.sh" --hostinger
```

El primer comando verifica TLS/AUTH y crea `password_reset_mail.local.php`; el segundo comprueba GET /me con el token específico de Hostinger Email y crea `mail_central.local.php`. Si se rechaza la autenticación, no sustituye ninguna configuración existente. Guarda un respaldo privado exacto antes de reemplazar un archivo y muestra el comando para restaurarlo. Escritura atómica y permisos 0600 fuera de public_html, sin herramientas de ejecución PHP bloqueadas, sin envíos ni cambios de BD. El publicador de código no ejecuta esta configuración automáticamente.

En el segundo comando se puede indicar el correo **registrado en el CRM** del usuario autorizado a elegir los buzones de ese token (por ejemplo, Marketing registrado con Gmail que utiliza De de Hostinger). Sólo se asocian los buzones comprobados en GET /me; no cambia el usuario, su rol ni sus permisos de módulos. Enter conserva el comportamiento de sólo el buzón propio. También puede darse ese correo como segundo argumento de `--hostinger`. No se permite a otros usuarios utilizar esa asociación.

Para un empleado Gmail sin aplicación OAuth, usar la clave de aplicación de **ese Gmail**:

```bash
bash "$HOME/domains/fundacionrededumex.org/public_html/tools/configurar_correo_hostinger.sh" --account correo.del.empleado@gmail.com
```

La dirección de ese último ejemplo debe sustituirse por la registrada realmente. El configurador comprueba el usuario activo mediante lectura de BD, autentica SMTP y guarda la autorización en `mail_accounts.local.php`; no cambia su registro ni comparte la clave de recuperación. Conserva las demás cuentas y autorizaciones previas. También admite otros dominios con su SMTP autenticado. Nunca permite enviar desde una dirección sólo por escribirla. Si Google no permite claves de aplicación para esa cuenta, utilizar OAuth; [requisitos oficiales de Google](https://support.google.com/accounts/answer/185833?hl=es).

## Configuración privada única

Carpeta Hostinger: `$HOME/domains/fundacionrededumex.org/impe-private/`, fuera de public_html. Directorio 0700 y PHP privados 0600, accesibles al proceso PHP. No subir secretos a Git ni pegarlos en el chat.

- **Hostinger existente:** si no existe `mail_central.local.php`, se reutiliza el token privado Hostinger actual (de `marketing_mail.local.php` o `hostinger_mail_config.php`/entorno). No hace falta elegir un remitente central para los empleados cuyo correo registrado esté entre los buzones autorizados por GET /me. Se consulta la identidad al preparar y se verifica el buzón exacto antes de POST. No hay fallback después de transmitir.
- **Configuración opcional:** `mail_central.local.php`, siguiendo `config/mail_central.local.example.php`. `transport=hostinger_api`, `correo=''` permite selección automática del buzón registrado. Una salida institucional alternativa sólo se admite cuando está autorizada y se muestra antes de enviar. Nunca se escoge al azar entre varios buzones. Un archivo presente inválido detiene el envío.
- **SMTP institucional compatible:** sin token Hostinger ni archivo central, se reutiliza SMTP de `marketing_mail.local.php`. La configuración SMTP requiere `correo`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_username`, `smtp_password`; TLS 587 o SSL 465 según proveedor. No reutiliza las credenciales de recuperación.
- **Respuestas API:** el esquema Hostinger no incluye Reply-To. Para el buzón propio Hostinger, las respuestas llegan naturalmente al mismo empleado. Cuando contacto y From difieren, la interfaz muestra la dirección real de respuesta y la limitación. Opcionalmente `reply_smtp` permite seleccionar SMTP de la misma cuenta antes de transmitir; nunca se inventa un encabezado API.
- **Recuperación:** `password_reset_mail.local.php`, ejemplo homónimo en config, o los MAIL_* privados/entorno ya existentes. Gmail usa smtp.gmail.com:587/TLS y su clave de aplicación permitida. Si falta MAIL_HOST y el usuario es Gmail se selecciona Gmail; todo host explícito se conserva. Esta cuenta no depende de OAuth de empleados ni de mail_central.
- Opcionales existentes: `IMPE_PRIVATE_CONFIG_DIR` y `IMPE_MAIL_STATE_DIR` (fuera de todos los directorios públicos). Sin variables nuevas obligatorias si se usan los archivos privados estándar.

## Comportamiento conservado

El formulario mantiene campos, estilos, borradores, convocatorias y adjuntos. Se elimina la leyenda solicitada y se muestra el remitente visible con la dirección real de respuestas. Se comprueban al abrir/editar y antes de enviar; una confirmación firmada de sesión impide cambios silenciosos de identidad. Si ésta cambia, se pide revisar y pulsar Enviar. Las cuentas SMTP previamente autorizadas en `mail_accounts.local.php` tienen precedencia; una entrada desactivada/ajena detiene el envío y nunca se elude con otro transporte. Sólo el Gmail registrado sin SMTP ni consentimiento muestra «Autorizar mi Gmail», en otra pestaña para conservar la redacción; al volver se comprueba de nuevo. Hostinger no requiere esa pantalla. Los archivos privados anteriores no se borran.

From es el buzón autenticado correspondiente: Hostinger propio, Gmail autorizado o salida institucional configurada y visible. Un alias SMTP sólo se usa si está autorizado por administración y asociado al usuario. Reply-To sólo usa el correo registrado o contactos adicionales autorizados. Un contacto ajeno no permite suplantar identidad ni usar la firma de otra persona. El usuario real sigue registrado en usuario_id. No hay filtros por dominio del destinatario.

## Gmail de empleados: autorización Google

No se crea ninguna cuenta ni aplicación en este trabajo. El administrador debe habilitar Gmail API, crear un cliente OAuth **Web**, configurar consentimiento y guardar `mail_oauth.local.php` privado usando el ejemplo homónimo en config. URI exacta: `https://fundacionrededumex.org/index.php?controller=correoConexion&action=callback`. Claves fuera de Git y public_html.

El empleado con permiso de envío abre `index.php?controller=correoConexion&action=index` (Marketing también ofrece el enlace al comprobar De), autoriza su Gmail registrado y vuelve al correo. Sólo Google está habilitado; no se añade Microsoft. Se comprueban sesión, permisos, CSRF, estado de un solo uso, PKCE, caducidad, aplicación y coincidencia de correo verificado con Google. No basta con escribir una dirección: debe coincidir con el registro activo. Tokens guardados privadamente por usuario/cuenta; no se devuelven al navegador. La desconexión retira los tokens locales; el empleado puede revocar también en Google.

Se solicita `openid email https://www.googleapis.com/auth/gmail.send`: identidad y envío, sin lectura/eliminación de mensajes. Se genera MIME con PHPMailer y se envía por HTTPS Gmail, manteniendo imágenes, firma y adjuntos; nunca se usa Gmail de recuperación ni un remitente Hostinger como sustitución del Gmail elegido. Si falta configuración/consentimiento o se revoca el permiso, se detiene el envío con un mensaje claro.

Google clasifica gmail.send como sensible: revisar [verificación para producción](https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification) y [scopes Gmail](https://developers.google.com/workspace/gmail/api/auth/scopes). Con aplicación externa en Testing, los refresh tokens para Gmail vencen en siete días; no debe presentarse ese modo como autorización permanente ([documentación OAuth](https://developers.google.com/identity/protocols/oauth2/)). No se contrata servicio, registra aplicación ni desactiva verificación TLS automáticamente.

El historial conserva la BD y añade evidencia privada por usuario/registro: pendiente, en proceso, aceptado, fallido o incierto, con From/Reply-To reales. Los históricos no se reescriben. Aceptación SMTP/API no demuestra recepción. La idempotencia conserva un único registro y evita reenvíos inciertos; límites de 60 intentos comerciales por usuario/hora. No modifica permisos, tablas comerciales, vendor, Telefonía, oficios/PDF ni otros procesos.

Recuperación: contraseña aleatoria criptográfica, sólo hash en usuarios, correo exclusivo al registro activo y vigencia de 30 minutos; el acceso temporal conserva el cambio obligatorio. Límites de tres solicitudes por cuenta/identificador por hora y diez por IP en 15 minutos, con claves técnicas hash. La respuesta pública y su modal no revelan si la cuenta existe. Sólo tras aceptación se actualiza la contraseña con comparación exacta del hash anterior; un cambio concurrente no se sobrescribe. Un rechazo no cambia la contraseña. La incertidumbre impide repetir durante 30 minutos; una nueva solicitud posterior está sujeta a límites. No hay tarea automática que reenvíe.

SMTP y MySQL no comparten transacción: si se acepta el mensaje pero falla/compite el guardado, se conserva la contraseña vigente y la temporal enviada no queda activa; se registra el fallo sin contraseñas y se requiere nueva solicitud/conciliación. No se promete entrega sólo por aceptación.

## Comprobación en Hostinger pendiente

El usuario dispone de ambas claves, pero no se han recibido ni guardado desde este equipo; no se dispone de acceso SSH autenticado. **Cero envíos desde Hostinger y cero entregas reales confirmadas por el agente.** Falta ingresar las claves privadamente con los comandos anteriores, comprobar autenticación y recepción real con cabeceras SPF/DKIM/DMARC. No existe una aplicación Google configurada: Gmail de empleados puede usar primero SMTP propio, o esperar la configuración OAuth y su consentimiento real.

```bash
php "$HOME/domains/fundacionrededumex.org/public_html/tools/diagnosticar_correo_hostinger.php" --central --probe
php "$HOME/domains/fundacionrededumex.org/public_html/tools/diagnosticar_correo_hostinger.php" --recovery --probe
```

Siempre incluir php; ejecutar directamente el archivo da Permission denied y no comprueba SMTP. Probe no envía mensajes. Para central API hace GET /me; para SMTP verifica TLS/AUTH. Los códigos seguros identifican configuración ausente sin mostrar claves. No modificar disable_functions ni desactivar TLS.

Después de configurar y publicar con autorización: probar Marketing hacia cuentas de prueba Gmail/Outlook/institucionales, From propio de distintos empleados Hostinger, Gmail tras consentimiento, adjunto, borrador y consulta de historial desde cada perfil permitido. Probar recuperación con cuenta registrada de prueba, recibir la temporal, acceder, cambiarla y confirmar que las anteriores ya no sirven. Simular fallo SMTP y comprobar que el hash vigente no cambie. Verificar inbox/spam/cabeceras: ni un modal ni SMTP 250 acreditan entrega.

## Pruebas dirigidas y publicación

Pruebas locales/simuladas: selección del buzón propio Hostinger y transporte SMTP/API; autorización Google, PKCE/propiedad/replay/caducidad; Gmail HTTPS con MIME y adjuntos reales; identidad/Reply-To/alias y rechazo/incertidumbre/idempotencia; Marketing con BD en memoria; recuperación con hash/vigencia/límites/rechazo/BD/concurrencia; límites HTTP y vistas de login/recuperación intactas. SMTP real únicamente contra un servidor efímero local con TLS y certificado no confiable rechazado. No sustituyen las pruebas anteriores de producción. Sin CI global.

Único publicador: tools/publicar_correos_multiproveedor_hostinger.sh, lista cerrada de 27 archivos de correo/recuperación. Admite la revisión ya instalada e16cff33 y las versiones revisadas a90b6ade, 69d65f41, d5928cd0 e instalación anterior fd5fd282. Conserva CRLF y comentarios PHP compatibles mediante comprobación del código; versiones o personalizaciones de código desconocidas requieren revisión y dejan el sitio intacto. No sobrescribe credenciales ni BD. Instala el configurador CLI privado y la pantalla OAuth Google opcional; no configura claves, conecta cuentas ni envía mensajes al aplicar. Los respaldos de configuración privada y de código son independientes: restore del publicador no elimina credenciales.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
bash "$HOME/impe-publicadores/publicar_correos_multiproveedor_hostinger.sh" --check
# Sólo tras revisar OK y autorizar publicación:
bash "$HOME/impe-publicadores/publicar_correos_multiproveedor_hostinger.sh" --apply
```

Descargar la revisión/hash fijados en el PR antes de ejecutar. Guardar el comando Rollback exacto que imprime apply. Restore rechaza pisar ediciones posteriores y restaura sólo el código de esa entrega; no deshace correos ni cambia credenciales/BD. Publicar sin envíos activos, gestionar OPcache del PHP web y recargar el navegador.

Fuente primaria: [esquema oficial Hostinger Mail API](https://raw.githubusercontent.com/hostinger/mail-api/main/openapi.json), V1.Send.Request (sin Reply-To).
