#!/usr/bin/env bash
set -euo pipefail
MODE="${1:-}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi
TARGET="$HOME/domains/fundacionrededumex.org/public_html/app/views/prospectos/index.php"
[[ -f "$TARGET" ]] || { echo "ERROR: No existe la vista de Prospectos en producción." >&2; exit 1; }
python3 - "$MODE" "$TARGET" <<'PY'
import datetime
import pathlib
import shutil
import subprocess
import sys

mode = sys.argv[1]
path = pathlib.Path(sys.argv[2])
source = path.read_text(encoding="utf-8")
start = "/* IMPE_PROSPECTOS_TIPOGRAFIA_V3_INICIO */"
end = "/* IMPE_PROSPECTOS_TIPOGRAFIA_V3_FIN */"
css = """/* IMPE_PROSPECTOS_TIPOGRAFIA_V3_INICIO */
.prospectos-page .prospectos-table-wrap table,
.prospectos-page .prospectos-table-wrap table th,
.prospectos-page .prospectos-table-wrap table td,
.prospectos-page .prospectos-table-wrap table span,
.prospectos-page .prospectos-table-wrap table a {
    font-family: 'Manrope', sans-serif !important;
    font-size: 12px !important;
    letter-spacing: normal !important;
    line-height: 1.45 !important;
}
.prospectos-page .prospectos-table-wrap table th {font-weight: 800 !important;}
.prospectos-page .prospectos-table-wrap table td {font-weight: 600 !important;}
.prospectos-page .prospectos-table-wrap table td.prospectos-name {font-weight: 800 !important;}
/* IMPE_PROSPECTOS_TIPOGRAFIA_V3_FIN */"""
if source.count("</style>") != 1 or ".prospectos-table-wrap" not in source:
    sys.exit("ERROR: Vista incompatible; no se aplicaron cambios.")
if start in source and end in source:
    print("OK: Tipografía V3 ya instalada.")
    sys.exit(0)
if start in source or end in source:
    sys.exit("ERROR: Bloque de estilo incompleto; no se aplicaron cambios.")
updated = source.replace("</style>", css + "\n</style>", 1)
if mode == "--check":
    print("OK: Se ajustará exclusivamente la tipografía de la tabla de Prospectos a Manrope 12px.")
    print("No se alterarán los registros, las columnas ni otros módulos.")
    sys.exit(0)
backup = path.with_name(path.name + ".bak-tipografia-v3-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S"))
shutil.copy2(str(path), str(backup))
try:
    path.write_text(updated, encoding="utf-8")
    if shutil.which("php"):
        result = subprocess.run(["php", "-l", str(path)], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, universal_newlines=True)
        print(result.stdout.strip())
        if result.returncode != 0:
            raise RuntimeError("Error de sintaxis PHP")
except Exception:
    shutil.copy2(str(backup), str(path))
    raise
print("OK: Prospectos actualizado en public_html.")
print("Respaldo: " + str(backup))
PY
