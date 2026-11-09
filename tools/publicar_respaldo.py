"""Publica únicamente el cambio de Respaldo desde el clon de preparación."""

import argparse
from pathlib import Path
import subprocess
import tarfile
import tempfile


EXISTENTES = (
    "index.php",
    "app/views/layout/topbar.php",
    "app/views/layout/my_profile_modal.php",
)
NUEVOS = (
    "app/controllers/RespaldoController.php",
    "app/services/RespaldoBaseDatos.php",
    "app/views/respaldo/index.php",
)


def ejecutar(*comando, **opciones):
    return subprocess.run(comando, check=True, **opciones)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True, type=Path)
    parser.add_argument("--backup-root", type=Path)
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    if args.apply and args.backup_root is None:
        parser.error("--apply requiere --backup-root")
    sitio = args.site.resolve(strict=True)
    if args.apply and (raiz := args.backup_root.resolve()).is_relative_to(sitio):
        parser.error("--backup-root debe estar fuera del sitio")

    repo = Path(__file__).resolve().parent.parent
    commit = ejecutar(
        "git", "log", "-1", "--format=%H", "--diff-filter=A", "HEAD", "--", NUEVOS[0],
        cwd=repo, capture_output=True, text=True,
    ).stdout.strip()
    if not commit:
        parser.error("El clon de preparación no contiene el commit de Respaldo")

    archivos = EXISTENTES + NUEVOS
    parche = ejecutar(
        "git", "show", "--format=", "--binary", commit, "--", *archivos,
        cwd=repo, capture_output=True,
    ).stdout
    if not parche:
        parser.error("El parche de Respaldo está vacío")

    for nombre in archivos:
        contenido = ejecutar("git", "show", f"{commit}:{nombre}", cwd=repo, capture_output=True).stdout
        ejecutar("php", "-l", input=contenido)

    aplicar = ("git", "apply", "--", "-")
    ejecutar("git", "apply", "--check", "--", "-", cwd=sitio, input=parche)
    if args.check:
        print("Parche compatible con el sitio.")
        return

    raiz.mkdir(parents=True, exist_ok=True, mode=0o700)
    respaldo = Path(tempfile.mkdtemp(prefix="respaldo-bd-", dir=raiz))
    with tarfile.open(respaldo / "archivos-antes.tar.gz", "w:gz") as copia:
        for nombre in EXISTENTES:
            copia.add(sitio / nombre, arcname=nombre)
    (respaldo / "cambios.patch").write_bytes(parche)
    print(f"Copia del código anterior: {respaldo}", flush=True)
    ejecutar(*aplicar, cwd=sitio, input=parche)

    try:
        for nombre in archivos:
            ejecutar("php", "-l", nombre, cwd=sitio)
    except subprocess.CalledProcessError:
        ejecutar("git", "apply", "--reverse", "--", "-", cwd=sitio, input=parche)
        raise
    print("Publicación terminada.")


if __name__ == "__main__":
    main()
