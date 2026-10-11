#!/usr/bin/env bash
# Recepción Marketing, icono y alternativa WebRTC en pestaña.
# No modifica MySQL, Zadarma ni datos de usuarios; aborta ante conflictos.
set -euo pipefail
umask 077

SRC="${IMPE_RECEPTION_SOURCE_DIR:-$HOME/crm-preparacion}"
DST="${IMPE_RECEPTION_PUBLIC_DIR:-$HOME/domains/fundacionrededumex.org/public_html}"
RESP="${IMPE_RECEPTION_BACKUP_DIR:-$HOME/respaldos-crm}"
FILES=(
    "app/views/dashboard/marketing.php"
    "app/views/telefonia/recepcion_marketing.php"
    "app/views/telefonia/host.php"
    "public/css/telefonia_recepcion_marketing.css"
    "public/css/telefonia_host.css"
    "public/javascript/telefonia_persistente.js"
    "public/javascript/telefonia_host.js"
)
BASES=(
    "d240a9ff42ecb0408cd12ca642084035812866df"
    "5881cebd24d53eb7fb9bd95249319abf203d92b4"
    "0163d8a1485f46ceaad4f82de19d6119035decfa"
    "79a16325fa981c0bc47909d5c1728cc4d82cd871"
    "0ec57dd7f3b763c24bca2cf1b24a1c86ec66ce69"
    "3146adb8b47e317e323a7a908cdac4ce0358ae86"
    "f415905da19a6d4113e6a996202863f3029ff102"
)
MARKERS=(
    "// Si Marketing tiene una extensión de recepción propia y activa,"
    "data-marketing-reception-tab-mode"
    "telephony-host-tab-info"
    "/* Recepción como prioridad operativa:"
    "body.telephony-host-tab {"
    "const getHostMode = function"
    "const marcarPestanaEntrante = function"
)

[[ -d "$SRC/.git" && -f "$DST/index.php" ]] || {
    echo "ERROR: No se encontró el repositorio o la instalación de producción." >&2
    exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Comprobando los cambios y preparando fusión segura..."
for i in "${!FILES[@]}"; do
    file="${FILES[$i]}"
    base="${BASES[$i]}"
    src="$SRC/$file"
    live="$DST/$file"
    dest="$TMP/$file"
    [[ -f "$src" && -f "$live" ]] || {
        echo "ERROR: archivo ausente: $file" >&2
        exit 1
    }
    mkdir -p "$TMP/$(dirname "$file")"
    grep -Fq "${MARKERS[$i]}" "$src" || {
        echo "ERROR: repositorio no actualizado para $file" >&2
        exit 1
    }

    if [[ "$file" == "public/css/telefonia_recepcion_marketing.css" ]]; then
        # Las reglas de esta entrega se añadieron al final: se incorporan sin
        # pisar los ajustes de estilos que ya tiene Hostinger.
        php -r '
            $git = file_get_contents($argv[1]);
            $live = file_get_contents($argv[2]);
            $mark = $argv[4];
            $i = strpos($git, $mark);
            if ($i === false) exit(2);
            $fragment = substr($git, $i);
            if (strpos($live, $mark) !== false) {
                if (strpos($live, $fragment) === false) {
                    fwrite(STDERR, "Conflicto en bloque CSS de recepción.\n");
                    exit(2);
                }
                $out = $live;
            } else {
                $out = rtrim($live) . "\n\n" . $fragment;
            }
            if (file_put_contents($argv[3], $out) === false) exit(2);
        ' "$src" "$live" "$dest" "${MARKERS[$i]}" || {
            echo "ERROR: no se pudo integrar $file; nada publicado." >&2
            exit 1
        }
        continue
    fi

    if grep -Fq "${MARKERS[$i]}" "$live"; then
        if cmp -s "$live" "$src"; then
            cp "$live" "$dest"
        else
            # Si la versión está personalizada no asumir que un marcador
            # significa que contiene TODO el parche.
            git -C "$SRC" cat-file blob "$base" > "$TMP/base-$i" || exit 1
            git merge-file -p "$live" "$TMP/base-$i" "$src" > "$dest" || {
                echo "CONFLICTO: $file tiene cambios incompatibles; nada publicado." >&2
                exit 1
            }
        fi
        continue
    fi

    git -C "$SRC" cat-file blob "$base" > "$TMP/base-$i" || {
        echo "ERROR: Git no contiene versión base de $file." >&2
        exit 1
    }
    git merge-file -p "$live" "$TMP/base-$i" "$src" > "$dest" || {
        echo "CONFLICTO: $file requiere revisión manual. Nada publicado." >&2
        exit 1
    }
done

echo "2/4 Verificando sintaxis e integridad del conjunto..."
for p in app/views/dashboard/marketing.php app/views/telefonia/recepcion_marketing.php app/views/telefonia/host.php; do
    php -l "$TMP/$p"
done
if command -v node >/dev/null 2>&1; then
    node --check "$TMP/public/javascript/telefonia_persistente.js"
    node --check "$TMP/public/javascript/telefonia_host.js"
fi
grep -Fq "data-marketing-reception-tab-mode" "$TMP/app/views/telefonia/recepcion_marketing.php"
grep -Fq "const getHostMode = function" "$TMP/public/javascript/telefonia_persistente.js"
grep -Fq "const marcarPestanaEntrante = function" "$TMP/public/javascript/telefonia_host.js"
grep -Fq "body.telephony-host-tab {" "$TMP/public/css/telefonia_host.css"
grep -Fq "marketing-reception-answered-check" "$TMP/public/css/telefonia_recepcion_marketing.css"

echo "3/4 Respaldando y publicando siete archivos..."
mkdir -p "$RESP"
BACKUP="$(mktemp -d "$RESP/marketing-recepcion-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${FILES[@]}"; do
    mkdir -p "$BACKUP/$(dirname "$file")"
    cp -p "$DST/$file" "$BACKUP/$file"
done
for file in "${FILES[@]}"; do
    cp "$TMP/$file" "$DST/$file"
done

echo "4/4 Verificación final..."
for p in app/views/dashboard/marketing.php app/views/telefonia/recepcion_marketing.php app/views/telefonia/host.php; do
    php -l "$DST/$p"
done
echo "OK: recepción de Marketing subida bajo las métricas, icono y pestaña opcional preparados."
echo "Respaldo: $BACKUP"
echo "Recarga el CRM con Ctrl+F5. El modo WebRTC anterior sigue siendo el predeterminado."
