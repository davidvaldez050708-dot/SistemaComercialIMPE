#!/usr/bin/env bash
set -euo pipefail
MODE="${1:-}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi
ROOT="$HOME/domains/fundacionrededumex.org/public_html"
TARGET="$ROOT/app/views/prospectos/index.php"
if [[ ! -f "$TARGET" ]]; then
  echo "ERROR: No existe la vista de Prospectos en producción: $TARGET" >&2
  exit 1
fi
if ! command -v python3 >/dev/null 2>&1; then
  echo "ERROR: python3 no está disponible." >&2
  exit 1
fi
if [[ "$MODE" == "--check" ]]; then
  python3 - "$TARGET" <<'PY'
import sys
from pathlib import Path
p = Path(sys.argv[1])
s = p.read_text(encoding="utf-8")
anchor = ".prospectos-page table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}"
rule = ".prospectos-page table,.prospectos-page table th,.prospectos-page table td{font-family:'Manrope',sans-serif !important;letter-spacing:normal}"
if rule in s:
    print("OK: La tipografía Manrope ya está aplicada. No se requieren cambios.")
elif s.count(anchor) == 1:
    print("OK: Vista compatible. Se agregará únicamente la regla Manrope a la tabla.")
else:
    sys.exit("ERROR: Estructura de Prospectos no reconocida; no se aplicarán cambios.")
PY
  exit 0
fi
python3 - "$TARGET" <<'PY'
import sys, shutil, datetime, subprocess
from pathlib import Path
p = Path(sys.argv[1])
s = p.read_text(encoding="utf-8")
anchor = ".prospectos-page table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}"
rule = ".prospectos-page table,.prospectos-page table th,.prospectos-page table td{font-family:'Manrope',sans-serif !important;letter-spacing:normal}"
if rule in s:
    print("OK: Manrope ya está aplicada. Sin cambios.")
    sys.exit(0)
if s.count(anchor) != 1:
    sys.exit("ERROR: Vista incompatible. No se modificó ningún archivo.")
backup = p.with_name(p.name + ".bak-tipografia-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S"))
shutil.copy2(str(p), str(backup))
nuevo = s.replace(anchor, anchor + "\n" + rule, 1)
p.write_text(nuevo, encoding="utf-8")
if shutil.which("php"):
    proc = subprocess.run(["php", "-l", str(p)], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, universal_newlines=True)
    print(proc.stdout.strip())
    if proc.returncode != 0:
        shutil.copy2(str(backup), str(p))
        sys.exit("ERROR: Sintaxis inválida. Se restauró el respaldo.")
print("OK: Tipografía de Prospectos actualizada.")
print("Respaldo: " + str(backup))
PY
