# Canales del aliado — Etapa 1

Corrección: `bc359ff2ba030a9c024b421808ccec368cd427e4`. Entrega inmutable: `a6d7442179078c5a33a2391d49bf3bdb4a6ae07a`, PR #124.

Sólo se publican `app/views/aliados/partials/registro_gestion.php`, `public/css/aliados_v2_extension.css` y `public/javascript/aliados_registro.js`. El modal manual hereda las clases institucionales de `aliados.css`; ese archivo personalizado y el modal de Vinculación no se reemplazan. No se ejecuta SQL ni se requieren nuevas migraciones para esta etapa. La base de la entrega es la revisión V3 `9d09f6d0`, ya instalada según la comprobación previa de Hostinger.

El publicador existente es compatible con Python 3.6: concilia cambios ajenos, conserva CRLF, valida todos los candidatos antes de escribir y se detiene ante personalizaciones superpuestas. No usar el manifiesto original de todo el PR ni el publicador V3 para esta corrección.

## Descargar y comprobar (no instala)

Ejecutar por SSH, fuera de `public_html`:

```bash
REV=a6d7442179078c5a33a2391d49bf3bdb4a6ae07a
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PREP="$HOME/impe-canales-etapa1-a6d74421"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/canales_aliado_etapa1_release.json" -o "$PREP/entrega.json" &&
(cd "$PREP" && printf '%s\n' \
 '3c0137543f7990fc591aa489d613e4a3d6d6078b2bbd8707f41ebd00e243adbb  publicar.py' \
 'e06c0d0ddfe222e18a14797130654e7d8b48390131d401f8121f4fd14a6f098d  entrega.json' | sha256sum -c -) &&
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-canales" --check
```

Revisar el diff: únicamente presentación del modal manual. Si hay conflicto, detenerse y comparar ese archivo; no forzar ni reemplazarlo por la versión completa de GitHub. La sintaxis PHP se comprueba antes de instalar; JavaScript también cuando Node está disponible.

## Aplicar después de una revisión compatible

```bash
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-canales" --apply
```

Guardar la ruta `Respaldo:` indicada. El mensaje genérico del publicador menciona una migración pendiente; esta entrega sólo contiene presentación y no requiere SQL. Una segunda aplicación debe indicar que la entrega ya está publicada. Recargar el navegador sin caché y comparar ambos modales en escritorio y móvil, incluyendo guardar, editar, cancelar y desactivar; comprobar el teléfono original y las tres opciones independientes.

## Restaurar sólo esta entrega

```bash
RESPALDO="/ruta/exacta/indicada/en/Respaldo"
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-canales" --restore "$RESPALDO"
```

La restauración conserva los datos y verifica que ningún archivo haya sido editado después de instalar. No ejecutar `git pull` sobre el sitio ni copiar carpetas completas.

Validación local: PHP/JS sin errores de sintaxis; 89 comprobaciones Playwright/HTTP en Edge (1440, 1024, 768, 390 y 360 px, Manrope e iconos locales); 65 comprobaciones MariaDB aislada; 4 pruebas de la entrega selectiva, incluyendo CRLF, personalizaciones, conflicto sin escrituras y restauración exacta. No se accedió a producción.
