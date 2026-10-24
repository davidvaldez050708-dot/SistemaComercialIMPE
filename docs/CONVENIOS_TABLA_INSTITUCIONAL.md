# Convenios: tabla institucional y acciones por fila

Cada municipio muestra una tabla con encabezados compartidos: Aliado, Contacto, Programas ofrecidos, Origen, Registro y Acciones. Las columnas mantienen el mismo ancho en todos los municipios; en móvil la tabla se desplaza dentro de su contenedor.

El ojo abre el detalle de sólo lectura. Crear convocatoria abre directamente el editor multiprograma, con el permiso existente. Cerrar el editor vuelve al listado, conserva los filtros y el borrador y devuelve el foco al botón de origen. Cerrar el detalle abierto desde Gestión sigue volviendo a Gestión.

La entrega modifica cinco archivos de interfaz, conserva íntegro el personalizador QR comunicado por el usuario y utiliza las cuatro bases Hostinger contrastadas por SHA256. No incluye controladores, modelos, SQL ni Prospectos. No instala componentes de Aliados internacionales.

## Descargar y comprobar en SSH

```bash
REV=105bf9a03acaf43447820a76a16d20b0d787478c
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PREP="$HOME/impe-convenios-tabla-105bf9a0"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"

mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/convenios_tabla_release.json" -o "$PREP/entrega.json" &&
(cd "$PREP" && printf '%s\n' \
 'da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar.py' \
 'b50a08c9362a6b99e8fc383aa33dee5bcd5d77800a52abe752b151275af4f886  entrega.json' | sha256sum -c -) &&
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convenios-tabla" --check
```

`--check` muestra el diff sin modificar el sitio. Deben aparecer cinco destinos preparados y `REVISIÓN COMPATIBLE`. Si aparece un conflicto, el sitio queda intacto y hay que revisar el diff del archivo indicado.

## Aplicar después de revisar

```bash
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convenios-tabla" --apply
```

El publicador respalda los archivos originales antes de cambiarlos. Anota la ruta real que imprime en `Respaldo:`. La operación es idempotente y no ejecuta SQL.

## Verificar

1. Recargar Convenios con Ctrl+F5, seleccionar un estado con dos municipios y comprobar que cada tabla tiene seis encabezados y las mismas columnas.
2. Probar búsqueda, municipio y origen combinados. Cada contador debe reflejar las filas visibles; los municipios sin resultados deben ocultarse.
3. Abrir el ojo en un aliado manual y uno de Vinculación: datos, notas y programas completos; sólo Cerrar como acción del pie.
4. Desde la fila pulsar Crear convocatoria, modificar un título, cerrar y reabrir. Debe recuperarse el borrador y conservarse el filtro del listado al cerrar.
5. Generar QR: el personalizador instalado debe seguir disponible con el mismo enlace del aliado.
6. Con un usuario sin permiso para crear, comprobar que sólo aparece el ojo. Abrir un aliado desde Gestión y cerrar su detalle: debe volver a la misma Gestión.

## Restaurar sólo si se necesita deshacer la entrega

Usa la ruta completa real que imprimió el publicador. No ejecutes el siguiente bloque con el texto de ejemplo sin sustituirlo:

```bash
RESPALDO="/ruta/real/impresa/en/Respaldo"
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convenios-tabla" --restore "$RESPALDO"
```

La restauración comprueba hashes y se detiene si detecta ediciones posteriores. No cambia datos ni borradores de la base de datos.

## Validación de la entrega

- Navegador sobre la vista PHP y los componentes V3/V4 reales: 82 comprobaciones en seis tamaños, tanto nativa como sobre las variantes Hostinger confirmadas. Estas comprobaciones simulan el transporte del borrador.
- Diez pruebas de publicación selectiva: check sin escrituras, apply, idempotencia, restauración, CRLF, personalizaciones, conflicto sin escrituras, QR intacto, protección de cambios posteriores y hashes de descarga contrastados contra el commit publicado.
- CI aislada con MySQL 8 y MariaDB 10.11: 52 comprobaciones por motor con navegador/HTTP/SQL reales y otras tres de desconexión, cierre y recuperación. Incluyen autoguardado, imágenes, permisos, rollback y publicación sin duplicados.

La entrega no se ha aplicado a producción desde Codex.
