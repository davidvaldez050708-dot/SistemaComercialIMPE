#!/usr/bin/env python3
"""Simular actualización selectiva sin credenciales ni Hostinger."""
import pathlib
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
COMMIT = "1350cf192dbad9b6de6f2c8defc92674f1fde9b3"
FILES = (
    "prueba_telefonia/api/llamadas_seguimiento.php",
)
INSTALLER = ROOT / "tools/desplegar_historial_mysql_2002_hostinger.py"

def git_text(ref):
    return subprocess.check_output(
        ["git", "-C", str(ROOT), "show", ref], stderr=subprocess.DEVNULL
    ).decode()

with tempfile.TemporaryDirectory() as directory:
    live = pathlib.Path(directory) / "live"
    for p in FILES:
        dest = live / p
        dest.parent.mkdir(parents=True, exist_ok=True)
        base = git_text(COMMIT + "^:" + p)
        if p.endswith("llamadas_seguimiento.php"):
            base += "\n// Personalización local que debe preservarse.\n"
        dest.write_text(base)
    cmd = [sys.executable, str(INSTALLER), "--live", str(live)]
    check = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert check.returncode == 0, check.stdout + check.stderr
    apply = subprocess.run(cmd + ["--apply"], capture_output=True, text=True)
    assert apply.returncode == 0, apply.stdout + apply.stderr
    for p in FILES:
        expected = git_text(COMMIT + ":" + p)
        if p.endswith("llamadas_seguimiento.php"):
            expected += "\n// Personalización local que debe preservarse.\n"
        assert (live / p).read_text() == expected, p
    again = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert again.returncode == 0, again.stdout + again.stderr
    assert "ya actualizado" in again.stdout
    # Una edición local en el bloque afectado no puede sobreescribirse.
    target = live / FILES[0]
    original = git_text(COMMIT + "^:" + FILES[0])
    target.write_text(original.replace(
        "$conexionHistorial = (new Database())->connect(true);",
        "$conexionHistorial = (new Database())->connect(/*personalizacion*/true);"
    ))
    rejected = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert rejected.returncode != 0 and "CONFLICTO" in rejected.stdout
print("Despliegue ruta/audio: OK (check, apply, personalización, idempotencia y conflicto).")
