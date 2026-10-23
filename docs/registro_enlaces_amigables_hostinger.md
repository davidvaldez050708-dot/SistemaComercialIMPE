# Registro: frase completa y código corto

Formato elegido:

`https://fundacionrededumex.org/registro/el-primer-paso-hacia-tu-meta-comienza-aqui/Ab7K9mP2xQ4Z`

El código del ejemplo es ilustrativo. Cada destino tiene un código aleatorio de 12 caracteres, sensible a mayúsculas, estable y único. Una tabla de equivalencias conserva todos los parámetros de Registro: token del aliado, programa/clasificación, territorio, convocatoria y referencia, cuando existen. No es un acortador de sitios externos ni una redirección arbitraria.

## Comportamiento

- Generadores de Registro general, aliados manuales, Vinculación e internacionales; enlaces de Gestión, detalle, edición, Compartir y QR utilizan el formato amigable.
- Los generadores generales comprimen también los parámetros de procedencia antes de mostrar/copiar o dibujar el QR.
- La ruta pública resuelve internamente al mismo controlador de Registro; la barra del navegador conserva la dirección amigable. Las autorizaciones de programas, estado del aliado, publicación/habilitación, CSRF y guardado siguen en el flujo existente.
- Las ligas largas y QR anteriores siguen funcionando. Los tokens y las URLs históricas almacenadas no se migran masivamente. Editar una publicación reconoce la equivalencia y conserva su valor histórico.
- Inscripción mantiene sus enlaces. Las referencias o aliados diferentes nunca se fusionan por tener el mismo título.
- Antes de instalar la tabla adicional, los generadores conservan las ligas anteriores; el publicador de archivos no ejecuta SQL.

Código funcional: `d7ab9f98e8c32ea14c3681188aa6bd4a7fd521f1`, PR #132. La entrega Hostinger revisa 13 archivos: servicio de equivalencias, entrada pública y su `.htaccess` local, migrador, dos JavaScript, controlador de Formularios, servicio de Aliados, modelo y controlador de Convocatorias, modelo de Aliados, vista internacional y carga del script en dashboard_head. Conserva el index modular y el `.htaccess` raíz; no reemplaza CSS, imágenes ni las personalizaciones internacionales del controlador/modelo. El cambio equivalente del index nativo se versiona para instalaciones no modulares, pero no se publica sobre el index personalizado de Hostinger.

## Pruebas y límite de validación

MySQL 8.0.44 y MariaDB 10.11.14: 23 comprobaciones de servicio por motor y 20 de HTTP/navegador por motor, incluyendo envío real y procedencia persistida, ambos formatos, permisos/CSRF, alias por contexto y Registro deshabilitado. Otras 17 del editor/publicación Cuenta Clave y 27 de recuperación/descarga QR, con PNG decodificado y cinco tamaños. Doce pruebas del publicador cubren variantes, integridad, LF/CRLF, personalizaciones, conflictos, instalación idempotente y reversión conservando las ligas nuevas.

El arranque de Apache local fue rechazado por la revisión automática con «blocked by policy». La prueba HTTP utilizó PHP con el mismo controlador público y un adaptador de ruta. La regla de Apache/LiteSpeed debe comprobarse en el paso siguiente **antes de activar la tabla**. Si la ruta no resuelve al mensaje indicado, no ejecutar la migración; los generadores seguirán usando las ligas anteriores.

## 1. Descargar y revisar, sin instalar

En SSH, introducir el SHA completo del **commit de entrega indicado en la conversación**, que contiene este paquete. No usar el commit funcional para descargar el manifiesto.

```bash
read -r -p "SHA completo del commit de entrega (40 caracteres): " REV &&
[[ "$REV" =~ ^[0-9a-f]{40}$ ]] &&
SITIO="$HOME/domains/fundacionrededumex.org/public_html" &&
PREP="$HOME/impe-registro-amigable-$REV" &&
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV" &&
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_registro_enlaces.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/publicar_convenios_inline_v4.py" -o "$PREP/publicar_convenios_inline_v4.py" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fL "$RAW/tools/registro_enlaces_release.json" -o "$PREP/entrega.json" &&
curl -fL "$RAW/tools/registro_enlaces_sha256.txt" -o "$PREP/sha256.txt" &&
(cd "$PREP" && sha256sum -c sha256.txt) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-registro-amigable" --check
```

Revisar el diff y exigir REVISIÓN COMPATIBLE. Los conflictos bloquean toda la entrega. Se concilian las variantes internacionales de V5 y la versión modular confirmada del generador; no forzar sustituciones completas de archivos personalizados.

## 2. Instalar archivos y comprobar la ruta

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-registro-amigable" --apply
```

Guardar la ruta real `Respaldo:`. Este paso no ejecuta SQL. Antes de activar las ligas, ejecutar esta lectura HTTP:

```bash
ESTADO_RUTA=$(curl -sS --connect-timeout 15 --max-time 30 \
 -o "$PREP/prueba-ruta.html" -w '%{http_code}' \
 'https://fundacionrededumex.org/registro/el-primer-paso-hacia-tu-meta-comienza-aqui/000000000000') &&
{ [ "$ESTADO_RUTA" = 404 ] || [ "$ESTADO_RUTA" = 503 ]; } &&
grep -q '<h1>Registro no disponible</h1>' "$PREP/prueba-ruta.html" &&
echo 'RUTA CONFIRMADA: el código de prueba no identifica a un aliado.'
```

Exigir **RUTA CONFIRMADA**. El 503 antes de instalar la tabla, o 404 por código inexistente, es esperado; una página de login, error genérico del servidor o redirección no confirma la ruta. Si falla, detener aquí y revisar la configuración local de Apache/LiteSpeed, sin sustituir el `.htaccess` raíz.

## 3. Activar mediante migración explícita

Sólo después de confirmar la ruta:

```bash
php "$SITIO/tools/migrar_enlaces_registro_amigables.php" --check
```

Si muestra PENDIENTE, la siguiente orden crea **únicamente** la tabla nueva `registro_enlaces_amigables`; no actualiza tokens, convocatorias, programas ni registros existentes:

```bash
php "$SITIO/tools/migrar_enlaces_registro_amigables.php" --apply &&
php "$SITIO/tools/migrar_enlaces_registro_amigables.php" --check
```

Recargar el CRM. Generar/copiar Registro general y un aliado de prueba: deben mostrar la frase y código corto, sin query. Abrir sin sesión, verificar programas, enviar un registro de prueba y revisar procedencia. Confirmar también una liga/QR antiguos, la descarga del QR nuevo y una edición de convocatoria. No se asignan permisos nuevos.

## Revertir generadores sin romper las ligas compartidas

No ejecutar como paso de instalación:

```bash
read -r -p "Ruta real del respaldo Registro amigable: " RESPALDO &&
[ -n "$RESPALDO" ] &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-registro-amigable" --restore "$RESPALDO"
```

Restaura los generadores, modelos, controladores y vistas previos. **Conserva la entrada pública, su regla local, el servicio de resolución, el migrador y la tabla**, para que las ligas/QR nuevos sigan sirviendo al mismo Registro. No ejecuta SQL, no borra equivalencias y no modifica el respaldo original. Se bloquea si hay archivos modificados después de publicar. No retirar esos cuatro archivos ni la tabla mientras existan ligas nuevas compartidas. No se desplegó automáticamente.
