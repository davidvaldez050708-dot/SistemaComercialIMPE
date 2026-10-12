#!/usr/bin/env bash
# Ajuste incremental del modal QR de Formularios en Hostinger.
# --check: sólo inspecciona.
# --apply: respalda e integra únicamente el bloque CSS del modal QR.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/reparar_modal_qr_formularios_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_QR_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/public/css/formularios.css" ]]; then
    SRC="${IMPE_QR_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ ! -f "$SRC/public/css/formularios.css" ]]; then
    echo "ERROR: no se encontró el repositorio fuente con public/css/formularios.css." >&2
    echo "Puedes indicar la ruta con IMPE_QR_SOURCE_DIR=/ruta/al/repositorio." >&2
    exit 1
fi

if [[ -n "${IMPE_QR_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_QR_PUBLIC_DIR"
else
    CANDIDATES=(
        "$HOME/domains/fundacionrededumex.org/public_html"
        "$HOME/domains/mediumturquoise-starling-552985.hostingersite.com/public_html"
        "$HOME/public_html"
    )
    DST=""
    for candidate in "${CANDIDATES[@]}"; do
        if [[ -f "$candidate/index.php" && -f "$candidate/public/css/formularios.css" ]]; then
            DST="$candidate"
            break
        fi
    done
fi

if [[ -z "$DST" || ! -f "$DST/index.php" || ! -f "$DST/public/css/formularios.css" ]]; then
    echo "ERROR: no se encontró la instalación pública del sistema." >&2
    echo "Indícala con IMPE_QR_PUBLIC_DIR=/ruta/public_html." >&2
    exit 1
fi

CSS="public/css/formularios.css"
SRC_CSS="$SRC/$CSS"
LIVE_CSS="$DST/$CSS"
MARKER="/* ======================================================================"
TITLE="FORMULARIOS · MODAL QR COMPACTO"
BACKUP_ROOT="${IMPE_QR_BACKUP_DIR:-$HOME/respaldos-crm}"

extract_block() {
    php -r '
        $css = file_get_contents($argv[1]);
        $title = $argv[2];
        $titlePos = strpos($css, $title);
        if ($titlePos === false) {
            fwrite(STDERR, "No se encontró el bloque QR compacto en el CSS fuente.\n");
            exit(2);
        }

        $start = strrpos(substr($css, 0, $titlePos), "/* ======================================================================");
        if ($start === false) {
            fwrite(STDERR, "No se encontró el inicio del bloque QR compacto.\n");
            exit(2);
        }

        $next = strpos($css, "/* ======================================================================", $titlePos + strlen($title));
        $block = $next === false
            ? rtrim(substr($css, $start)) . "\n"
            : rtrim(substr($css, $start, $next - $start)) . "\n";

        echo $block;
    ' "$1" "$TITLE"
}

SOURCE_BLOCK="$(extract_block "$SRC_CSS")"

echo "Repositorio fuente : $SRC"
echo "Instalación pública: $DST"
echo "Archivo objetivo   : $CSS"
echo

if [[ "$MODE" == "--check" ]]; then
    echo "Comprobando estado actual del modal QR..."

    if grep -Fq "$TITLE" "$LIVE_CSS"; then
        echo "OK: producción ya contiene el bloque '$TITLE'."
        if LIVE_BLOCK="$(extract_block "$LIVE_CSS" 2>/dev/null)" && [[ "$LIVE_BLOCK" == "$SOURCE_BLOCK" ]]; then
            echo "OK: el bloque publicado coincide con la versión del repositorio."
        else
            echo "AVISO: producción contiene otra versión del bloque."
            echo "Con --apply se sustituirá sólo ese bloque, preservando el resto del CSS."
        fi
    else
        echo "PENDIENTE: producción todavía no contiene el bloque compacto del modal QR."
    fi

    echo
    echo "Objetivo del ajuste:"
    echo "  - ancho máximo del modal: 420px"
    echo "  - QR: 238px"
    echo "  - centrado vertical/horizontal estándar de Bootstrap"
    echo "  - versión móvil: hasta 380px de modal y QR de 215px"
    echo
    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/modal-qr-formularios-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
mkdir -p "$BACKUP/$(dirname "$CSS")"
cp -p "$LIVE_CSS" "$BACKUP/$CSS"

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

php -r '
    $live = file_get_contents($argv[1]);
    $block = file_get_contents($argv[2]);
    $title = $argv[3];

    $titlePos = strpos($live, $title);

    if ($titlePos === false) {
        $nuevo = rtrim($live) . "\n\n" . trim($block) . "\n";
    } else {
        $start = strrpos(
            substr($live, 0, $titlePos),
            "/* ======================================================================"
        );

        if ($start === false) {
            fwrite(STDERR, "No se pudo localizar el inicio del bloque existente.\n");
            exit(2);
        }

        $next = strpos(
            $live,
            "/* ======================================================================",
            $titlePos + strlen($title)
        );

        if ($next === false) {
            $nuevo = rtrim(substr($live, 0, $start)) .
                "\n\n" . trim($block) . "\n";
        } else {
            $nuevo = rtrim(substr($live, 0, $start)) .
                "\n\n" . trim($block) . "\n\n" .
                ltrim(substr($live, $next));
        }
    }

    if (file_put_contents($argv[4], $nuevo) === false) {
        fwrite(STDERR, "No fue posible preparar el CSS actualizado.\n");
        exit(2);
    }
' "$LIVE_CSS" <(printf '%s\n' "$SOURCE_BLOCK") "$TITLE" "$TMP"

grep -Fq "$TITLE" "$TMP" || {
    echo "ERROR: el CSS preparado no contiene el bloque QR compacto." >&2
    exit 1
}

cp "$TMP" "$LIVE_CSS"
chmod 0644 "$LIVE_CSS"

grep -Fq "$TITLE" "$LIVE_CSS"
grep -Fq "max-width: 420px !important;" "$LIVE_CSS"
grep -Fq "width: 238px;" "$LIVE_CSS"

echo "OK: modal QR reducido y publicado."
echo "Respaldo: $BACKUP"
echo "Recarga el sistema con Ctrl+F5 para descartar caché del navegador."
