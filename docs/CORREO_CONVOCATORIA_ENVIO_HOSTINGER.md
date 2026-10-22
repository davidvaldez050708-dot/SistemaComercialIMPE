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

Pendiente de aceptación en Hostinger: aplicar, revisar diagnóstico, entrar al CRM, Ctrl+F5 y comprobar historial/proveedor de la solicitud anterior antes de crear una redacción nueva. Realizar **una sola prueba**, con autorización expresa y destinatario propio, verificar recepción, imagen visible y un único registro en historial. Una respuesta incierta no autoriza repetir. La autorización de esa prueba está registrada en la conversación; aún no acredita envío ni recepción. El navegador accesible inicialmente sólo mostraba login. No declarar terminado en producción únicamente por estas pruebas simuladas.
