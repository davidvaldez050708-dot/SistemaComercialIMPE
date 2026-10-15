#!/usr/bin/env python3
"""Simulación de Hostinger con vista PBX personalizada conservada intacta."""
from pathlib import Path
from tempfile import TemporaryDirectory
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
BASE = "b4a685bc2223e6aaa84326bbeeb57bdfa855b989"
SCRIPT = ROOT / "tools/desplegar_historial_reintentos_personalizado_hostinger.py"
JS = "public/javascript/seguimiento_llamadas_expediente.js"
FILES = (
    "public/javascript/seguimiento_grabaciones.js",
    "app/views/layout/dashboard_footer.php",
)
NEW = "public/javascript/seguimiento_historial_consulta.js"

def code(commit, path):
    return subprocess.check_output(
        ["git", "-C", str(ROOT), "show", commit + ":" + path],
        stderr=subprocess.DEVNULL
    ).decode()

with TemporaryDirectory() as directory:
    live = Path(directory) / "live"
    for relative in (JS,) + FILES:
        target = live / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(
            code("9ed452e134cf15520387e8a942c9f804dfd442b4", relative)
            if relative == JS else code(BASE + "^", relative)
        )
    customization = "// Personalización exclusiva de Hostinger.\n"
    js = live / JS
    original = js.read_text()
    js.write_text(original + customization)
    cmd = [sys.executable, str(SCRIPT), "--live", str(live)]
    test = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert test.returncode == 0, test.stdout + test.stderr
    assert not (live / NEW).exists()
    applied = subprocess.run(cmd + ["--apply"], capture_output=True, text=True)
    assert applied.returncode == 0, applied.stdout + applied.stderr
    assert js.read_text() == original + customization, "Se sobreescribió JS personalizado"
    assert (live / NEW).is_file(), "La consulta compartida no está instalada"
    again = subprocess.run(cmd + ["--apply"], capture_output=True, text=True)
    assert again.returncode == 0 and "YA INSTALADO" in again.stdout, again.stdout
    js.write_text(original.replace("IMPE_CONSULTAR_LLAMADAS_EXPEDIENTE(seguimientoId)",
                                   "IMPE_CONSULTAR_FALLIDA(seguimientoId)"))
    failed = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert failed.returncode != 0 and "CONFLICTO" in failed.stderr
print("Hostinger historial personalizado: OK (check, apply, conservación, reapply, conflicto).")
