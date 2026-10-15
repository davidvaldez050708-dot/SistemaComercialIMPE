#!/usr/bin/env python3
"""Validar instalación de audio sin acceso a Hostinger ni credenciales."""
import pathlib
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
COMMIT = "9ed452e134cf15520387e8a942c9f804dfd442b4"
FILES = (
    "prueba_telefonia/api/llamadas_seguimiento.php",
    "public/javascript/seguimiento_llamadas_expediente.js",
    "public/css/seguimiento_llamadas_expediente.css",
)
NEW = "app/services/TelefoniaGrabacionExpedienteTransferidaService.php"
SCRIPT = ROOT / "tools/desplegar_audio_pbx_expediente_hostinger.py"

def show(ref):
    return subprocess.check_output(
        ["git", "-C", str(ROOT), "show", ref], stderr=subprocess.DEVNULL
    ).decode()

with tempfile.TemporaryDirectory() as work:
    live = pathlib.Path(work) / "live"
    for p in FILES:
        dest = live / p
        dest.parent.mkdir(parents=True, exist_ok=True)
        original = show(COMMIT + "^:" + p)
        if p.endswith("seguimiento_llamadas_expediente.js"):
            original += "\n// Personalización Hostinger que no debe borrarse.\n"
        dest.write_text(original)
    args = [sys.executable, str(SCRIPT), "--live", str(live)]
    checked = subprocess.run(args + ["--check"], capture_output=True, text=True)
    assert checked.returncode == 0, checked.stdout + checked.stderr
    assert not (live / NEW).exists()
    applied = subprocess.run(args + ["--apply"], capture_output=True, text=True)
    assert applied.returncode == 0, applied.stdout + applied.stderr
    assert (live / NEW).read_text() == show(COMMIT + ":" + NEW)
    for p in FILES:
        expected = show(COMMIT + ":" + p)
        if p.endswith("seguimiento_llamadas_expediente.js"):
            expected += "\n// Personalización Hostinger que no debe borrarse.\n"
        assert (live / p).read_text() == expected, p
    again = subprocess.run(args + ["--apply"], capture_output=True, text=True)
    assert again.returncode == 0 and "YA INSTALADO" in again.stdout, again.stdout
    first = live / FILES[0]
    source = show(COMMIT + "^:" + FILES[0])
    first.write_text(source.replace(
        "$etapaHistorial = 'consultar_interacciones';",
        "$etapaHistorial = /* personalizado */ 'consultar_interacciones';"
    ))
    before = first.read_text()
    conflict = subprocess.run(args + ["--check"], capture_output=True, text=True)
    assert conflict.returncode != 0 and "CONFLICTO" in conflict.stdout
    assert first.read_text() == before
print("Audio PBX expediente: OK (check, apply, respaldo, idempotencia, conflicto).")
