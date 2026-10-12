#!/usr/bin/env bash
# Diagnóstico de conflictos telefónicos, sin escribir en public_html.
set -euo pipefail
SRC="$HOME/crm-preparacion"
DST="$HOME/domains/fundacionrededumex.org/public_html"
if [[ ! -d "$SRC/.git" || ! -f "$DST/index.php" ]]; then
  echo "ERROR: repositorio o CRM público no encontrados." >&2
  exit 2
fi
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
conflictos=0
echo "DIAGNÓSTICO DE FUSIÓN — sólo lectura de Hostinger"
while IFS='|' read -r p base_sha; do
  [[ -n "$p" ]] || continue
  echo
  echo "=== $p ==="
  if [[ ! -f "$SRC/$p" || ! -f "$DST/$p" ]]; then
    echo "FALTA ARCHIVO: requiere revisión manual."
    conflictos=$((conflictos + 1))
    continue
  fi
  if cmp -s "$DST/$p" "$SRC/$p"; then
    echo "IGUAL a GitHub: sin conflicto."
    continue
  fi
  if ! git -C "$SRC" cat-file blob "$base_sha" > "$TMP/base"; then
    echo "ERROR: falta la base Git; se requiere actualizar el repositorio."
    conflictos=$((conflictos + 1))
    continue
  fi
  if git merge-file -p -L HOSTINGER -L BASE -L GITHUB \
      "$DST/$p" "$TMP/base" "$SRC/$p" > "$TMP/merged"; then
    echo "FUSIÓN AUTOMÁTICA posible, sin modificar el archivo real."
    continue
  fi
  num="$(grep -c '^<<<<<<< HOSTINGER' "$TMP/merged" || true)"
  if [[ "$num" == "0" ]]; then
    echo "ERROR: la simulación no terminó correctamente."
    conflictos=$((conflictos + 1))
    continue
  fi
  conflictos=$((conflictos + 1))
  echo "CONFLICTO: $num bloque(s). Fragmento (sin escribir producción):"
  awk '
    /^<<<<<<< HOSTINGER/ {en_bloque=1}
    en_bloque {
      lineas++
      if (lineas <= 180) print
    }
    en_bloque && /^>>>>>>> GITHUB/ {en_bloque=0}
    END {if (lineas > 180) print "... salida limitada a 180 líneas por archivo"}
  ' "$TMP/merged"
done <<'FILES'
public/javascript/telefonia_host.js|1590a267840e6ac5d341254fdb760d4deb04b4b7
public/css/telefonia_shell.css|13cdd5876bedf6313168c53c13666fe602ebe89a
public/javascript/telefonia_shell.js|2352c5aa9a1e2c2cb9329cfba79a785c83b5792e
public/javascript/telefonia_persistente.js|94d7e74fb8a1dca3e5afd45e44db48a46be9dfce
app/views/telefonia/shell.php|763470485ce10bd7a74ec2f938eca3bae0877e31
FILES
echo
echo "Resumen: $conflictos archivo(s) pendientes de revisión."
echo "No se publicó ni alteró ningún archivo de Hostinger."
