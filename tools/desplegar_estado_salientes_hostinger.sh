#!/usr/bin/env bash
# Actualiza ÚNICAMENTE la consulta del estado de llamadas salientes.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == --check || "$MODE" == --apply ]] || { echo "Usa --check o --apply" >&2; exit 2; }
SRC="${IMPE_ESTADO_SALIENTES_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_ESTADO_SALIENTES_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_ESTADO_SALIENTES_BACKUP:-$HOME/respaldos-crm}"
FILE='prueba_telefonia/api/zadarma_estado_llamada.php'
BASE='3ac9f43133852adca9e873e2d5b1794d201db205'
[[ -d "$SRC/.git" && -f "$SRC/$FILE" && -f "$LIVE/$FILE" && ! -L "$LIVE/$FILE" ]] || {
 echo "ERROR: instalación o endpoint no disponible." >&2; exit 1
}
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
echo "1/4 Preparando solo el endpoint saliente..."
git -C "$SRC" show "$BASE:$FILE" > "$TMP/base" || { echo "ERROR: falta versión base." >&2; exit 1; }
if cmp -s "$LIVE/$FILE" "$SRC/$FILE" || cmp -s "$LIVE/$FILE" "$TMP/base"; then
 cp "$SRC/$FILE" "$TMP/nuevo"
elif git merge-file -p -L HOSTINGER -L BASE -L GITHUB \
 "$LIVE/$FILE" "$TMP/base" "$SRC/$FILE" > "$TMP/nuevo"; then
 echo "Personalización compatible de Hostinger conservada."
else
 echo "CONFLICTO: endpoint modificado de forma incompatible; sin publicar." >&2; exit 1
fi
echo "2/4 Validando seguridad y sintaxis..."
php -l "$TMP/nuevo"
for marker in "tienePermiso('telefonia.usar')" \
 "tienePermiso('telefonia.salientes')" \
 "empty(\$asignacionTelefonica['permite_salientes'])" \
 "resolverParaUsuario(\$usuarioId)" "ultimoInicioSalienteId(\$extension)" \
 "buscarInicioSalienteReciente("; do
 grep -Fq "$marker" "$TMP/nuevo" || {
   echo "ERROR: falta guardia obligatoria del endpoint." >&2; exit 1;
 }
done
if grep -Fq "['Analista de Datos', 'Asesor de Ventas']" "$TMP/nuevo" ||
 grep -Fq 'Tu perfil no tiene acceso al estado telefónico.' "$TMP/nuevo" ||
 grep -Eq '^(<<<<<<<|=======|>>>>>>>)' "$TMP/nuevo"; then
 echo "CONFLICTO: sigue la restricción por rol o una fusión incompleta." >&2; exit 1
fi
if cmp -s "$LIVE/$FILE" "$TMP/nuevo"; then
 echo "OK: endpoint ya actualizado; nada que publicar."; exit 0
fi
if [[ "$MODE" == --check ]]; then
 echo "OK: comprobación previa sin modificar producción."
 echo "Pendiente: $FILE"
 exit 0
fi
echo "3/4 Respaldando archivo original..."
mkdir -p "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/estado-salientes-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
mkdir -p "$BACKUP/$(dirname "$FILE")"
cp -p "$LIVE/$FILE" "$BACKUP/$FILE"
echo "4/4 Publicando únicamente endpoint del estado saliente..."
cp "$TMP/nuevo" "$LIVE/$FILE"
cmp -s "$TMP/nuevo" "$LIVE/$FILE" || {
 echo "ERROR: publicación incompleta. Respaldo: $BACKUP" >&2; exit 1
}
echo "OK: salientes autorizadas por permiso y extensión; WebRTC y entrantes intactos."
echo "Respaldo: $BACKUP"
