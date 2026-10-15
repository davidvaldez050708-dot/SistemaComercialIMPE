#!/usr/bin/env python3
"""Prueba aislada de despliegue con historial legacy y cambios locales."""
import importlib.util
import pathlib
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
COMMIT = "b4a685bc2223e6aaa84326bbeeb57bdfa855b989"
SCRIPT = ROOT / "tools/desplegar_historial_consulta_legacy_hostinger.py"
JS = "public/javascript/seguimiento_llamadas_expediente.js"
OTHER = (
    "public/javascript/seguimiento_grabaciones.js",
    "app/views/layout/dashboard_footer.php",
)
NEW = "public/javascript/seguimiento_historial_consulta.js"


def git(ref, file):
    return subprocess.check_output(
        ["git", "-C", str(ROOT), "show", ref + ":" + file],
        stderr=subprocess.DEVNULL,
    ).decode("utf-8")


spec = importlib.util.spec_from_file_location("legacy", SCRIPT)
legacy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(legacy)
original = git(COMMIT + "^", JS)
target = git(COMMIT, JS)
# Personalización realista cercana al bloque sustituido, en líneas de
# contexto de los hunks, para que la antigua instalación falle.
needle = "const grabaciones = llamadas.filter(function (llamada) {"
assert original.count(needle) == 1
personal = "// Hostinger: lógica de tarjetas de llamadas personalizada.\n                            "
custom = original.replace(needle, personal + needle)
patched, mode = legacy.patch(custom, original, target)
assert "consulta compatible" in mode
assert personal in patched
assert patched.replace(personal, "") == target
same, again_mode = legacy.patch(patched, original, target)
assert same == patched and "ya instalada" in again_mode
broken = custom.replace(
    "const anterior = window.IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE;",
    "const anterior = window.OTRO_HISTORIAL;"
)
try:
    legacy.patch(broken, original, target)
except ValueError:
    pass
else:
    raise AssertionError("Un bloque incompatible no se debe instalar")

with tempfile.TemporaryDirectory() as directory:
    live = pathlib.Path(directory) / "live"
    for file in (JS,) + OTHER:
        path = live / file
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(custom if file == JS else git(COMMIT + "^", file))
    cmd = [sys.executable, str(SCRIPT), "--live", str(live)]
    check = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert check.returncode == 0, check.stdout + check.stderr
    assert not (live / NEW).exists()
    apply = subprocess.run(cmd + ["--apply"], capture_output=True, text=True)
    assert apply.returncode == 0, apply.stdout + apply.stderr
    assert (live / JS).read_text() == patched
    assert (live / NEW).read_text() == git(COMMIT, NEW)
    for file in OTHER:
        assert (live / file).read_text() == git(COMMIT, file)
    retry = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert retry.returncode == 0, retry.stdout + retry.stderr

print("Historial legacy Hostinger: OK (fusión, personalización, respaldo, recheck y rechazo).")
