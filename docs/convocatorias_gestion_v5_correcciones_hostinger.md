# Correcciones institucionales de Gestión V5

Actualización del PR #132 sobre la V5 instalada (`722d3125`). Código: `709347e9`. Cinco archivos operativos: vista `convocatorias/index.php`, `convocatorias_gestion_v5.js`, `crear_convocatoria_v2_extension.js`, `convocatorias_gestion_v5.css` y CSS compartido `convocatorias_aliado_multiprograma_v3.css`.

Los filtros reutilizan las pestañas y contadores institucionales. Se retira Consultar aliado del listado y editor; se muestra como botón institucional al final del detalle. La información del aliado se integra en el mismo contenedor que las filas originales, con idéntica alineación.

Consultar aliado abre Convenios con el ID verificado. Cerrar ese detalle vuelve a Gestión con búsqueda, modalidad, fecha, pestaña y posición conservadas y reabre la convocatoria original. El retorno sólo admite el mismo origen/ruta y `controller=convocatoria&action=index`. Abrir el editor V4 desde Convenios no se interpreta como un cierre solicitado por el usuario.

Editar convocatoria conserva el formulario y `actualizar()` existentes, utilizando las mismas tarjetas, tamaños y filas de programa de Nueva convocatoria. Muestra aliado, procedencia, programa y enlace protegido. Por defecto edita una sola publicación. **Sí, mostrar otras** consulta únicamente publicaciones verificadas del mismo aliado y permite seleccionar otra. Cada publicación se guarda por separado; no hay actualización masiva. Si hay cambios pendientes, se solicita guardarlos o cancelarlos antes de cambiar.

Los seis hashes enviados por SSH coinciden con los bytes de la V5 instalada. Se conserva el index modular personalizado, los componentes de Registro/QR y el backend. La ampliación del CSS compartido mantiene exactamente las reglas de Nueva convocatoria y añade su alcance al editor existente. No hay SQL, migraciones ni cambios de lógica de persistencia.

Validación local: **31 comprobaciones dirigidas por motor** en MySQL 8.0.44 y MariaDB 10.11.14, con navegador real: filtros institucionales, detalle alineado, retorno, publicación seleccionada, otras publicaciones opcionales, protección de cambios pendientes, guardado individual, Registro estable, creación V3 intacta y cinco tamaños (1440/1024/768/390/360). Sin errores JavaScript/PHP. Doce pruebas del publicador cubren instalación, restauración, conflictos, LF/CRLF y personalizaciones.

Capturas sintéticas: [listado escritorio](assets/convocatorias-v5/corregido-listado-1440.png) · [editor escritorio](assets/convocatorias-v5/corregido-editor-1440.png) · [editor móvil](assets/convocatorias-v5/corregido-editor-390.png).

## Comprobar sin publicar

En SSH, introducir el **SHA completo del commit de entrega indicado en la conversación**. No utilizar el commit funcional `709347e9` para descargar el paquete.

```bash
read -r -p "SHA completo del commit de entrega (40 caracteres): " REV &&
[[ "$REV" =~ ^[0-9a-f]{40}$ ]] &&
SITIO="$HOME/domains/fundacionrededumex.org/public_html" &&
PREP="$HOME/impe-convocatorias-v5-ui-$REV" &&
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV" &&
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_convocatorias_gestion_v5.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/publicar_convenios_inline_v4.py" -o "$PREP/publicar_convenios_inline_v4.py" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fL "$RAW/tools/convocatorias_gestion_v5_ui_release.json" -o "$PREP/entrega.json" &&
curl -fL "$RAW/tools/convocatorias_gestion_v5_ui_sha256.txt" -o "$PREP/sha256.txt" &&
(cd "$PREP" && sha256sum -c sha256.txt) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convocatorias-v5-ui" --check
```

Revisar el diff y exigir **REVISIÓN COMPATIBLE**. Un conflicto bloquea la entrega completa; no forzar.

## Aplicar los cinco archivos

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convocatorias-v5-ui" --apply
```

No ejecutar SQL. Conservar la ruta real `Respaldo:`. Recargar Gestión y verificar pestañas, ojo → Consultar aliado → cerrar → volver, y edición de una publicación de prueba. Las demás publicaciones sólo aparecen al pedirlo y no se guardan juntas.

## Restauración opcional

No ejecutar como paso de instalación. Para revertir sólo esta corrección:

```bash
read -r -p "Ruta real del respaldo V5 UI: " RESPALDO &&
[ -n "$RESPALDO" ] &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convocatorias-v5-ui" --restore "$RESPALDO"
```

Se bloquea si existen cambios posteriores en los archivos. Las publicaciones, imágenes, relaciones, programas y borradores V4 permanecen en la BD y almacenamiento. No se desplegó automáticamente.
