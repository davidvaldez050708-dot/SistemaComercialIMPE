#!/usr/bin/env python3
"""Simular actualización selectiva sin credenciales ni Hostinger."""
import pathlib
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
COMMIT = "b4a685bc2223e6aaa84326bbeeb57bdfa855b989"
FILES = (
    "public/javascript/seguimiento_llamadas_expediente.js",
    "public/javascript/seguimiento_grabaciones.js",
    "app/views/layout/dashboard_footer.php",
)
INSTALLER = ROOT / "tools/desplegar_historial_reintentos_hostinger.py"

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
        if p.endswith("seguimiento_llamadas_expediente.js"):
            base += "\n// Personalización local que debe preservarse.\n"
        dest.write_text(base)
    cmd = [sys.executable, str(INSTALLER), "--live", str(live)]
    check = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert check.returncode == 0, check.stdout + check.stderr
    apply = subprocess.run(cmd + ["--apply"], capture_output=True, text=True)
    assert apply.returncode == 0, apply.stdout + apply.stderr
    for p in FILES:
        expected = git_text(COMMIT + ":" + p)
        if p.endswith("seguimiento_llamadas_expediente.js"):
            expected += "\n// Personalización local que debe preservarse.\n"
        assert (live / p).read_text() == expected, p
    again = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert again.returncode == 0, again.stdout + again.stderr
    assert "ya actualizado" in again.stdout
    # Una edición local en el bloque afectado no puede sobreescribirse.
    target = live / FILES[0]
    original = git_text(COMMIT + "^:" + FILES[0])
    target.write_text(original.replace(
        "const anterior = window.IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE;",
        "const anterior = /* personalizado */ window.IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE;"
    ))
    rejected = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert rejected.returncode != 0 and "CONFLICTO" in rejected.stdout
print("Despliegue ruta/audio: OK (check, apply, personalización, idempotencia y conflicto).")
