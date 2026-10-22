# Correo institucional: salida central transparente (v3)

Rama `codex/hostinger-comercial-central`, [PR #126](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/126). Código/publicador: **0ad7ac860247400e099c1015c63c4b633ae11d65**. Diagnóstico: `hostinger-comercial-central-v3`. Publicador: `hostinger-comercial-v3-f054e1e9af6696d3`.

## Evidencia y diferencia con el comportamiento anterior

En la versión comercial `37b2aa98`, `CorreoSalidaInstitucionalService` elegía el buzón preferido o el primero autorizado por `/api/v1/me`; la dirección escrita no se convertía en From real si no correspondía a ese buzón. Su alternativa SMTP autenticaba una cuenta central, utilizaba esa cuenta en `setFrom` y añadía la dirección de contacto mediante `addReplyTo`. Versiones posteriores cambiaron las reglas. El historial Git prueba ese código, no la configuración ejecutada en cada instalación ni la recepción de todos los mensajes anteriores.

El diagnóstico SSH real más reciente confirmó `mail_central.local.php` legible, token válido y un único buzón utilizable: `d.institucional2@rededucativamexico.org`. No confirma que el token autorice otros From. Podría tratarse de una selección limitada de buzones o de una orden con sólo ese buzón; no se ha inspeccionado la administración de Hostinger.

[Contrato oficial Hostinger Mail API](https://raw.githubusercontent.com/hostinger/mail-api/main/openapi.json), comprobado el 22/10/2026: `/api/v1/me` devuelve `data.mailboxes[].address/resourceId`, sin paginación en ese endpoint. El token está limitado a una orden y puede restringirse a determinados buzones. `POST /api/v1/mailboxes/{resourceId}/send` usa la identidad de ese buzón; `displayName` sólo cambia el nombre visible. No se documentan `From`, `Reply-To` ni cabeceras arbitrarias como parámetros de envío. `inReplyTo` sirve para hilos y no configura la dirección de respuesta.

## Funcionamiento implementado

- Se conserva De y su diseño. Permite escribir una dirección válida de `@rededucativamexico.org`; la lista dinámica ofrece sugerencias, pero no exige pertenencia para redactar o resolver una salida central.
- Si el From solicitado está autorizado por la API y por las restricciones comerciales vigentes, se envía directamente desde ese buzón.
- Si no lo está, se resuelve un buzón de salida permitido: preferencia administrativa existente (`from_address` en la configuración central o `HOSTINGER_MAIL_FROM_ADDRESS`), buzón del usuario si está disponible o primer buzón permitido ordenado. Una preferencia configurada no autorizada se rechaza, sin escoger otra silenciosamente.
- Antes de enviar, el formulario muestra el remitente real, dónde se reciben las respuestas y si el contacto se incluirá. Marketing envía esa identidad confirmada; si cambia o no se confirmó una salida central, el servidor detiene el envío antes del POST.
- El contacto escrito se considera autorizado sólo si corresponde a un buzón permitido o al correo registrado del usuario activo. Escribir una dirección del mismo dominio no acredita propiedad. Si no está verificada, se mantiene en el campo, pero no se comunica al destinatario ni se usa su firma. Se puede enviar desde el central sin atribuirse esa dirección ajena.
- La salida central conserva completo el texto original y agrega una nota con el From real, redactor verificado y contacto institucional autorizado cuando exista. El nombre y la firma no se obtienen de una identidad ajena introducida en el formulario.
- El mismo servicio resuelve Marketing y los llamadores comerciales existentes. No se cambian sus permisos ni se crean configuraciones, credenciales o registros por usuario/buzón.

**Limitación de Reply-To:** no se envía una cabecera personalizada porque la API no ofrece ese campo documentado. Por defecto, Responder utiliza el From real. El contacto institucional autorizado figura en el texto para que el destinatario pueda escribirle directamente. No se promete que Responder vaya a esa otra dirección. Para que el destinatario vea exactamente `superior286@rededucativamexico.org` en From, ese buzón debe existir en la orden y estar autorizado por el token. El código no puede ampliar ese permiso. Un Reply-To distinto requeriría soporte oficial del proveedor; esta entrega no cambia a SMTP ni instala otro transporte.

## Conservación de datos y configuración

Se conservan asunto, texto íntegro, adjuntos, firma autorizada, borradores e historial. Los borradores no reciben notas de envíos inexistentes. Sólo tras HTTP 204 se registra ENVIADO; en salida central se añade al cuerpo del registro la misma nota transmitida al destinatario, conservando `usuario_id` como vínculo con el redactor. Se reutilizan columnas existentes, sin migraciones ni cambios de esquema. Un rechazo conserva el borrador y un resultado incierto no provoca reenvío ni cambio de cuenta.

La configuración privada se conserva: precedencia de valores no vacíos de entorno, archivo central API, constante Mail API y compatibilidad comercial legacy. Los archivos antiguos no se ejecutan si ya existe una credencial prioritaria. El publicador no sobrescribe el token ni ejecuta el configurador. Sólo se utilizan buzones autorizados por el proveedor y permitidos por las restricciones administrativas previas.

**Recuperación protegida:** comparación de 14 archivos de login, recuperación, SMTP, modelo, configuración y llamadores antes y después: idénticos a main. No se ejecutaron recuperaciones ni se modificaron contraseñas, credenciales, Telefonía, oficios/PDF u otros módulos.

## Archivos y pruebas

Cambios de esta corrección: `CorreoHostingerApiService.php` (versión y preferencia central); `CorreoCuentaConectadaService.php` (resolución y contacto autorizado); `CorreoSalidaInstitucionalService.php` (identidad real, confirmación y nota); `CorreoMarketingService.php` (historial); `CorreoMarketingController.php` (consulta de identidad); `correos_marketing.js` (previsualización); diagnóstico comercial; publicador; tres archivos de pruebas y este documento. No cambian vista, CSS, configuración privada ni permisos.

Pruebas dirigidas completadas, sin CI global ni envíos reales nuevos:

- `hostinger_comercial_test.php`: flujo directo existente, adjuntos íntegros, destinatarios Gmail/Outlook/institucionales, restricciones, errores, borrador e historial idempotente (API/BD simuladas).
- `hostinger_buzones_dinamicos_test.php`: descubrimiento, altas/revocaciones, identidad dirección/ID, configuración y permisos previos.
- `hostinger_central_identidad_test.php`: dirección propia/ajena sin registro individual, From central real, ausencia de Reply-To inventado, contacto autorizado, confirmación/cambio de buzón, perfiles, adjuntos, historial con nota única, rechazo que conserva borrador e incertidumbre sin duplicados.
- `hostinger_buzones_interfaz_test.js`: entrada institucional no incluida en sugerencias, salida central visible, selección conservada, configuración ausente y respuestas atrasadas.
- Comparación de archivos protegidos antes/después, PHP lint, sintaxis JS y `git diff --check`.
- Publicador en sitios ficticios de main `a90b6ade` y v2 `63b91494`: `--check` sin escribir, `--apply`/`--restore` exactos, idempotencia y rechazo de personalizaciones/ediciones posteriores; credenciales y recuperación conservadas.

La prueba real anterior por API directa obtuvo HTTP 204 y recepción confirmada en Gmail. No valida esta nueva resolución central ni su historial web. La versión v3 aún no se ha desplegado ni probado con envío real desde producción.

## Publicación selectiva por SSH

SHA-256 real del publicador v3: **438404a0fb1f12d4196a40f3c1565d6a37bb3d3f3afc1bb6be028731185753b8**. No usar publicadores ni hashes anteriores para instalar v3.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/0ad7ac860247400e099c1015c63c4b633ae11d65/tools/publicar_correo_comercial_hostinger.sh" \
  -o "$HOME/impe-publicadores/hostinger-comercial-0ad7ac86.sh" &&
printf '%s  %s\n' \
  '438404a0fb1f12d4196a40f3c1565d6a37bb3d3f3afc1bb6be028731185753b8' \
  "$HOME/impe-publicadores/hostinger-comercial-0ad7ac86.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/hostinger-comercial-0ad7ac86.sh" --check
```

Sólo con hash/check correctos y autorización de publicación:

```bash
bash "$HOME/impe-publicadores/hostinger-comercial-0ad7ac86.sh" --apply
php "$HOME/domains/fundacionrededumex.org/public_html/tools/diagnosticar_hostinger_comercial.php" --check
```

Guardar y usar el comando exacto de restauración que imprime `--apply`, con el mismo script `--restore` y la ruta real del respaldo. No forzar archivos personalizados. La lista cerrada del publicador conserva los diez archivos de integración comercial del PR #126 para admitir instalación inicial y actualización; no incorpora recuperación ni configura credenciales. Publicar sin envíos activos y gestionar OPcache cuando corresponda.

El token ya válido de Hostinger no debe sobrescribirse. Sólo si falta una credencial utilizable, el configurador existente pide el token oculto por SSH y lo valida antes de guardarlo fuera de `public_html`:

```bash
bash "$HOME/domains/fundacionrededumex.org/public_html/tools/configurar_hostinger_comercial.sh"
```

Nunca introducir tokens en GitHub, chat, JavaScript o argumentos visibles. Un token configurado como variable de entorno conserva prioridad y debe actualizarse allí si se rota.

Después de publicar, recargar el CRM con Ctrl+F5. En Correos → Redactar, escribir una dirección institucional y revisar el aviso de salida real bajo De. El aviso debe mostrar el central si la dirección no está autorizada como From, sin pedir registrar cuentas individuales. Comprobar un único envío autorizado con adjunto, su recepción, contacto, From/respuestas reales y registro en historial. Si hace falta que From sea exactamente la dirección escrita, ampliar la autorización del token de esa orden en Hostinger, sin suplantar identidades.

**Sin fusión ni despliegue automático. Validación real de v3 en producción pendiente.**
