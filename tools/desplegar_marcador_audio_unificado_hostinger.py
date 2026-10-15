#!/usr/bin/env python3
"""Despliegue conservador de marcador y reproductor PBX desde un commit revisado.
--check nunca escribe. --apply solo con coincidencia exacta de la base o
hunks únicos, y respalda TODOS los archivos antes de modificar el primero.
"""
import argparse
import difflib
import pathlib
import shutil
import subprocess
import sys
from datetime import datetime, timezone

FILES = (
    "public/javascript/telefonia_teclado.js",
    "public/javascript/telefonia_recepcion_marketing.js",
    "public/javascript/seguimiento_entrantes_analista.js",
    "app/views/layout/dashboard_footer.php",
    "public/javascript/telefonia_segmentos_player.js",
)
COMMIT = "add3c7e1f59000d8bfc5473e31f21ac983b9173c"


def git(*args):
    return subprocess.run(["git", *args], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout.decode("utf-8")


def aplicar_hunks(actual, original, nuevo):
    if actual == nuevo:
        return actual, "ya actualizado"
    if actual == original:
        return nuevo, "base exacta"
    # Compatibilidad conservadora: solo sustituir bloques con contexto
    # idéntico y único. No intentar combinar si el contexto fue personalizado.
    diffs = list(difflib.unified_diff(
        original.splitlines(keepends=True), nuevo.splitlines(keepends=True),
        n=5,
    ))
    if len(diffs) < 3:
        raise ValueError("No hay modificaciones para fusionar")
    resultado = actual
    old, new = [], []
    hunks = []

    def guardar():
        nonlocal old, new
        if old or new:
            hunks.append(("".join(old), "".join(new)))
        old, new = [], []

    for line in diffs[2:]:
        if line.startswith("@@"):
            guardar()
        elif line.startswith(" "):
            old.append(line[1:])
            new.append(line[1:])
        elif line.startswith("-"):
            old.append(line[1:])
        elif line.startswith("+"):
            new.append(line[1:])
        elif line.startswith("\\ No newline"):
            raise ValueError("Cambio de final de línea no fusionable")
    guardar()
    if not hunks:
        raise ValueError("No se identificaron bloques de modificación")
    for before, after in hunks:
        if not before or resultado.count(before) != 1:
            raise ValueError("Bloque original ausente o repetido: posible personalización")
        resultado = resultado.replace(before, after, 1)
    return resultado, "hunks compatibles"


def main():
    cli = argparse.ArgumentParser()
    cli.add_argument("--check", action="store_true")
    cli.add_argument("--apply", action="store_true")
    cli.add_argument("--live", required=True)
    args = cli.parse_args()
    if args.check == args.apply:
        cli.error("Selecciona exclusivamente --check o --apply")
    repo = pathlib.Path(__file__).resolve().parents[1]
    live = pathlib.Path(args.live).expanduser().resolve()
    if not live.is_dir() or not (live / "app").is_dir():
        cli.error("La ruta real del CRM no es válida.")
    ancestor = subprocess.run(
        ["git", "-C", str(repo), "merge-base", "--is-ancestor", COMMIT, "HEAD"]
    )
    if ancestor.returncode != 0:
        cli.error("El checkout no contiene el commit aprobado: " + COMMIT)
    outputs = []
    problems = []
    for relative in FILES:
        dest = live / relative
        target = git("-C", str(repo), "show", COMMIT + ":" + relative)
        try:
            base = git("-C", str(repo), "show", COMMIT + "^:" + relative)
        except subprocess.CalledProcessError:
            base = None
        if base is None:
            if dest.exists() and dest.read_text(encoding="utf-8") != target:
                problems.append(relative + ": existe un archivo nuevo distinto")
                continue
            outputs.append((relative, dest, target, "nuevo" if not dest.exists() else "ya actualizado"))
            continue
        if not dest.is_file():
            problems.append(relative + ": archivo de producción ausente")
            continue
        try:
            result, mode = aplicar_hunks(dest.read_text(encoding="utf-8"), base, target)
            outputs.append((relative, dest, result, mode))
        except (UnicodeDecodeError, ValueError) as err:
            problems.append(relative + ": " + str(err))
    for relative, _, _, mode in outputs:
        print("COMPATIBLE:", relative, "(" + mode + ")")
    for error in problems:
        print("CONFLICTO:", error)
    if problems:
        print("ABORTADO: ningún archivo modificado.")
        return 2
    if args.check:
        print("CHECK OK: " + str(len(outputs)) + " archivos; producción intacta.")
        return 0
    pending = [(rel, dest, content) for rel, dest, content, _ in outputs
               if not dest.is_file() or dest.read_text(encoding="utf-8") != content]
    if not pending:
        print("YA INSTALADO: no se requirieron cambios.")
        return 0
    backup = pathlib.Path.home() / "crm-deploy-backups" / ("marcador-audio-" + datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S"))
    backup.mkdir(parents=True, exist_ok=False)
    for relative, dest, _ in pending:
        if dest.exists():
            path = backup / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(dest, path)
    # Se preparan primero los nuevos archivos en .tmp; reemplazos atómicos.
    try:
        for relative, dest, content in pending:
            dest.parent.mkdir(parents=True, exist_ok=True)
            tmp = dest.with_name(dest.name + ".grabaciones.tmp")
            if tmp.exists():
                raise RuntimeError("Archivo temporal ya existente: " + str(tmp))
            tmp.write_text(content, encoding="utf-8")
            if dest.exists():
                shutil.copymode(dest, tmp)
        for relative, dest, _ in pending:
            dest.with_name(dest.name + ".grabaciones.tmp").replace(dest)
            print("INSTALADO:", relative)
    except Exception:
        for relative, dest, _ in pending:
            tmp = dest.with_name(dest.name + ".grabaciones.tmp")
            if tmp.exists():
                tmp.unlink()
            original = backup / relative
            if original.is_file():
                shutil.copy2(original, dest)
            elif dest.exists() and relative == "public/javascript/telefonia_segmentos_player.js":
                dest.unlink()
        raise
    print("APLICACIÓN TERMINADA; respaldo en:", backup)
    return 0


if __name__ == "__main__":
    sys.exit(main())
