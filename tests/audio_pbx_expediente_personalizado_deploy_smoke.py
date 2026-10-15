#!/usr/bin/env python3
"""Simula producción Hostinger con cambios locales sin revelar sus archivos."""
import importlib.util
import pathlib
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tools/desplegar_audio_pbx_expediente_personalizado_hostinger.py"
BASE = ROOT / "tools/desplegar_audio_pbx_expediente_hostinger.py"
COMMIT = "9ed452e134cf15520387e8a942c9f804dfd442b4"
JS = "public/javascript/seguimiento_llamadas_expediente.js"
OTHER = (
    "prueba_telefonia/api/llamadas_seguimiento.php",
    "public/css/seguimiento_llamadas_expediente.css",
)
NEW = "app/services/TelefoniaGrabacionExpedienteTransferidaService.php"


def git(p, rev):
    return subprocess.check_output(
        ["git", "-C", str(ROOT), "show", rev + ":" + p],
        stderr=subprocess.DEVNULL,
    ).decode()


spec = importlib.util.spec_from_file_location("personal", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
old = git(JS, COMMIT + "^")
target = git(JS, COMMIT)
custom = "\n// Hostinger: preservación de funcionalidad telefónica local.\n"
# Personalización EN MEDIO del contexto de diff, no en la sección de audio.
pattern = "const usuario = String(llamada.usuario || '').trim();"
assert old.count(pattern) == 1
local = old.replace(pattern, "// Personalización local fuera del audio\n                    " + pattern) + custom
try:
    module.original_aplicar_hunks(local, old, target)
except ValueError:
    pass
else:
    raise AssertionError("La prueba no reproduce conflicto real del instalador antiguo")
patched, mode = module.aplicar_hunks(local, old, target)
assert "anclas semánticas" in mode
assert "Personalización local fuera del audio" in patched
assert custom in patched
assert patched.replace("// Personalización local fuera del audio\n                    ", "").replace(custom,"") == target
again, mode = module.aplicar_hunks(patched, old, target)
assert again == patched and "actualizado" in mode
broken = local.replace(
    "const estadoAudio = document.createElement('span');",
    "const estadoAudio = crearEstadoPersonalizado();"
)
try:
    module.aplicar_hunks(broken, old, target)
except ValueError:
    pass
else:
    raise AssertionError("No se detectó modificación interna del audio")

with tempfile.TemporaryDirectory() as tmp:
    live = pathlib.Path(tmp) / "live"
    for p in (JS,) + OTHER:
        dst = live / p
        dst.parent.mkdir(parents=True, exist_ok=True)
        dst.write_text(local if p == JS else git(p, COMMIT + "^"))
    cmd = [sys.executable, str(SCRIPT), "--live", str(live)]
    chk = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert chk.returncode == 0, chk.stdout + chk.stderr
    assert not (live / NEW).exists()
    apply = subprocess.run(cmd + ["--apply"], capture_output=True, text=True)
    assert apply.returncode == 0, apply.stdout + apply.stderr
    assert (live / JS).read_text() == patched
    assert (live / NEW).read_text() == git(NEW, COMMIT)
    for p in OTHER:
        assert (live / p).read_text() == git(p, COMMIT)
    repeat = subprocess.run(cmd + ["--apply"], capture_output=True, text=True)
    assert repeat.returncode == 0 and "YA INSTALADO" in repeat.stdout
print("Audio personalizado Hostinger: OK (conflicto, fusión, resguardo, idempotencia y rechazo).")
