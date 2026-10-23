# Convenios V4: creación y borradores recuperables

Rama `feat/convenios-convocatorias-inline-v4`, basada en `5f7a0570` de `feat/aliados-programas-registro`. Código funcional: `a29c46e3`, garantías de imágenes: `d5cd6733`, aislamiento de aperturas tardías: `5b5f6964`. La unificación de Bachillerato General ya instalada no forma parte de esta migración.

## Funcionamiento

Convenios → Ver detalle → Crear convocatoria abre el editor V3 existente en la misma página. El componente modular productivo sigue administrando Registro y QR. Las tarjetas y validaciones V3 se comparten con Gestión de Convocatorias; sólo el contenedor de Convenios activa la adaptación de borradores.

Los campos incompletos se guardan automáticamente tras 450 ms sin escritura. Las imágenes se suben inmediatamente en solicitudes separadas. Cerrar o Cancelar espera a que terminen los cambios; si fallan, el editor permanece abierto, muestra el error y permite reintentar. Una copia provisional de campos de texto se limita al propietario y borrador en ese navegador; no contiene tokens ni imágenes. Se sincroniza al reconectar y no sobrescribe una versión nueva del servidor.

Cada borrador pertenece a un usuario y aliado reales, con territorio, versión y estado. Cada programa conserva código canónico, campos, completitud, imagen y datos de última modificación. El servidor revalida permisos y territorio al recuperar, guardar, subir imágenes y publicar. Una segunda pestaña con una versión antigua recibe 409; puede conservar sus campos o cargar explícitamente la versión del servidor.

Publicar sigue utilizando `ConvocatoriaController::guardarProgramasAliado()`. Recupera los datos e imágenes del servidor y crea el lote en la transacción existente. El borrador pasa a publicado y conserva sus IDs en esa misma conexión/transacción. Repetir la petición devuelve esos IDs; un fallo revierte el lote y conserva el borrador. El navegador permanece en Convenios y actualiza el estado del aliado.

## Almacenamiento y retención

La migración añade únicamente:

- `convocatoria_borradores`: propietario, aliado, territorio, versión, campos por código, estado, fechas y resultado confirmado.
- `convocatoria_borrador_imagenes`: referencia aleatoria, SHA256, nombre, MIME, tamaño y autor, por borrador/programa.
- `convocatoria_borrador_operaciones`: respuestas de operaciones idempotentes.

No modifica tablas históricas. Los borradores no son registros de `convocatorias` y no alteran sus conteos. Las imágenes aceptan JPEG, PNG y WebP reales hasta 5 MB, con verificación de MIME, dimensiones y hash. Se guardan fuera de `public_html` en:

`$HOME/domains/fundacionrededumex.org/impe-private/convocatorias-borradores`

Puede configurarse otra ruta privada con `IMPE_CONVOCATORIA_DRAFT_DIR`. Las vistas previas requieren sesión, propietario y autorización territorial; conocer una URL no concede acceso. No se rellena el input file al recuperar una imagen. La publicación copia la imagen definitiva antes de confirmar el lote.

No hay vencimiento ni limpieza por antigüedad. Sólo reemplazar una imagen o confirmar Descartar retira sus archivos privados asociados. Los borradores publicados y sus referencias se conservan. Una futura política de retención requiere una decisión explícita.

## Compatibilidad verificada

La entrega modifica 11 archivos operativos y conserva cinco dependencias comprobadas por SHA256. Incluye las cuatro variantes productivas facilitadas por el usuario y reconstruidas con sus hashes exactos: los dos controladores, el modelo y la vista de Convenios. Mantiene los métodos internacionales y el callback del modelo. El componente modular, sus funciones de Registro/QR y los dos scripts del directorio no se reemplazan. Una dependencia distinta o una conciliación ambigua bloquea la instalación completa.

Validación local en MySQL **8.0.44** y MariaDB **10.11.14**, con bases sintéticas:

