"""Publica o revierte exclusivamente los tres archivos del R16, sin SQL."""
import argparse
import hashlib
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile

from comprobar_respaldo_r16 import BASE


ENTREGA = {'app/controllers/RespaldoController.php': '5b3b5bd24d2fb811f3ee0a11f6d6482d49b337541fdaeb4cfc4753c92619afa2', 'app/services/RespaldoBaseDatos.php': 'ee89d8ab7159775234d05534e43a0b3a012e4bfef7cb239f0a6797dea49a7e22', 'app/views/respaldo/index.php': '15056632b4175627e2a7fa3ea7372c1cd03170ac0476aab5c243e63e72ba1e8f'}


def huella(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def archivo(root, name):
    path = root / name
    if path.resolve() != path or not path.is_file():
        raise ValueError("Archivo ausente o con enlace: " + name)
    return path


def instalar(path, source, mode):
    fd, temporary = tempfile.mkstemp(dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(source.read_bytes())
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True, type=Path)
    parser.add_argument("--stage", required=True, type=Path)
    parser.add_argument("--backup", type=Path)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check", action="store_true")
    group.add_argument("--apply", action="store_true")
    group.add_argument("--rollback", action="store_true")
    args = parser.parse_args()
    site, stage = args.site.resolve(), args.stage.resolve()
    if site == stage or site in stage.parents:
        raise ValueError("La entrega debe estar fuera del sitio.")
    current, package = {}, {}
    for name in BASE:
        current[name] = archivo(site, name)
        package[name] = archivo(stage, name)
        if huella(package[name]) != ENTREGA[name]:
            raise ValueError("Integridad de la entrega: " + name)
        subprocess.run(["php", "-l", str(package[name])], check=True)
    installed = {name: huella(path) for name, path in current.items()}
    if args.check:
        if installed == BASE:
            print("CHECK_OK: versiones base exactas; sitio intacto.")
        elif installed == ENTREGA:
            print("CHECK_OK: entrega ya aplicada.")
        else:
            raise ValueError("Versiones desconocidas o mixtas: " + str(installed))
        return

    if args.backup is None:
        raise ValueError("Indica un directorio privado con --backup.")
    backup = args.backup.resolve()
    if backup == site or site in backup.parents or backup == stage:
        raise ValueError("El respaldo debe estar fuera del sitio y separado de la entrega.")
    if args.apply:
        if installed != BASE:
            raise ValueError("El sitio cambió respecto a la base esperada.")
        backup.mkdir(mode=0o700)
        for name, path in current.items():
            target = backup / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)
        original = {name: archivo(backup, name) for name in BASE}
    else:
        original = {name: archivo(backup, name) for name in BASE}
        if any(value not in (BASE[name], ENTREGA[name]) for name, value in installed.items()):
            raise ValueError("El sitio ya contiene una versión ajena a la entrega.")
    if any(huella(path) != BASE[name] for name, path in original.items()):
        raise ValueError("El respaldo de código no coincide con la base.")
    print("RESPALDO:", backup, flush=True)

    changed = []
    try:
        for name, path in current.items():
            if huella(archivo(site, name)) != installed[name]:
                raise ValueError("Cambio concurrente: " + name)
            mode = stat.S_IMODE(original[name].stat().st_mode)
            instalar(path, original[name] if args.rollback else package[name], mode)
            changed.append(name)
        expected = BASE if args.rollback else ENTREGA
        if any(huella(path) != expected[name] for name, path in current.items()):
            raise ValueError("La comprobación posterior falló.")
    except Exception:
        if args.apply:
            for name in reversed(changed):
                if huella(current[name]) == ENTREGA[name]:
                    instalar(current[name], original[name], stat.S_IMODE(original[name].stat().st_mode))
        raise
    print("ROLLBACK_OK" if args.rollback else "APPLY_OK")


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        raise SystemExit("BLOQUEADO: " + str(error))
