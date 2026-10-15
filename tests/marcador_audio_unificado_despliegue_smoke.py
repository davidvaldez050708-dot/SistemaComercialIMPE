#!/usr/bin/env python3
"""Prueba de instalador sin Hostinger ni credenciales."""
import pathlib
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
COMMIT = "add3c7e1f59000d8bfc5473e31f21ac983b9173c"
FILES = [
    "public/javascript/telefonia_teclado.js",
    "public/javascript/telefonia_recepcion_marketing.js",
    "public/javascript/seguimiento_entrantes_analista.js",
    "app/views/layout/dashboard_footer.php",
]
NEW = "public/javascript/telefonia_segmentos_player.js"
SCRIPT = ROOT / "tools/desplegar_marcador_audio_unificado_hostinger.py"


def old(path):
    return subprocess.check_output(
        ["git", "-C", str(ROOT), "show", COMMIT + "^:" + path]
    ).decode()


def new(path):
    return subprocess.check_output(
        ["git", "-C", str(ROOT), "show", COMMIT + ":" + path]
    ).decode()


with tempfile.TemporaryDirectory() as home:
    live = pathlib.Path(home) / "live"
    for path in FILES:
        output = live / path
        output.parent.mkdir(parents=True, exist_ok=True)
        text = old(path)
        if path.endswith("telefonia_recepcion_marketing.js"):
            text += "\n// Personalización de Hostinger que debe conservarse.\n"
        output.write_text(text)
    command = [sys.executable, str(SCRIPT), "--live", str(live)]
    check = subprocess.run(command + ["--check"], capture_output=True, text=True)
    assert check.returncode == 0, check.stdout + check.stderr
    assert not (live / NEW).exists()
    assert old(FILES[0]) in (live / FILES[0]).read_text()
    apply = subprocess.run(command + ["--apply"], capture_output=True, text=True)
    assert apply.returncode == 0, apply.stdout + apply.stderr
    assert (live / NEW).read_text() == new(NEW)
    for path in FILES:
        actual = (live / path).read_text()
        expected = new(path)
        if path.endswith("telefonia_recepcion_marketing.js"):
            expected += "\n// Personalización de Hostinger que debe conservarse.\n"
        assert actual == expected, path + ": diferencias de despliegue"
    repeat = subprocess.run(command + ["--apply"], capture_output=True, text=True)
    assert repeat.returncode == 0, repeat.stdout + repeat.stderr
    assert "YA INSTALADO" in repeat.stdout
    # Protección ante personalizaciones dentro del bloque que se modificaría.
    conflict = live / FILES[0]
    conflict.write_text(old(FILES[0]).replace(
        "if (next.direction === 'incoming') {",
        "if (next.direction === 'incoming' && /* personalizado */ true) {"
    ))
    before = conflict.read_text()
    rejected = subprocess.run(command + ["--check"], capture_output=True, text=True)
    assert rejected.returncode != 0 and "ABORTADO" in rejected.stdout, rejected.stdout
    assert conflict.read_text() == before
print("Despliegue PBX: OK (check, apply, conservación, idempotencia, conflicto).")
