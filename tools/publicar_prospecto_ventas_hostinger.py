#!/usr/bin/env python3
"""Actualización selectiva y reversible de recepción de prospectos en Hostinger."""
import difflib
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime

FILES = [
    ("public/javascript/telefonia_enrutamiento.js", "js",
     "a5ed6de6f67cee97a22650b063e8a051d2d4fad1",
     "ca13ed1c8a800adcd83f1bb0586338b0f491a8b8"),
    ("app/services/TelefoniaDisponibilidadService.php", "php",
     "9cbd97b7251d16760e2ef141d2bde07eb38da754",
     "aae00d537268fb6863e101b662ac2a2f42d62e88"),
]
BASE = "bd562a04b46814dcada148195f5793630e95f393"
TARGET = "2c8f51aaef007d7b4b20dd7008811a554bb19b9f"
REPO = "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/"
ROOT = Path(os.environ.get("IMPE_PROSPECTO_LIVE") or Path.home() / "domains/fundacionrededumex.org/public_html")
BACKUPS = Path(os.environ.get("IMPE_PROSPECTO_BACKUPS") or Path.home() / "respaldos-crm")
MIRROR = os.environ.get("IMPE_PROSPECTO_MIRROR", "")

def fail(message):
    raise SystemExit("CONFLICTO: " + message + ". No sobrescribir la versión publicada.")

def safe(path):
    if any(p.is_symlink() for p in (path, *path.parents)):
        fail("No se admiten enlaces simbólicos: " + str(path))

def digest(content):
    return hashlib.sha256(content).hexdigest()

def gitblob(content):
    return hashlib.sha1(b"blob " + str(len(content)).encode() + b"\0" + content).hexdigest()

