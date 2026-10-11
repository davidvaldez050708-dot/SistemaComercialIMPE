#!/usr/bin/env bash
# Simula dos instalaciones incrementales, preservando cambios locales.
set -euo pipefail
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/domains/fundacionrededumex.org/public_html"
mkdir -p "$SITE"
touch "$SITE/index.php"
ln -s "$RAIZ" "$TMP/crm-preparacion"

RUTAS=(
    "app/views/telefonia/extensiones.php"
    "app/views/telefonia/recepcion_marketing.php"
    "app/models/RolModel.php"
    "public/javascript/telefonia_admin.js"
    "public/javascript/telefonia_recepcion_marketing.js"
    "public/css/telefonia.css"
    "public/css/telefonia_recepcion_marketing.css"
    "public/css/responsive_sistema.css"
)
BASES=(
    "1abca253797ecd78d99ab5a5464d546ddedf1949"
    "218703782364f4d5964168b2ae83ab0c42964132"
    "eb82b53ca1efaabf5b68cc7386292ef4fcf6215f"
    "770384e48ef486b24ab744c24bfd4f64c1af7231"
    "316c6061a9ebfee1bde60b7249e7d3a465420d1b"
    "59fc1ce5e20decc787693559bfa25cba556f137d"
    "80ca17685d46a11ca1f5bee7008522d183549b5d^:public/css/telefonia_recepcion_marketing.css"
    "ed5555eb221026a09c6192211c739632994ba99f"
)
for i in "${!RUTAS[@]}"; do
    file="${RUTAS[$i]}"
    mkdir -p "$SITE/$(dirname "$file")"
    git -C "$RAIZ" cat-file blob "${BASES[$i]}" > "$SITE/$file"
done

# Simula personalización de producción en un área que NO toca el parche.
echo '/* MARCA-LOCAL-HOSTINGER */' >> "$SITE/public/css/responsive_sistema.css"

HOME="$TMP" bash "$RAIZ/tools/desplegar_telefonia_ux_hostinger.sh"
HOME="$TMP" bash "$RAIZ/tools/desplegar_telefonia_ux_hostinger.sh"

grep -Fq 'MARCA-LOCAL-HOSTINGER' "$SITE/public/css/responsive_sistema.css"
grep -Fq 'data-telephony-flash' "$SITE/app/views/telefonia/extensiones.php"
grep -Fq 'normalizarNumeroDevolucion' "$SITE/public/javascript/telefonia_recepcion_marketing.js"
grep -Fq 'telephony-admin-flash-container' "$SITE/public/css/telefonia.css"
[[ "$(grep -Fc 'Telefonía · extensiones: limitar específicamente' "$SITE/public/css/responsive_sistema.css")" -eq 1 ]]
[[ "$(grep -Fc 'El selector heredado .telephony-user span' "$SITE/public/css/telefonia.css")" -eq 1 ]]
echo 'Despliegue UX Telefonía: OK (repetible y sin perder ajustes locales)'