- 17 comprobaciones DB de propietarios, permisos, territorio, campos incompletos, versiones e idempotencia.
- 52 comprobaciones de navegador/HTTP/SQL sobre la réplica de las variantes productivas, con cierre real del navegador, imágenes físicas y privadas, JPEG/PNG/WebP, límites, CSRF, reconexión, dos pestañas, rollback y respuestas de publicación perdidas.
- Tres comprobaciones adicionales de cierre fallido, filtros y cambio de territorio.
- Tres comprobaciones de respuestas tardías: cancelar una apertura y elegir otro aliado no mezcla editores ni programas.
- Diez comprobaciones visuales en 1440, 1024, 768, 390 y 360 px, sin doble modal ni desbordamiento.
- 12 comprobaciones del CLI aditivo y su restauración, con históricos intactos.
- 118 regresiones del editor V3 normal y 16 comprobaciones de clasificación General por motor.
- Once pruebas del publicador, variantes LF/CRLF, personalizaciones, integridad, conflictos y restauración.

El workflow `convenios-inline-v4.yml` permite reproducir la validación dirigida. No se desplegó ni ejecutó SQL de producción desde Codex.

## Descargar y comprobar sin instalar

Usar el **SHA del commit de entrega indicado en la conversación**, no el de aplicación anterior. El bloque exige escribirlo, para evitar descargar otra revisión:

```bash
read -r -p "SHA completo del commit de entrega (40 caracteres): " REV &&
[[ "$REV" =~ ^[0-9a-f]{40}$ ]] &&
SITIO="$HOME/domains/fundacionrededumex.org/public_html" &&
PREP="$HOME/impe-convenios-inline-v4-$REV" &&
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV" &&
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_convenios_inline_v4.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fL "$RAW/tools/convenios_inline_v4_release.json" -o "$PREP/entrega.json" &&
curl -fL "$RAW/tools/convenios_inline_v4_sha256.txt" -o "$PREP/sha256.txt" &&
(cd "$PREP" && sha256sum -c sha256.txt) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convenios-v4" --check
```

Se exige **REVISIÓN COMPATIBLE**. Un conflicto se revisa; no se fuerza ni se sustituye el archivo completo.

## Instalar archivos y revisar la migración

Sólo después de comprobar el diff compatible:

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convenios-v4" --apply &&
php "$SITIO/tools/migrar_convocatoria_borradores_v4.php" --check
```

Conservar la ruta `Respaldo:`. El publicador no instala SQL. Hasta instalar las tablas, los nuevos endpoints informan que falta la migración; no crean estructuras silenciosamente.

## Instalar las tres tablas, de forma explícita

Este bloque sí ejecuta la migración aditiva:

```bash
RESPALDOS_SQL="$HOME/respaldos-impe-convenios-v4-sql"
mkdir -p -m 700 "$RESPALDOS_SQL" &&
mkdir -p -m 700 "$HOME/domains/fundacionrededumex.org/impe-private/convocatorias-borradores" &&
php "$SITIO/tools/migrar_convocatoria_borradores_v4.php" --apply --backup-dir="$RESPALDOS_SQL" &&
php "$SITIO/tools/migrar_convocatoria_borradores_v4.php" --check
```

Se espera `OK: persistencia de borradores V4 instalada`. Recargar Convenios. Completar un programa y su imagen, dejar otro pendiente, cerrar y reabrir; comprobar luego con Cuenta Clave de ese territorio. Terminar el segundo programa y publicar: deben aparecer ambas publicaciones en Gestión, conservando el enlace, mientras la pantalla sigue en Convenios.

## Restauración opcional

**No ejecutar como paso de instalación.** Para volver al código anterior, usar la ruta real de respaldo de archivos emitida por el publicador:

```bash
read -r -p "Ruta real del respaldo de archivos: " RESPALDO_ARCHIVOS &&
[ -n "$RESPALDO_ARCHIVOS" ] &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convenios-v4" --restore "$RESPALDO_ARCHIVOS"
```

Las tablas y las imágenes privadas se conservan, para poder recuperar borradores cuando se reinstale V4. Si las tablas nuevas siguen completamente vacías y se desea retirarlas, el CLI permite restaurar su diario de estructura:

```bash
read -r -p "Ruta real del JSON de respaldo SQL: " RESPALDO_SQL &&
[ -n "$RESPALDO_SQL" ] &&
php "$SITIO/tools/migrar_convocatoria_borradores_v4.php" --restore="$RESPALDO_SQL"
```

Se bloquea si hay datos o cambios posteriores de estructura. Nunca vaciar tablas ni borrar imágenes para forzar esa restauración.
