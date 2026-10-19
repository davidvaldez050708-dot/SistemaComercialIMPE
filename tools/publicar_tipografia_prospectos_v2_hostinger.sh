#!/usr/bin/env bash
set -euo pipefail
MODE="${1:-}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi
TARGET="$HOME/domains/fundacionrededumex.org/public_html/app/views/prospectos/index.php"
if [[ ! -f "$TARGET" ]]; then echo "ERROR: Vista de Prospectos inexistente: $TARGET" >&2; exit 1; fi
python3 - "$MODE" "$TARGET" <<'PY'
import datetime
import pathlib
import shutil
import subprocess
import sys
mode = sys.argv[1]
p = pathlib.Path(sys.argv[2])
original = p.read_text(encoding="utf-8")
legacy = ".prospectos-page table,.prospectos-page table th,.prospectos-page table td{font-family:'Manrope',sans-serif !important;letter-spacing:normal}"
base = ".prospectos-page table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}"
updated = """.prospectos-page .prospectos-table-wrap table,
.prospectos-page .prospectos-table-wrap table th,
.prospectos-page .prospectos-table-wrap table td,
.prospectos-page .prospectos-table-wrap table span,
.prospectos-page .prospectos-table-wrap table a{font-family:'Manrope',sans-serif !important;font-size:14px !important;letter-spacing:-.01em !important;line-height:1.5 !important}
.prospectos-page .prospectos-table-wrap table th{font-weight:700 !important}
.prospectos-page .prospectos-table-wrap table td{font-weight:500 !important}
.prospectos-page .prospectos-table-wrap table td.prospectos-name{font-weight:700 !important}"""
if updated in original:
    print("OK: Tipografía institucional ya aplicada; sin cambios.")
    sys.exit(0)
if original.count(legacy) == 1:
    nuevo = original.replace(legacy, updated, 1)
elif original.count(base) == 1 and legacy not in original:
    nuevo = original.replace(base, base + "\n" + updated, 1)
else:
    sys.exit("ERROR: CSS de Prospectos no reconocido. Se protege la instalación existente.")
if mode == "--check":
    print("OK: Se puede actualizar exclusivamente la tabla de Prospectos.")
    print("La fuente será Manrope a 14px, pesos 500 y 700.")
    sys.exit(0)
backup = p.with_name(p.name + ".bak-tipografia-v2-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S"))
shutil.copy2(str(p), str(backup))
try:
    p.write_text(nuevo, encoding="utf-8")
    if shutil.which("php"):
        result = subprocess.run(["php", "-l", str(p)], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, universal_newlines=True)
        print(result.stdout.strip())
        if result.returncode:
            raise RuntimeError("Falló la comprobación PHP")
except Exception:
    shutil.copy2(str(backup), str(p))
    raise
print("OK: Tipografía de Prospectos actualizada exclusivamente en public_html.")
print("Respaldo: " + str(backup))
PY
