# Cuenta Clave: generar enlaces y publicar convocatorias

Corrección puntual del PR #132. Código: `f5d27bfaef26233d848ba0afb151a7b33052ba60`. Dos archivos operativos: `app/services/AliadoRegistroService.php` y `app/controllers/ConvocatoriaController.php`. El editor, CSS, QR y modelos permanecen instalados como están.

## Causa comprobada

`AliadoRegistroService::enlace()` comprobaba primero `formularios_aliados.gestionar_enlaces` y después rechazaba cualquier rol diferente de Marketing con «Operación exclusiva de Marketing». Ese mensaje indica que la petición había pasado la comprobación del permiso. El código contradecía el acceso de Cuenta Clave al editor y a la publicación desde Convenios.

Ahora Marketing y Cuenta Clave pueden gestionar el enlace con ese permiso. Marketing conserva su alcance nacional; Cuenta Clave debe tener una asignación CUENTA_CLAVE activa, vigente y con estado activo para el territorio real del aliado, manual o de Vinculación. Se mantiene la protección CSRF/POST, el catálogo autorizado y la restricción operativa de Administrador. Las rutas de guardado individual y multiprograma utilizan la misma política de permisos y el servicio territorial; se retiran sus comprobaciones redundantes de Marketing.

Generar vuelve a obtener el token existente; no lo rota, no sustituye programas y no habilita automáticamente un Registro deshabilitado. Con el mismo permiso se puede habilitar/deshabilitar explícitamente. Los vínculos y las convocatorias publicadas anteriormente por Marketing se conservan.

## Permisos en Administrador

Para el flujo completo de Cuenta Clave:

- **Ver Convenios** (`convenios.ver`) o **Gestionar Convenios** (`convenios.gestionar`).
- **Ver convocatorias** (`convocatorias.ver`).
- **Crear convocatorias** (`convocatorias.crear`).
- En **Formularios**, **Enlaces de Registro por aliado** (`formularios_aliados.gestionar_enlaces`).
- Asignación territorial vigente de Cuenta Clave para el estado del aliado.

No es necesario convertirlo en Marketing. `aliados.gestionar_programas` se necesita para cambiar la oferta del aliado, no para reutilizar sus programas ya autorizados. Si cambias permisos, cerrar sesión y volver a entrar actualiza los permisos cargados en sesión. Esta entrega no otorga permisos automáticamente ni modifica la base de datos de producción.

## Validación

MySQL 8.0.44 y MariaDB 10.11.14: **21 comprobaciones de servicio por motor**, incluyendo enlace creado por Marketing, recuperación repetida, manual/institucional, primera publicación del enlace, disponibilidad explícita, permiso ausente, asignación ajena/inactiva/vencida/futura, rol no autorizado, integridad de tokens y programas. **17 comprobaciones HTTP/navegador por motor**, con el botón real Generar enlace, QR, autoguardado y publicación de dos convocatorias como Cuenta Clave, además del guardado individual, CSRF y POST. Otras 14 comprobaciones de política sin SQL. Doce pruebas del publicador cubren personalizaciones, variante internacional, LF/CRLF, conflicto y restauración.

## Comprobar sin publicar

En SSH, introducir el SHA completo del **commit de entrega indicado en la conversación** (el commit funcional `f5d27bfa` no incluye el paquete):

```bash
read -r -p "SHA completo del commit de entrega (40 caracteres): " REV &&
[[ "$REV" =~ ^[0-9a-f]{40}$ ]] &&
SITIO="$HOME/domains/fundacionrededumex.org/public_html" &&
PREP="$HOME/impe-enlaces-cuenta-clave-$REV" &&
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV" &&
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_convocatorias_gestion_v5.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/publicar_convenios_inline_v4.py" -o "$PREP/publicar_convenios_inline_v4.py" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fL "$RAW/tools/aliado_enlaces_cuenta_clave_release.json" -o "$PREP/entrega.json" &&
curl -fL "$RAW/tools/aliado_enlaces_cuenta_clave_sha256.txt" -o "$PREP/sha256.txt" &&
(cd "$PREP" && sha256sum -c sha256.txt) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-enlaces-cuenta-clave" --check
```

Revisar el diff y exigir **REVISIÓN COMPATIBLE: 2 archivos**. Incluye la variante internacional del controlador confirmada por SSH en V5. Conserva personalizaciones compatibles y bloquea la entrega completa ante conflictos; no forzar una sustitución completa.

## Aplicar los dos archivos

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-enlaces-cuenta-clave" --apply
```

No requiere SQL ni migraciones. Con Cuenta Clave, abrir el aliado de un territorio asignado → Nueva convocatoria → Generar enlace. Debe conservar el enlace que creó Marketing y permitir Generar QR. Completar y guardar una convocatoria de prueba; comprobar programa, aliado y enlace. Probar también otro territorio, que debe seguir denegado. Conservar la ruta real `Respaldo:`.

## Restauración opcional

No ejecutar como paso de instalación. Para revertir esta corrección:

```bash
read -r -p "Ruta real del respaldo de enlaces Cuenta Clave: " RESPALDO &&
[ -n "$RESPALDO" ] &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-enlaces-cuenta-clave" --restore "$RESPALDO"
```

Se bloquea la restauración si hay modificaciones posteriores. Las convocatorias guardadas siguen en la BD. No se despliega automáticamente.
