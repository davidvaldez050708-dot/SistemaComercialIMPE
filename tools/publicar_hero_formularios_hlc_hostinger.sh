#!/usr/bin/env bash
set -euo pipefail
MODE="${1:-}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Uso: bash $0 --check|--apply" >&2; exit 2; }
DOMAIN="$HOME/domains/fundacionrededumex.org/public_html"
GITHUB="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/main"
CSS="$DOMAIN/public/css/formularios_hero_institucional.css"
LOGO="$DOMAIN/public/img/brand/logo-huston.png"
REG="$DOMAIN/app/views/formularios/publico_registro.php"
INS="$DOMAIN/app/views/formularios/publico_inscripcion.php"
for file in "$REG" "$INS"; do [[ -f "$file" ]] || { echo "ERROR: Archivo ausente: $file" >&2; exit 1; }; done
command -v curl >/dev/null || { echo "ERROR: curl no disponible" >&2; exit 1; }
command -v python3 >/dev/null || { echo "ERROR: python3 no disponible" >&2; exit 1; }
echo "Verificando que logo-huston.png existe en main..."
curl -fsSL "$GITHUB/public/img/brand/logo-huston.png" -o /dev/null || {
 echo "ERROR: Primero sube logo-huston.png a la rama main de GitHub." >&2; exit 1;
}
if [[ "$MODE" == "--apply" ]]; then
  STAMP="$(date +%Y%m%d-%H%M%S)"
  BACKUP="$HOME/respaldo_hero_formularios_hlc_$STAMP"
  mkdir -p "$BACKUP"
  cp -p "$REG" "$BACKUP/publico_registro.php"
  cp -p "$INS" "$BACKUP/publico_inscripcion.php"
fi
python3 - "$MODE" "$REG" "$INS" <<'PY'
from pathlib import Path
import sys
mode = sys.argv[1]
files = [Path(sys.argv[2]), Path(sys.argv[3])]
css_anchor = 'href="<?= BASE_URL ?>public/css/formularios_publico.css?v=<?= filemtime(ROOT_PATH . \'/public/css/formularios_publico.css\') ?>">'
css_new = '    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/formularios_hero_institucional.css?v=<?= filemtime(ROOT_PATH . \'/public/css/formularios_hero_institucional.css\') ?>">'
sep = '''                <div class="formulario-publico-hero-logo-sep">
                    <img
                        src="<?= BASE_URL ?>public/img/brand/Logo-Sep-Blanco.png"
                        alt="Secretaría de Educación Pública"
                        loading="eager">
                </div>'''
new = '''                <span class="formulario-publico-hero-logo-separator" aria-hidden="true"></span>
''' + sep + '''
                <span class="formulario-publico-hero-logo-separator" aria-hidden="true"></span>
                <div class="formulario-publico-hero-acuerdo">Acuerdo<br>286</div>
                <span class="formulario-publico-hero-logo-separator" aria-hidden="true"></span>
                <div class="formulario-publico-hero-logo formulario-publico-hero-logo-hlc">
                    <img
                        src="<?= BASE_URL ?>public/img/brand/logo-huston.png"
                        alt="HLC Handy Language Center"
                        loading="eager">
                </div>'''
changes = []
for p in files:
    s = p.read_text(encoding="utf-8")
    if css_new not in s and s.count(css_anchor) != 1:
        sys.exit("ERROR: Hoja de estilos no encontrada en " + str(p))
    if 'formulario-publico-hero-logo-hlc' not in s and s.count(sep) != 1:
        sys.exit("ERROR: Bloque SEP incompatible en " + str(p))
    if css_new not in s:
        s = s.replace(css_anchor, css_anchor+"\n"+css_new, 1)
    if 'formulario-publico-hero-logo-hlc' not in s:
        s = s.replace(sep, new, 1)
    changes.append((p,s))
print("OK: Los dos formularios son compatibles. Se conservarán todos los campos y validaciones.")
if mode == "--apply":
    for p,s in changes:
        p.write_text(s,encoding="utf-8")
PY
if [[ "$MODE" == "--check" ]]; then
  echo "OK: Logo disponible en GitHub. Listo para publicar exclusivamente el encabezado."
  exit 0
fi
# Se conserva respaldo anterior a los cambios de ambos PHP.
# También se protegen recursos estáticos previos si existían.
[[ ! -f "$CSS" ]] || cp -p "$CSS" "$BACKUP/formularios_hero_institucional.css"
[[ ! -f "$LOGO" ]] || cp -p "$LOGO" "$BACKUP/logo-huston.png"
mkdir -p "$(dirname "$CSS")" "$(dirname "$LOGO")"
curl -fsSL "$GITHUB/public/css/formularios_hero_institucional.css" -o "$CSS.tmp"
curl -fsSL "$GITHUB/public/img/brand/logo-huston.png" -o "$LOGO.tmp"
mv "$CSS.tmp" "$CSS"
mv "$LOGO.tmp" "$LOGO"
php -l "$REG"
php -l "$INS"
echo "OK: Encabezado institucional publicado en producción. Recursos respaldados en $BACKUP"
