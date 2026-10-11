#!/usr/bin/env bash
# Actualización conservadora de Telefonía: avatares, modales, toasts y devolución.
# No cambia BD, claves de Zadarma ni configuraciones de la centralita.
set -euo pipefail
umask 077

SRC="$HOME/crm-preparacion"
DST="$HOME/domains/fundacionrededumex.org/public_html"
[[ -f "$DST/index.php" && -d "$SRC/.git" ]] || {
    echo "ERROR: No se encontró el repositorio o la instalación de producción." >&2
    exit 1
}

FILES=(
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
MARKERS=(
    'data-telephony-flash'
    'data-marketing-reception-callback-status'
    'devolver llamadas perdidas, con extensión saliente habilitada'
    'bootstrap.Toast.getOrCreateInstance'
    'normalizarNumeroDevolucion'
    'El selector heredado .telephony-user span'
    'Recepción · llamada de retorno vinculada'
    'Telefonía · extensiones: limitar específicamente'
)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "1/4 Preparando fusión y comprobando personalizaciones..."
for i in "${!FILES[@]}"; do
    file="${FILES[$i]}"
    srcfile="$SRC/$file"
    dstfile="$DST/$file"
    [[ -f "$srcfile" && -f "$dstfile" ]] || {
        echo "ERROR: falta archivo $file en origen o producción." >&2
        exit 1
    }

    mkdir -p "$TMP/$(dirname "$file")"

    # Los cambios CSS de Telefonía son secciones completas agregadas al final.
    # Fusionar con git merge-file causa conflictos artificiales cuando
    # producción también añadió reglas al final de responsive_sistema.css.
    # Por eso sólo anexamos el bloque NUEVO y verificamos su integridad.
    if [[ "$file" == "public/css/telefonia.css" ||
          "$file" == "public/css/telefonia_recepcion_marketing.css" ||
          "$file" == "public/css/responsive_sistema.css" ]]; then
        php -r '
            $fuente = file_get_contents($argv[1]);
            $produccion = file_get_contents($argv[2]);
            $inicio = $argv[4];
            $pos = strpos($fuente, $inicio);
            if ($pos === false) {
                fwrite(STDERR, "No se encontró la sección CSS prevista.\n");
                exit(2);
            }
            $bloque = substr($fuente, $pos);
            if (strpos($produccion, $inicio) !== false) {
                if (strpos($produccion, $bloque) === false) {
                    fwrite(STDERR, "Producción ya contiene una versión CSS diferente.\n");
                    exit(2);
                }
                $nuevo = $produccion;
            } else {
                $nuevo = rtrim($produccion) . "\n\n" . $bloque;
            }
            if (file_put_contents($argv[3], $nuevo) === false) {
                exit(2);
            }
        ' "$srcfile" "$dstfile" "$TMP/$file" "${MARKERS[$i]}" || {
            echo "ERROR: no se pudo integrar el CSS de $file." >&2
            exit 1
        }
        continue
    fi

    if grep -Fq "${MARKERS[$i]}" "$dstfile"; then
        # Volver a ejecutar no duplica las secciones ya integradas.
        cp "$dstfile" "$TMP/$file"
        continue
    fi

    git -C "$SRC" cat-file blob "${BASES[$i]}" > "$TMP/base-$i" || {
        echo "ERROR: falta la base Git para $file; se canceló el despliegue." >&2
        exit 1
    }
    git merge-file -p "$dstfile" "$TMP/base-$i" "$srcfile" > "$TMP/$file" || {
        echo "CONFLICTO: $file tiene cambios incompatibles en Hostinger." >&2
        echo "No se publicó ningún archivo; necesita revisión manual." >&2
        exit 1
    }
done

echo "2/4 Validando antes de publicar..."
for file in "app/views/telefonia/extensiones.php" \
            "app/views/telefonia/recepcion_marketing.php" "app/models/RolModel.php"; do
    php -l "$TMP/$file"
done
if command -v node >/dev/null 2>&1; then
    node --check "$TMP/public/javascript/telefonia_admin.js"
    node --check "$TMP/public/javascript/telefonia_recepcion_marketing.js"
fi
grep -Fq 'data-telephony-flash' "$TMP/app/views/telefonia/extensiones.php"
grep -Fq 'telefono.startCall({' "$TMP/public/javascript/telefonia_recepcion_marketing.js"
grep -Fq 'bootstrap.Toast.getOrCreateInstance' "$TMP/public/javascript/telefonia_admin.js"
grep -Fq 'telephony-admin-flash-container' "$TMP/public/css/telefonia.css"

echo "3/4 Creando respaldo y publicando..."
mkdir -p "$HOME/respaldos-crm"
BACKUP="$(mktemp -d "$HOME/respaldos-crm/telefonia-ux-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${FILES[@]}"; do
    mkdir -p "$BACKUP/$(dirname "$file")"
    cp -p "$DST/$file" "$BACKUP/$file"
done

for file in "${FILES[@]}"; do
    cp "$TMP/$file" "$DST/$file"
done

echo "4/4 Comprobación final..."
php -l "$DST/app/views/telefonia/extensiones.php"
php -l "$DST/app/views/telefonia/recepcion_marketing.php"
php -l "$DST/app/models/RolModel.php"

echo "OK: interfaz y devoluciones de Telefonía publicadas."
echo "La autorización saliente de Marketing se configura aparte en Roles y permisos."
echo "Respaldo: $BACKUP"
