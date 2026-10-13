#!/usr/bin/env bash
# Simula la corrección selectiva e idempotente, sin servicios externos.
set -euo pipefail
cd "$(dirname "$0")/.."
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
cat > "$TMP/antes.css" <<'CSS'
.admin-content .dropdown-menu,
.admin-content .offcanvas,
.admin-content .modal-dialog {
    max-width: 100vw;
}
@media (max-width: 767.98px) {
    .admin-content .modal-dialog:not(.modal-fullscreen-sm-down) {
        margin-right: 12px;
        margin-left: 12px;
        max-width: calc(100vw - 24px);
    }
    /* Evita modales más anchos que el visor (sin ocultar su contenido). */
    .admin-content .modal-dialog:not(.modal-fullscreen-sm-down) {
        width: auto;
        max-width: calc(100vw - 24px);
    }

}
/* CSS privado de producción: conservar exactamente. */
.custom-hostinger { color: #123456; }
CSS
php tools/ajustar_anchos_modales.php "$TMP/antes.css" "$TMP/despues.css"
grep -Fq 'No limitar los diálogos aquí' "$TMP/despues.css"
grep -Fq 'width: calc(100% - 24px);' "$TMP/despues.css"
grep -Fq '.custom-hostinger { color: #123456; }' "$TMP/despues.css"
if grep -Fq 'Evita modales más anchos que el visor' "$TMP/despues.css"; then
  echo "FALLO: quedó el override móvil duplicado" >&2; exit 1
fi
php tools/ajustar_anchos_modales.php "$TMP/despues.css" "$TMP/idempotente.css"
cmp -s "$TMP/despues.css" "$TMP/idempotente.css" || {
  echo "FALLO: el parche no es idempotente" >&2; exit 1
}
sed 's/max-width: 100vw;/max-width: 99vw;/' "$TMP/antes.css" > "$TMP/conflicto.css"
if php tools/ajustar_anchos_modales.php "$TMP/conflicto.css" "$TMP/invalido.css" >/dev/null 2>&1; then
  echo "FALLO: se aceptó CSS desconocido" >&2; exit 1
fi
echo "Modales Hostinger: OK (parche, personalizaciones, idempotencia, conflictos)"
