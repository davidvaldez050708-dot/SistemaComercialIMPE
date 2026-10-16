#!/usr/bin/env python3
"""Prueba del instalador aislado del servicio de audio PBX."""
import pathlib
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
COMMIT = "4e749181f8baa1e3ea70d91ec11b298dad7ffd7c"
FILE = "app/services/TelefoniaGrabacionExpedienteTransferidaService.php"
SCRIPT = ROOT / "tools/desplegar_audio_nuevas_transferencias_hostinger.py"


def version(commit):
    return subprocess.check_output(
        ["git", "-C", str(ROOT), "show", commit + ":" + FILE],
        stderr=subprocess.DEVNULL,
    ).decode("utf-8")


with tempfile.TemporaryDirectory() as directory:
    live = pathlib.Path(directory) / "live"
    (live / "app").mkdir(parents=True)
    path = live / FILE
    path.parent.mkdir(parents=True, exist_ok=True)
    old = version(COMMIT + "^")
    target = version(COMMIT)
    # Cambios locales que no toquen la lógica sustituida deben sobrevivir.
    custom = "\n// Personalización Hostinger: preservar.\n"
    path.write_text(old + custom, encoding="utf-8")
    cmd = [sys.executable, str(SCRIPT), "--live", str(live)]
    check = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert check.returncode == 0, check.stdout + check.stderr
    assert path.read_text() == old + custom
    apply = subprocess.run(cmd + ["--apply"], capture_output=True, text=True)
    assert apply.returncode == 0, apply.stdout + apply.stderr
    assert path.read_text() == target + custom
    again = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert again.returncode == 0, again.stdout + again.stderr
    assert "ya actualizado" in again.stdout
    conflict_source = old.replace("if (count($transferencias) !== 1)",
                                  "if (count($transferencias) < 1)")
    if conflict_source == old:
        conflict_source = old.replace("if (count($candidatas) > 1)",
                                      "if (count($candidatas) >= 1)")
    path.write_text(conflict_source)
    fail = subprocess.run(cmd + ["--check"], capture_output=True, text=True)
    assert fail.returncode != 0 and "CONFLICTO" in fail.stdout, fail.stdout
print("Despliegue único de audio: OK (check, apply, respaldo, idempotencia y conflicto).")
