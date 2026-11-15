"""Compara las tres versiones instaladas sin escribir en el sitio."""
import argparse
import hashlib
from pathlib import Path

BASE = {'app/controllers/RespaldoController.php': '5b3b5bd24d2fb811f3ee0a11f6d6482d49b337541fdaeb4cfc4753c92619afa2', 'app/services/RespaldoBaseDatos.php': '1ef8fc4452278f399c07cd3cc8e3068a1373f8c1829dbf5c8c51fc67e32007c3', 'app/views/respaldo/index.php': '15056632b4175627e2a7fa3ea7372c1cd03170ac0476aab5c243e63e72ba1e8f'}

if __name__ == '__main__':
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
