# Envío con convocatoria: corrección de compatibilidad v4

Código/publicador: `2bd44b1b0d1f01d188d6fdb2a89a9da85d79f988`, rama `codex/correos-filtros-convocatorias`, PR #127 hacia main. Sin despliegue ni merge automáticos.

## Causa demostrada

Se reprodujo con los servicios comerciales v4 de `1375a90a`, que son la versión publicada para Hostinger, y el compositor anterior `57b8e062`: el mismo correo sin convocatoria funciona; con convocatoria devuelve el error general antes de POST y antes de crear historial. `CorreoMarketingService` llama a `sender->prepararSalida()`. El decorador `CorreoConvocatoriaMensaje` heredaba ese método, pero su constructor sólo inicializaba el delegado: `cuentas` del padre quedaba null. Excepción: `Call to a member function resolverSalida() on null`. No se demostró un rechazo del contenido por Hostinger; el caso reproducido ni siquiera llega a la API de envío.

La única modificación funcional es `app/services/CorreoConvocatoriaComposicion.php`: delegar `prepararSalida` y `validarRemitente` a la misma instancia configurada que ya realiza el envío. No inicializa otro transporte ni evita su autorización. Añade trazas de fase/clase/código/estado y una huella corta de solicitud; no registra destinatarios, tokens, cuerpo, archivos ni rutas privadas.

La imagen mantiene exactamente su HTML/CID/MIME/base64 y bytes; no se transforma, duplica ni elimina. Filtros, consultas, controladores, vista, JS, transporte, Hostinger API, firma, borradores, historial, prevención de duplicados, configuración y recuperación permanecen intactos. Un resultado aceptado por el transporte se registra como tal en la traza; si después falla el historial, el servicio existente conserva resultado global incierto y bloquea reenvíos. No se borran bloqueos anteriores.

## Pruebas dirigidas

`tests/correo_convocatoria_hostinger_v4_test.py C:/xampp/php/php.exe --legacy` reproduce el fallo anterior. Sin `--legacy` pasa con la corrección: mismo mensaje simple/con convocatoria, CID y bytes originales una vez, imagen cercana a 5 MB, total mayor de 20 MB rechazado antes de POST, imagen alterada/fuera de límite, sin imagen, adjunto manual, cambio de convocatoria, borrador/propietario, aceptación e historial únicos, rechazo 403, incertidumbre 503 y aceptación con fallo posterior del historial sin reenvío. API/BD simuladas y temporales privados; no prueba recepción real ni límites efectivos del proveedor.

También pasaron `correo_convenios_manuales_test.php` y aislamiento de 24 archivos protegidos contra main; composición/selección previa idéntica excepto delegación/trazas. Publicador probado contra instalaciones ficticias con compositor inicial y actualizado: check sin escritura, apply/restore exactos, idempotencia, CRLF/comentarios y rechazo de personalizaciones incompatibles, conservando los servicios v4 y recuperación. No CI global, correos reales ni cambios de contraseña.

## Publicar y comprobar

Único publicador de esta reparación: un parche del compositor y un diagnóstico CLI. Admite las revisiones conocidas del compositor inicial, filtros v2 y manuales; no cambia las diferencias entre ellas. Rechaza modificaciones incompatibles. No usar los publicadores anteriores sobre esta corrección.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/2bd44b1b0d1f01d188d6fdb2a89a9da85d79f988/tools/publicar_envio_convocatoria_hostinger.sh" \
  -o "$HOME/impe-publicadores/envio-convocatoria-2bd44b1b.sh" &&
printf '%s  %s\n' \
  'bed47e07122a9489f790f633b9f03e0df480a10abc0787749e001feeb069c423' \
  "$HOME/impe-publicadores/envio-convocatoria-2bd44b1b.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/envio-convocatoria-2bd44b1b.sh" --check
```

Sólo tras revisión compatible:

```bash
bash "$HOME/impe-publicadores/envio-convocatoria-2bd44b1b.sh" --apply
php "$IMPE_CORREOS_PUBLIC/tools/diagnosticar_envio_convocatoria.php" --check --convocatoria=11
```

Guardar el comando `--restore` y respaldo exactos impresos por apply. El diagnóstico no hace consultas API ni envíos: verifica la delegación con un doble local y, si se indica convocatoria, hace SELECT y valida el archivo original. Esperar `autorizacion_delegada: true`, convocatoria 11 activa e `imagen_valida: true`. No muestra nombres de contactos, rutas ni credenciales.

Para comprobaciones posteriores: revisar diagnóstico, entrar al CRM, Ctrl+F5 y comprobar historial/proveedor de cualquier solicitud anterior antes de crear una redacción nueva. Una respuesta incierta no autoriza repetir un envío.

## Resultado real en Hostinger — 22/10/2026

El usuario ejecutó check compatible y apply de **dos archivos**, con respaldo reversible. El diagnóstico productivo confirmó delegación correcta, convocatoria 11 activa y PNG válido de **788 316 bytes**, SHA256 `1b326f526856cd4fa307d4a2285d2295024cd80b5b9c1be6daf4f4613175cca0`. No se cambiaron BD ni credenciales.

Con autorización expresa de la conversación y sesión del usuario se creó una redacción nueva desde el CRM, asunto **Prueba convocatoria IMPE 11**, remitente institucional autorizado y destinatario de prueba propio. Guanajuato mostró #155 y manual #1 PRUEBA; al seleccionar el manual aparecieron únicamente las convocatorias 8, 9, 10 y 11. Se eligió la 11 con su imagen original.

El primer intento fue rechazado por validación previa de Hostinger API, sin crear registro ni alcanzar el envío al buzón. Una consulta GET posterior devolvió los 50 buzones y el remitente autorizado. Tras comprobar el rechazo previo y ausencia de registro se volvió a presentar la solicitud: se completó **un solo correo**, sin repetir resultados inciertos. No se modificó el transporte para eludir ese rechazo.

Prueba confirmada desde Gmail mediante el conector de la cuenta receptora: **un mensaje en INBOX**, asunto y texto exactos, From institucional esperado, MIME `multipart/related` con **una imagen PNG inline**, CID `convocatoria-11-1b326f526856cd4fa307` coincidente con el HTML y tamaño 788 316 bytes. Se descargó únicamente esa parte MIME para verificar SHA256: coincide por completo con el original de Hostinger. La recepción está confirmada, no sólo la aceptación de la API.

El CRM devolvió **un resultado** al buscar el asunto; detalle con estado **Enviado**, proveedor **HOSTINGER_MAIL_API**, texto intacto e imagen/selección de convocatoria 11 conservadas. Capturas locales de historial e imagen guardadas en `tmp/`, sin versionar correos ni datos personales del destinatario. Envío/recepción registrados por Gmail a las 20:59:04 UTC. No se enviaron más mensajes ni se probaron contraseñas reales. Adjuntos manuales y límites de tamaño se verificaron con dobles, sin envíos adicionales.
