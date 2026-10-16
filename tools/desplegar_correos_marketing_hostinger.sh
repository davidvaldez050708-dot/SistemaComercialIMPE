#!/usr/bin/env bash
# Despliega el módulo de Correos de Marketing a Hostinger.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_correos_marketing_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_CORREOS_MARKETING_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
    SRC="${IMPE_CORREOS_MARKETING_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ -n "${IMPE_CORREOS_MARKETING_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_CORREOS_MARKETING_PUBLIC_DIR"
else
    CANDIDATES=(
        "$HOME/domains/fundacionrededumex.org/public_html"
        "$HOME/domains/mediumturquoise-starling-552985.hostingersite.com/public_html"
        "$HOME/public_html"
    )

    DST=""
    for candidate in "${CANDIDATES[@]}"; do
        if [[ -f "$candidate/index.php" ]]; then
            DST="$candidate"
            break
        fi
    done
fi

if [[ -z "$DST" || ! -f "$DST/index.php" ]]; then
    echo "ERROR: no se encontró la instalación pública." >&2
    exit 1
fi

FILES=(
    "index.php"
    "app/controllers/CorreoMarketingController.php"
    "app/services/CorreoMarketingService.php"
    "app/services/CorreoSalidaInstitucionalService.php"
    "config/mail_config.php"
    "app/views/correos/index.php"
    "app/views/layout/my_profile_modal.php"
    "app/views/layout/dashboard_footer.php"
    "public/javascript/correos_marketing.js"
    "public/javascript/mi_perfil.js"
    "public/css/correos_marketing.css"
)

REMOVE_FILES=(
    "app/controllers/CuentaCorreoUsuarioController.php"
    "app/services/CuentaCorreoUsuarioService.php"
    "public/javascript/cuenta_correo_perfil.js"
    "database/migrations/2026_10_16_cuentas_correo_usuario.sql"
)

echo "Repositorio fuente : $SRC"
echo "Instalación pública: $DST"
echo

for file in "${FILES[@]}"; do
    if [[ ! -f "$SRC/$file" ]]; then
        echo "ERROR: falta en fuente $file" >&2
        exit 1
    fi
done

if command -v php >/dev/null 2>&1; then
    php -l "$SRC/index.php" >/dev/null
    php -l "$SRC/app/controllers/CorreoMarketingController.php" >/dev/null
    php -l "$SRC/app/services/CorreoMarketingService.php" >/dev/null
    php -l "$SRC/app/services/CorreoSalidaInstitucionalService.php" >/dev/null
    php -l "$SRC/config/mail_config.php" >/dev/null
    php -l "$SRC/app/views/correos/index.php" >/dev/null
    php -l "$SRC/app/views/layout/my_profile_modal.php" >/dev/null
fi

if [[ "$MODE" == "--check" ]]; then
    changes=0

    for file in "${FILES[@]}"; do
        if [[ ! -f "$DST/$file" ]]; then
            echo "NUEVO     $file"
            changes=$((changes + 1))
        elif cmp -s "$SRC/$file" "$DST/$file"; then
            echo "OK        $file"
        else
            echo "CAMBIA    $file"
            changes=$((changes + 1))
        fi
    done

    for file in "${REMOVE_FILES[@]}"; do
        if [[ -f "$DST/$file" ]]; then
            echo "ELIMINAR  $file"
            changes=$((changes + 1))
        else
            echo "OK        $file (ausente)"
        fi
    done

    echo

    if [[ "$changes" -eq 0 ]]; then
        echo "OK: Correos de Marketing ya está actualizado en producción."
    else
        echo "PENDIENTE: $changes cambio(s) requieren publicación."
    fi

    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_CORREOS_MARKETING_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/correos-marketing-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

for file in "${FILES[@]}"; do
    if [[ -f "$DST/$file" ]]; then
        mkdir -p "$BACKUP/$(dirname "$file")"
        cp -p "$DST/$file" "$BACKUP/$file"
    fi

    mkdir -p "$DST/$(dirname "$file")"
    cp "$SRC/$file" "$DST/$file"
    chmod 0644 "$DST/$file"
    echo "PUBLICADO $file"
done

for file in "${REMOVE_FILES[@]}"; do
    if [[ -f "$DST/$file" ]]; then
        mkdir -p "$BACKUP/$(dirname "$file")"
        cp -p "$DST/$file" "$BACKUP/$file"
        rm -f "$DST/$file"
        echo "ELIMINADO $file"
    fi
done

if grep -Fq "Cuenta de correo para envíos" "$DST/app/views/layout/my_profile_modal.php"; then
    echo "ERROR: el bloque SMTP por usuario sigue presente en Mi perfil." >&2
    exit 1
fi

if grep -Fq "CuentaCorreoUsuario" "$DST/index.php"; then
    echo "ERROR: index.php conserva rutas SMTP por usuario." >&2
    exit 1
fi

if grep -Fq "cuenta_correo_perfil.js" "$DST/app/views/layout/dashboard_footer.php"; then
    echo "ERROR: dashboard_footer.php sigue cargando el JS SMTP por usuario." >&2
    exit 1
fi

grep -Fq "data-marketing-mail-convocatoria" "$DST/app/views/correos/index.php"
grep -Fq "data-marketing-mail-from" "$DST/app/views/correos/index.php"
grep -Fq "ConvocatoriaModel" "$DST/app/controllers/CorreoMarketingController.php"
grep -Fq "CONVOCATORIA" "$DST/app/services/CorreoMarketingService.php"
grep -Fq "data-marketing-file-remove" "$DST/public/javascript/correos_marketing.js"
grep -Fq "Modal de correo compacto + selector de convocatoria" "$DST/public/css/correos_marketing.css"

echo
echo "OK: Correos de Marketing actualizado correctamente."
echo "La configuración SMTP por usuario fue retirada de producción."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
