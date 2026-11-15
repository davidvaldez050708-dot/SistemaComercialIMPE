"""Compara las tres versiones instaladas sin escribir en el sitio."""
import argparse
import hashlib
from pathlib import Path

BASE = {'app/controllers/RespaldoController.php': '100445f861a196fb9ea0a7be5d266c74775af6983de8a2b23e3e1941468f40c5', 'app/services/RespaldoBaseDatos.php': '3acae4fa8c65322191dd324d6a5cdcf3af0c520501fe4dbf0bdf52ccb7e01f56', 'app/views/respaldo/index.php': '4f48c79e569bc95b7c5d06012341ae93028a8da2ff33dd86b0776685f0a1c5b8'}

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--site", required=True, type=Path)
parser.add_argument("--check", required=True, action="store_true")
args = parser.parse_args()
root = args.site.resolve()
blocked = False
for name, expected in BASE.items():
    path = root / name
    if root not in path.resolve().parents or path.is_symlink() or not path.is_file():
        print("BLOQUEADO:", name, "ausente o fuera del sitio")
        blocked = True
        continue
    actual = hashlib.sha256(path.read_bytes()).hexdigest()
    if actual != expected:
        print("BLOQUEADO:", name, "versión distinta", actual)
        blocked = True
    else:
        print("BASE_OK:", name)
if blocked:
    raise SystemExit(2)
print("CHECK_OK: tres archivos coinciden con la rama base.")
