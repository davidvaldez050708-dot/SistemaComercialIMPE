#!/usr/bin/env python3
"""Publicación selectiva de Ventas: check/apply/restore, CRLF y protección de personalizaciones."""
import json
import os
import pathlib
import re
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tools/publicar_ventas_fin_sip_conciliacion_resultado_hostinger.sh"
data = SCRIPT.read_text(encoding="utf-8")
patches = json.loads(re.search(r"PATCHES=json.loads\(r'''(.*?)'''\)", data, re.S).group(1))
assert len(patches) == 6


def invoke(site, backup, mode, extra=None):
    env = os.environ.copy()
    env.update(IMPE_VENTAS_SALIENTE_PUBLIC=str(site), IMPE_VENTAS_SALIENTE_BACKUPS=str(backup))
    return subprocess.run(["bash", str(SCRIPT), mode] + (extra or []),
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          universal_newlines=True, env=env)


with tempfile.TemporaryDirectory() as tmp:
    base = pathlib.Path(tmp)
    site = base / "public_html"
    backup = base / "backups"
    site.mkdir()
    (site / "index.php").write_text("<?php // CRM simulado\n", encoding="utf-8")
    originals = {}
    targets = {}
    for item in patches:
        p = site / item["path"]
        p.parent.mkdir(parents=True, exist_ok=True)
        newest = (ROOT / item["path"]).read_text(encoding="utf-8")
        previous = newest
        for older, newer in item["pairs"]:
            assert previous.count(newer) == 1, ("Falta versión destino", item["path"])
            previous = previous.replace(newer, older, 1)
        assert previous != newest
        # Ediciones ajenas a estos bloques deben conservarse.
        previous += "\n/* PERSONALIZACION HOSTINGER NO TOCAR */\n"
        if item["path"].endswith("telefonia_shell.js"):
            previous = previous.replace("\n", "\r\n")
        originals[item["path"]] = previous.encode()
        expected = newest + "\n/* PERSONALIZACION HOSTINGER NO TOCAR */\n"
        if item["path"].endswith("telefonia_shell.js"):
            expected = expected.replace("\n", "\r\n")
        targets[item["path"]] = expected.encode()
        p.write_bytes(originals[item["path"]])
    result = invoke(site, backup, "--check")
    assert result.returncode == 0, (result.stdout, result.stderr)
    assert "Archivos a modificar: 6" in result.stdout
    assert all((site / p).read_bytes() == v for p, v in originals.items())
    result = invoke(site, backup, "--apply")
    assert result.returncode == 0, (result.stdout, result.stderr)
    assert all((site / p).read_bytes() == v for p, v in targets.items())
    saved = re.search(r"(?m)^Respaldo: (.*)$", result.stdout).group(1).strip()
    result = invoke(site, backup, "--check")
    assert result.returncode == 0 and "Archivos a modificar: 0" in result.stdout
    # Cambios inesperados tras publicar deben impedir una restauración destructiva.
    test_path = site / patches[1]["path"]
    test_path.write_bytes(test_path.read_bytes() + b"// concurrent edit\n")
    result = invoke(site, backup, "--restore", [saved])
    assert result.returncode != 0 and "CONFLICTO" in result.stderr
    test_path.write_bytes(targets[patches[1]["path"]])
    result = invoke(site, backup, "--restore", [saved])
    assert result.returncode == 0, (result.stdout, result.stderr)
    assert all((site / p).read_bytes() == v for p, v in originals.items())
    # Una versión ajena jamás se sobrescribe.
    test_path.write_bytes(b"// Version desconocida\n")
    result = invoke(site, backup, "--check")
    assert result.returncode != 0 and "CONFLICTO" in result.stderr
    assert test_path.read_bytes() == b"// Version desconocida\n"
print("OK: Ventas SIP registro inmediato --check, --apply, --restore, CRLF, conflictos y personalizaciones")
