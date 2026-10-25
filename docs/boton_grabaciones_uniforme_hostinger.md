# Diseño uniforme del botón de grabaciones

Las llamadas antiguas y nuevas usan ahora el mismo botón del historial de Ventas: fondo verde, icono de grabación y flecha. La confirmación del cierre y la duración se siguen mostrando por separado. Al consultar, el texto se ajusta al número de grabaciones disponibles y conserva ambos iconos.

Sólo se publica `app/views/telefonia/ventas_llamadas.php`. La consulta, autorización, referencias, índices y reproducción de audio conservan su comportamiento. No se publican servicios, endpoints, JavaScript del teléfono ni cambios SQL. Se parte de la vista incluida en la entrega anterior de grabaciones entrantes, que ya instalaste. Si se detecta otra versión, el instalador se detiene antes de modificar el sitio.

## 1. Descargar y comprobar

Copia el bloque completo en SSH. Guarda el paquete fuera de `public_html` y comprueba su integridad. `--check` no publica archivos ni modifica la base de datos.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-boton-grabaciones-3f322f3a"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/3f322f3a08bf6ec9980d83f27a78e8209296883b/tools"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
for f in publicar_boton_grabaciones.py boton_grabaciones_release.json; do
  curl -fsSL "$BASE/$f" -o "$f"
done
sha256sum -c <<'SHA'
80d5004ff6782d470a54843310dd1714972fa428fc759f0f23b307f14626900e  publicar_boton_grabaciones.py
9869bc49c81aa2874ed2b77996e78a1c6fad7ef6565da0e0d4bb03e4900d6f61  boton_grabaciones_release.json
SHA
python3 publicar_boton_grabaciones.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-boton-grabaciones" --check
)
```

Debe mostrar `CHECK OK: sin escrituras.` Si muestra conflicto, conserva la salida y no apliques el siguiente bloque.

## 2. Aplicar

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
cd "$HOME/impe-boton-grabaciones-3f322f3a"
sha256sum -c <<'SHA'
80d5004ff6782d470a54843310dd1714972fa428fc759f0f23b307f14626900e  publicar_boton_grabaciones.py
9869bc49c81aa2874ed2b77996e78a1c6fad7ef6565da0e0d4bb03e4900d6f61  boton_grabaciones_release.json
SHA
python3 publicar_boton_grabaciones.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-boton-grabaciones" --apply
)
```

Guarda la ruta de `APLICADO. RESPALDO:`. Recarga el historial de Ventas para ver la vista actualizada.

## 3. Comprobar

Abre el historial y compara las dos llamadas recientes del 24/10 con la antigua del 22/10 a las 09:25. Antes de consultar, todas deben tener el mismo fondo, icono de grabación y flecha. Después de consultar dos grabaciones, el texto debe indicar «Consultar 2 grabaciones» manteniendo el diseño. Abre y cierra los reproductores y reproduce unos segundos de ambos audios. El cierre o la duración pendientes de la llamada antigua conservan su estado; no se sustituyen por datos inventados.

## Restaurar

Sustituye `RUTA_DEL_RESPALDO` por la ruta exacta guardada al aplicar. La restauración se detiene si alguien modificó después esa vista.

```bash
python3 "$HOME/impe-boton-grabaciones-3f322f3a/publicar_boton_grabaciones.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-boton-grabaciones" \
  --restore "RUTA_DEL_RESPALDO"
```
