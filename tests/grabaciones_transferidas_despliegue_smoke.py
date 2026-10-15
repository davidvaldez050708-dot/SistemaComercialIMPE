#!/usr/bin/env python3
"""Prueba de instalador sin Hostinger ni credenciales."""
import pathlib
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
COMMIT = "f24f2876a4ee2b7ebdcc2755c412a30ea617ab06"
FILES = [
    "app/services/TelefoniaRecepcionMarketingService.php",
    "app/services/TelefoniaEntrantesAnalistaService.php",
    "app/views/seguimiento_vinculacion/llamadas_entrantes.php",
    "public/javascript/seguimiento_entrantes_analista.js",
    "public/javascript/telefonia_recepcion_marketing.js",
]
NEW = "prueba_telefonia/api/grabacion_transferida_pbx.php"
SCRIPT = ROOT / "tools/desplegar_grabaciones_transferidas_hostinger.py"


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
        if path.endswith("TelefoniaRecepcionMarketingService.php"):
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
        if path.endswith("TelefoniaRecepcionMarketingService.php"):
            expected += "\n// Personalización de Hostinger que debe conservarse.\n"
        assert actual == expected, path + ": diferencias de despliegue"
    repeat = subprocess.run(command + ["--apply"], capture_output=True, text=True)
    assert repeat.returncode == 0, repeat.stdout + repeat.stderr
    assert "YA INSTALADO" in repeat.stdout
    # Protección ante personalizaciones dentro del bloque que se modificaría.
    conflict = live / FILES[0]
    conflict.write_text(old(FILES[0]).replace(
        "!$call['transferida'] && $call['contestada'] &&",
        "!$call['transferida'] && /* personalizado */ $call['contestada'] &&"
    ))
    before = conflict.read_text()
    rejected = subprocess.run(command + ["--check"], capture_output=True, text=True)
    assert rejected.returncode != 0 and "ABORTADO" in rejected.stdout, rejected.stdout
    assert conflict.read_text() == before
print("Despliegue PBX: OK (check, apply, conservación, idempotencia, conflicto).")
