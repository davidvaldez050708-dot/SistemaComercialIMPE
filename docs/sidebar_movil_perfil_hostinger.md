# Perfil y cierre de sesión en el menú móvil

El perfil queda fijo al pie del menú móvil; sólo las opciones se desplazan. El panel se ajusta al alto visible del navegador y deja espacio para el área segura del celular. En horizontal se compacta el logotipo. Se conserva el nombre, rol, avatar y comportamiento de salida existentes.

El paquete modifica exclusivamente:

- `app/views/layout/sidebar.php`
- `public/css/global.css`

Las bases corresponden a los hashes de Hostinger `59022525…` y `9669a5ae…`. Incluye las reglas de avatar/perfil ya publicadas. El archivo `responsive_sistema.css`, incluida su personalización, queda intacto. No cambia permisos, sesiones ni base de datos.

## Instalar en SSH

Ejecutar el bloque completo. Primero descarga una entrega inmutable en una carpeta privada y comprueba su integridad. `--check` no escribe en el sitio. `--apply` guarda respaldo privado y sólo acepta las dos versiones verificadas; ante una personalización desconocida se detiene antes de reemplazar archivos.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-sidebar-movil-323c8bd5"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/323c8bd586808ad455574efc9bd4ee2a56c51b51/tools"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/publicar_sidebar_movil_perfil.py" -o publicar_sidebar_movil_perfil.py
curl -fsSL "$BASE/sidebar_movil_perfil_release.json" -o sidebar_movil_perfil_release.json
printf '%s\n' \
  '4a4b6d1a92bd08cff86127fb9d9798f61176a95350eda051418852b7b874f5a3  publicar_sidebar_movil_perfil.py' \
  'c3b29b1ed07243b1714295256992896404adf7979c496b00488dd9933f65d031  sidebar_movil_perfil_release.json' | sha256sum -c -
python3 publicar_sidebar_movil_perfil.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-sidebar-movil" --check
python3 publicar_sidebar_movil_perfil.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-sidebar-movil" --apply
)
```

## Comprobar en el celular

Recargar el CRM y abrir el menú. Confirmar que el avatar, nombre, rol y botón de salida aparezcan abajo desde el inicio y sigan visibles al desplazar las opciones. Girar el celular y repetir. La salida móvil mantiene la ruta original; el escritorio conserva su confirmación.

Pruebas automatizadas: vista PHP real con datos ficticios, Bootstrap 5.3.8, tamaños 320×568, 360×640, 390×844, 430×932, 768×1024, 844×390 y 667×320; alturas cambiantes, nombres largos, controles accesibles, desplazamiento y escritorio 1366×768. Se comprueba además publicación LF/CRLF, conflictos, respaldo, idempotencia y restauración exacta. Estas pruebas no sustituyen la comprobación final en un celular físico.

## Restaurar

Usar la ruta exacta que imprimió `APLICADO. RESPALDO:`:

```bash
python3 "$HOME/impe-sidebar-movil-323c8bd5/publicar_sidebar_movil_perfil.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-sidebar-movil" \
  --restore "/ruta/exacta/del/respaldo"
```

La restauración se bloquea si detecta cambios posteriores; no sobrescribe esas modificaciones.
