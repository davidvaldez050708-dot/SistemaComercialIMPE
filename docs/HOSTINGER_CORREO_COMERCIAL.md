# Correo institucional Hostinger: autorización central de la orden (v4)

Rama `codex/hostinger-comercial-central`, [PR #126](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/126). Código y publicador: **1375a90a9039e8d56465d0ca62b5fbc7c5a547bb**. Versión comercial `hostinger-comercial-orden-v4`; publicador `hostinger-comercial-v4-71f87891362af22f`.

## Causa comprobada con las dos credenciales

El 22/10/2026 se consultaron ambas APIs reales, sólo mediante GET. Mail API autenticó correctamente y devolvió **1 buzón**. Hostinger API encontró **50 buzones** en la misma orden institucional activa; `superior286@rededucativamexico.org` existe y está activo, pero no está autorizado por el token Mail instalado. De los dos tokens de correo registrados en esa orden, ninguno tenía alcance total. La lista administrativa no permite identificar cuál secreto corresponde al token instalado. No se crearon tokens ni buzones, no se ampliaron permisos y no se enviaron mensajes.

| Credencial | Uso oficial y separación |
| --- | --- |
| `HOSTINGER_MAIL_API_TOKEN` | En `api.mail.hostinger.com`: GET `/api/v1/me` proporciona direcciones e identificadores autorizados, sin paginación. POST `/api/v1/mailboxes/{resourceId}/send` envía desde ese buzón. Un 204 indica aceptación, no recepción confirmada. |
| `HOSTINGER_API_TOKEN` | En `developers.hostinger.com`: consulta órdenes de correo, buzones y alcances de tokens, con paginación. Puede crear un token Mail API para una orden; no se utiliza como credencial de envío. Sólo se usa por CLI/SSH. |

Fuentes oficiales verificadas: [Mail API](https://raw.githubusercontent.com/hostinger/mail-api/main/openapi.json), [órdenes](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/MailOrdersApi.md), [buzones](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/MailMailboxesApi.md) y [tokens de la orden](https://github.com/hostinger/api-php-sdk/blob/main/docs/Api/MailAPITokensApi.md). El contrato de tokens admite `scope.has_all_mailboxes=true` para los buzones actuales y futuros. No ofrece un permiso limitado exclusivamente a enviar: el token tiene también las operaciones de lectura y gestión disponibles en Mail API sobre esos buzones. Requiere autorización administrativa consciente; no se ejecuta al publicar ni desde el CRM web.

## Implementación mínima

Se conserva la resolución del PR #126: prioridad al From exacto autorizado por Mail API y los permisos comerciales previos. Las direcciones se descubren nuevamente desde `/me`, sin listas fijas ni registros individuales. La mera existencia administrativa o coincidencia de dominio no autoriza un From. Si no está autorizado, se conserva la salida central explícita de v3, con confirmación del remitente real y contacto verificado. No se inventan encabezados From/Reply-To.

Se añaden `HostingerCorreoAdministracionService.php`, `hostinger_orden_correo.php` y `configurar_hostinger_administracion.sh`. El servicio Mail incorpora la identificación de la orden y la versión v4; se actualizan el publicador, exclusiones de Git y pruebas. No cambia el modal ni el flujo comercial. Se conservan destinatarios, adjuntos, firmas, borradores, historial y restricciones de usuario/rol. No hay migraciones, dependencias nuevas ni cambios en otros módulos.

La herramienta administrativa valida la orden institucional activa, reserva un pendiente antes del POST y guarda el secreto devuelto una sola vez fuera de `public_html`, con permisos 0600. Una respuesta incierta bloquea nuevas creaciones hasta revisar Hostinger. La activación exige que el token pertenezca a la misma orden y cubra todos sus buzones activos con los identificadores correctos. Respalda la configuración Mail anterior y conserva sus políticas. No revoca ni modifica tokens previos.

## Publicación selectiva por SSH

No se ha desplegado. El publicador sólo copia código de una lista cerrada de 13 archivos comerciales; no configura credenciales, amplía permisos ni ejecuta envíos. Rechaza código personalizado incompatible. Ejecutar sin envíos activos; gestionar OPcache si el alojamiento lo requiere.

Descargar y comprobar la revisión exacta:

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/1375a90a9039e8d56465d0ca62b5fbc7c5a547bb/tools/publicar_correo_comercial_hostinger.sh" \
  -o "$HOME/impe-publicadores/hostinger-comercial-1375a90a.sh" &&
printf '%s  %s\n' \
  '6d0e7e219a2d42c9d0f38beb21e65720aa69383788392cd785b1fd415d28be8e' \
  "$HOME/impe-publicadores/hostinger-comercial-1375a90a.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/hostinger-comercial-1375a90a.sh" --check
```

Sólo después de obtener hash y revisión compatibles, y decidir publicar:

```bash
bash "$HOME/impe-publicadores/hostinger-comercial-1375a90a.sh" --apply
```

Guardar el comando **Rollback** exacto impreso por `--apply`: utiliza ese mismo script con `--restore` y la ruta real del respaldo. No copiar una ruta ficticia. La restauración conserva las configuraciones privadas y rechaza ediciones posteriores de código.

## Configuración central por SSH

Los configuradores piden entradas ocultas y validan las credenciales mediante lectura antes de escribir. Conservan una credencial existente válida. Nunca introducir tokens en el chat, argumentos, historial, GitHub o JavaScript. Los archivos se guardan en `domains/fundacionrededumex.org/impe-private/`: `mail_central.local.php` para Mail y `hostinger_admin.local.php` para administración; puede utilizarse un directorio privado explícito mediante `IMPE_PRIVATE_CONFIG_DIR`.

```bash
bash "$IMPE_CORREOS_PUBLIC/tools/configurar_hostinger_comercial.sh"
bash "$IMPE_CORREOS_PUBLIC/tools/configurar_hostinger_administracion.sh"
php "$IMPE_CORREOS_PUBLIC/tools/hostinger_orden_correo.php" --plan
```

El primer configurador conserva el token Mail válido; el segundo solicita el token de hPanel. El plan sólo consulta y muestra cantidades/orden, sin secretos. Si confirma la limitación observada, el siguiente paso **crea un token nuevo con acceso a todos los buzones actuales y futuros, incluidas lectura y gestión**. Ejecutarlo únicamente después de aprobar ese alcance. No crea buzones ni envía mensajes:

```bash
php "$IMPE_CORREOS_PUBLIC/tools/hostinger_orden_correo.php" --preparar-token-orden --confirmar-dominio=rededucativamexico.org
```

Sólo si termina correctamente, validar y activar el pendiente:

```bash
php "$IMPE_CORREOS_PUBLIC/tools/hostinger_orden_correo.php" --activar-pendiente
php "$IMPE_CORREOS_PUBLIC/tools/diagnosticar_hostinger_comercial.php" --check --remitente=superior286@rededucativamexico.org
```

La activación imprime el comando `cp -p` exacto para restaurar la configuración comercial anterior. Es independiente del rollback del código. Si aparece `PENDIENTE_EXISTENTE` o un error de POST, no borrar el pendiente ni repetir a ciegas: revisar los tokens en Hostinger. Si `HOSTINGER_MAIL_API_TOKEN` está definido en entorno, la activación se detiene para evitar guardar una credencial que quede oculta por esa precedencia. Las restricciones comerciales anteriores no se eliminan; el diagnóstico informa si existen filtros locales.

## Validación completada y pendiente

- **Real:** autenticación de ambos tokens, orden coincidente, 1 frente a 50 buzones, existencia/estado del buzón solicitado y dos tokens con alcance parcial. Se utilizaron credenciales cifradas temporalmente en la computadora; no se copiaron al servidor.
- **Simulada:** paginación, separación de tokens y hosts, confirmación explícita, creación única, POST incierto sin reintento, rechazo de orden incorrecta o alcance incompleto, activación con respaldo exacto y conservación de restricciones. Pruebas dirigidas existentes de correo, adjuntos, borradores, historial, altas/revocaciones e interfaz: correctas.
- **Publicador:** sitios ficticios de main `a90b6ade` y v3 `0ad7ac86`: check sin escritura, apply/restore exactos, idempotencia, conservación de configuración privada/CRLF/comentarios y rechazo de personalizaciones o ediciones posteriores.
- **Recuperación intacta:** comparación dirigida de 14 archivos protegidos contra `origin/main`, idénticos antes y después. Sin pruebas de restablecimiento real, cambios de SMTP/login/credenciales, Telefonía u otros módulos. Sin CI global.
- **Pendiente en Hostinger/CRM:** publicar, configurar administración, aprobar/crear/activar el token de alcance completo y comprobar el From exacto en Redactar. Después, una prueba real expresamente autorizada con adjunto a una cuenta propia, verificar recepción y cabeceras, historial y borrador. No se ha probado recepción ni envío real con el token nuevo; todavía no existe. Las altas futuras están soportadas por el alcance oficial y por el descubrimiento dinámico probado con datos ficticios; no se creó un buzón real para probarlas.

**PR sin fusionar. Sin despliegue, ampliación de permisos ni envíos automáticos. La aceptación final desde el CRM de producción sigue pendiente.**