def atomic(path, content):
    fd, temporary = tempfile.mkstemp(prefix=".impe-prospecto-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, path.stat().st_mode & 0o777)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

def download(ref, name, label, tmp):
    if MIRROR:
        return (Path(MIRROR) / label).read_bytes()
    curl = shutil.which("curl")
    if not curl:
        fail("Falta curl")
    path = tmp / label
    result = subprocess.run(
        [curl, "-fsSL", "--retry", "2", REPO + ref + "/" + name, "-o", str(path)],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True
    )
    if result.returncode:
        fail("No se descargó " + name)
    return path.read_bytes()

def patch(current, original, changed, name):
    if current == changed:
        return current, "YA ACTUALIZADO"
    if current == original:
        return changed, "BASE EXACTA"
    first = original.splitlines(keepends=True)
    second = changed.splitlines(keepends=True)
    groups = list(difflib.SequenceMatcher(None, first, second, autojunk=False).get_grouped_opcodes(4))
    if not groups:
        fail("No hay bloques nuevos para " + name)
    patches = []
    for group in groups:
        old = b"".join(first[group[0][1]:group[-1][2]])
        new = b"".join(second[group[0][3]:group[-1][4]])
        if not old or not new:
            fail("Bloque incompleto en " + name)
        patches.append((old, new))
    if all(current.count(new) == 1 for _, new in patches):
        return current, "YA ACTUALIZADO (PERSONALIZADO)"
    if not all(current.count(old) == 1 for old, _ in patches):
        fail("La personalización no coincide en " + name)
    result = current
    for old, new in patches:
        if result.count(old) != 1:
            fail("Bloque ambiguo en " + name)
        result = result.replace(old, new, 1)
    return result, "PARCHE COMPATIBLE"

def main():
    if len(sys.argv) not in (2, 3) or sys.argv[1] not in ("--check", "--apply", "--restore"):
        fail("Uso: python3 script.py --check|--apply|--restore RESPALDO")
    mode = sys.argv[1]
    if (mode == "--restore") != (len(sys.argv) == 3):
        fail("Se requiere un único respaldo para restaurar")
    safe(ROOT)
    safe(BACKUPS)
    if not ROOT.is_dir() or not (ROOT / "index.php").is_file():
        fail("No se encontró la raíz del CRM")
    if ROOT.resolve() == BACKUPS.resolve() or ROOT.resolve() in BACKUPS.resolve().parents:
        fail("El respaldo debe estar fuera del sitio web")
    files = {name: ROOT / name for name, _, _, _ in FILES}
    for name, path in files.items():
        safe(path)
        if not path.is_file():
            fail("Falta el archivo " + name)

    if mode == "--restore":
        folder = Path(sys.argv[2])
        safe(folder)
        if not folder.is_dir() or folder.parent.resolve() != BACKUPS.resolve():
            fail("El respaldo no está autorizado")
        manifest_file = folder / "manifest.json"
        safe(manifest_file)
        if not manifest_file.is_file():
            fail("Manifiesto ausente")
        manifest = json.loads(manifest_file.read_text(encoding="utf-8"))
        restores = []
        for name in files:
            source = folder / name
            safe(source)
            if not source.is_file():
                fail("Original ausente: " + name)
            entry = manifest.get("files", {}).get(name, {})
            original = source.read_bytes()
            if digest(original) != entry.get("before") or digest(files[name].read_bytes()) != entry.get("after"):
                fail("Un archivo cambió desde la publicación: " + name)
            restores.append((files[name], original))
        for path, original in restores:
            atomic(path, original)
        print("OK: dos archivos restaurados.")
        return

    prepared = {}
    with tempfile.TemporaryDirectory(prefix="impe-prospecto-check-") as scratch:
        tmp = Path(scratch)
        for name, tag, old_hash, new_hash in FILES:
            original = download(BASE, name, "base-" + tag, tmp)
            changed = download(TARGET, name, "target-" + tag, tmp)
            if gitblob(original) != old_hash or gitblob(changed) != new_hash:
                fail("Hash de GitHub incompatible para " + name)
            live = files[name].read_bytes()
            result, status = patch(live, original, changed, name)
            decoded = result.decode("utf-8")
            if tag == "js" and ("const esProspecto" not in decoded or "const directoryQuery = ''" not in decoded):
                fail("Faltan protecciones JS de " + name)
            if tag == "php" and "normalizarNombreProspecto" not in decoded:
                fail("Falta la validación PHP")
            validator = shutil.which("node" if tag == "js" else "php")
            if validator:
                source = tmp / ("check." + tag)
                source.write_bytes(result)
                args = [validator, "--check", str(source)] if tag == "js" else [validator, "-l", str(source)]
                check = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
                if check.returncode:
                    fail("Sintaxis inválida de " + name)
            prepared[name] = (live, result)
            print(status + ": " + name)

    if mode == "--check":
        print("OK: revisión compatible. " + str(sum(a != b for a, b in prepared.values())) + " archivos por publicar. SITIO INTACTO.")
        return
    pending = [name for name, (a, b) in prepared.items() if a != b]
    if not pending:
        print("OK: cambio ya instalado. No se modificó el sitio.")
        return
    BACKUPS.mkdir(parents=True, mode=0o700, exist_ok=True)
    safe(BACKUPS)
    folder = Path(tempfile.mkdtemp(prefix="ventas-prospecto-" + datetime.now().strftime("%Y%m%d-%H%M%S") + "-", dir=str(BACKUPS)))
    os.chmod(folder, 0o700)
    manifest = {"files": {}}
    for name, (before, after) in prepared.items():
        source = folder / name
        source.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(files[name], source)
        if digest(source.read_bytes()) != digest(before):
            fail("No coincide el respaldo de " + name)
        manifest["files"][name] = {"before": digest(before), "after": digest(after)}
    (folder / "manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    for name, (before, _) in prepared.items():
        if digest(files[name].read_bytes()) != digest(before):
            fail("Se modificó el archivo durante la comprobación: " + name)
    updated = []
    try:
        for name in pending:
            atomic(files[name], prepared[name][1])
            updated.append(name)
            if digest(files[name].read_bytes()) != digest(prepared[name][1]):
                raise RuntimeError("No coincidió el archivo publicado " + name)
    except BaseException:
        for name in reversed(updated):
            atomic(files[name], prepared[name][0])
        raise
    print("OK: publicado sin cambios SQL, SDK, Zadarma ni credenciales.")
    print("Respaldo:", folder)
    print('Restaurar: python3 "$HOME/publicar-prospecto-ventas.py" --restore "' + str(folder) + '"')

if __name__ == "__main__":
    main()
