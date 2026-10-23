# QR de Registro recuperable en Editar convocatoria

Actualización del PR #132 sobre la corrección UI instalada (`a7b49dfd`). Código: `4ef9cce330b476ad64a1a06bf0eef79c53e15738`. Sólo cambia tres archivos operativos:

- `app/views/convocatorias/index.php`: carga el generador QR local que ya utiliza el CRM.
- `public/javascript/convocatorias_gestion_v5.js`: Ver QR y Descargar PNG junto al enlace de Registro guardado; encabezado de programa con icono y contador institucionales.
- `public/css/convocatorias_gestion_v5.css`: presentación del panel QR y encabezados, reutilizando botones y clases de los grupos mensuales.

El editor anterior sólo mostraba el enlace. Las acciones QR estaban encapsuladas en los componentes de creación, sin acceso desde Editar. Ahora el editor dibuja el QR desde el enlace persistido de la publicación seleccionada con la misma biblioteca local, corrección M, tamaño de 600 × 600, margen y color institucional. No genera tokens, no llama a servicios externos y no altera el enlace. Recupera un PNG nuevo del enlace existente; no conserva posibles personalizaciones de un PNG antiguo. Si se cambia de publicación se limpia el QR previo; sin enlace guardado las acciones quedan deshabilitadas.

Los títulos de programa usaban un `h3` con tamaño y cápsula independientes. Se sustituyen por las clases del encabezado mensual del CRM, manteniendo el título semántico, icono, peso de fuente y contador con texto singular/plural.

Validación local: **27 comprobaciones por motor** en MySQL 8.0.44 y MariaDB 10.11.14 sobre la réplica modular de Hostinger; otras 27 sobre la vista nativa. Se decodificó el QR y el PNG descargado para comprobar igualdad exacta con el enlace guardado, incluyendo aliados manuales y de Vinculación con enlaces distintos. Reapertura, fallo/reintento del generador, enlace ausente y cinco tamaños (1440/1024/768/390/360) comprobados, sin errores JavaScript/PHP ni desbordamientos. No se envían POST ni cambian publicaciones o relaciones al consultar/descargar. Doce pruebas del publicador verifican conciliación, conflictos, LF/CRLF, personalizaciones y restauración.

Capturas con datos sintéticos: [editor escritorio](assets/convocatorias-v5/qr-editor-1440.png) · [editor móvil](assets/convocatorias-v5/qr-editor-390.png) · [encabezados](assets/convocatorias-v5/qr-listado-1440.png).

## Descargar y comprobar sin instalar

Ejecutar en **SSH**. Introducir el SHA completo del **commit de entrega indicado en la conversación**, que contiene este paquete; el commit de código `4ef9cce3` no incluye el manifiesto.

```bash
read -r -p "SHA completo del commit de entrega (40 caracteres): " REV &&
[[ "$REV" =~ ^[0-9a-f]{40}$ ]] &&
SITIO="$HOME/domains/fundacionrededumex.org/public_html" &&
PREP="$HOME/impe-convocatorias-v5-qr-$REV" &&
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV" &&
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_convocatorias_gestion_v5.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/publicar_convenios_inline_v4.py" -o "$PREP/publicar_convenios_inline_v4.py" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fL "$RAW/tools/convocatorias_gestion_v5_qr_release.json" -o "$PREP/entrega.json" &&
curl -fL "$RAW/tools/convocatorias_gestion_v5_qr_sha256.txt" -o "$PREP/sha256.txt" &&
(cd "$PREP" && sha256sum -c sha256.txt) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convocatorias-v5-qr" --check
```

Revisar el diff y exigir **REVISIÓN COMPATIBLE: 3 archivos**. Se concilia el index modular ya confirmado y se conservan las personalizaciones compatibles. Un conflicto bloquea toda la entrega; no forzar. Los componentes de creación/Registro existentes se comprueban y conservan.

## Instalar únicamente esta corrección

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convocatorias-v5-qr" --apply
```

No requiere SQL ni migraciones. Conservar la ruta real `Respaldo:`. Recargar Gestión, editar una convocatoria existente y pulsar Ver QR / Descargar PNG. Escanear el PNG y comprobar que abre el mismo Registro. Cerrar y abrir otra publicación de otro aliado: debe mostrar su enlace y su QR. Comprobar los encabezados de programa en escritorio y móvil. No hace falta guardar una publicación para recuperar el QR.

## Restauración opcional

Sólo para revertir esta corrección, no como paso de instalación:

```bash
read -r -p "Ruta real del respaldo V5 QR: " RESPALDO &&
[ -n "$RESPALDO" ] &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convocatorias-v5-qr" --restore "$RESPALDO"
```

La restauración se bloquea si hay modificaciones posteriores en los archivos. No se despliega automáticamente.
