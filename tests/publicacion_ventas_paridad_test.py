#!/usr/bin/env python3
"""Regresión del publicador reversible para formulario comercial (réplicas, sin producción)."""
import json
import os
import pathlib
import re
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tools/publicar_ventas_paridad_resultado_hostinger.sh"
text = SCRIPT.read_text(encoding="utf-8")
specs = json.loads(re.search(r"SPECS=json.loads\(r'''(.*?)'''\)", text, re.S).group(1))
assert [x["path"] for x in specs] == [
    "public/javascript/telefonia_ventas_resultados.js",
    "app/views/telefonia/resultado_ventas_form.php",
]
for item in specs:
    assert (ROOT / item["path"]).read_text(encoding="utf-8") == item["after"]


def call(site, backup, *args):
    env = os.environ.copy()
    env.update(IMPE_CRM_PUBLIC=str(site), IMPE_CRM_BACKUPS=str(backup))
    return subprocess.run(["bash", str(SCRIPT)] + list(args), env=env,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          universal_newlines=True)


with tempfile.TemporaryDirectory() as name:
    work = pathlib.Path(name)
    site, backups = work / "public_html", work / "respaldo_privado"
    site.mkdir()
    (site / "index.php").write_text("<?php // CRM simulado\n", encoding="utf-8")
    original = {}
    for i, item in enumerate(specs):
        p = site / item["path"]
        p.parent.mkdir(parents=True, exist_ok=True)
        value = item["before"].encode()
        if i == 0:
            value = value.replace(b"\n", b"\r\n")
        original[item["path"]] = value
        p.write_bytes(value)
    check = call(site, backups, "--check")
    assert check.returncode == 0 and "Archivos a modificar: 2" in check.stdout, (check.stdout, check.stderr)
    assert not backups.exists(), "check no debe escribir respaldos"
    assert all((site / p).read_bytes() == original[p] for p in original)
    done = call(site, backups, "--apply")
    assert done.returncode == 0, (done.stdout, done.stderr)
    match = re.search(r"Respaldo: (.*)", done.stdout)
    assert match, done.stdout
    folder = pathlib.Path(match.group(1).strip())
    assert backups in folder.parents and folder.is_dir()
    assert all((site / x["path"]).read_bytes().replace(b"\r\n", b"\n") ==
               x["after"].encode() for x in specs)
    assert (site / specs[0]["path"]).read_bytes().count(b"\r\n") > 0
    repeat = call(site, backups, "--apply")
    assert repeat.returncode == 0 and "ya actualizados" in repeat.stdout
    conflict = site / specs[0]["path"]
    conflict.write_bytes(conflict.read_bytes() + b"\n// edicion posterior\n")
    denied = call(site, backups, "--restore", str(folder))
    assert denied.returncode != 0 and "CONFLICTO" in denied.stderr
    assert conflict.read_bytes().endswith(b"// edicion posterior\n")
    conflict.write_bytes(specs[0]["after"].encode().replace(b"\n", b"\r\n"))
    recovered = call(site, backups, "--restore", str(folder))
    assert recovered.returncode == 0, (recovered.stdout, recovered.stderr)
    assert all((site / p).read_bytes() == original[p] for p in original)
    outsider = site / specs[1]["path"]
    outsider.write_bytes(b"<?php // variante no revisada\n")
    before = (site / specs[0]["path"]).read_bytes()
    denied = call(site, backups, "--apply")
    assert denied.returncode != 0 and "CONFLICTO" in denied.stderr
    assert (site / specs[0]["path"]).read_bytes() == before
    assert outsider.read_bytes() == b"<?php // variante no revisada\n"
print("OK: paridad --check/--apply/--restore exacto, CRLF, idempotencia, conflicto y proteccion de produccion")
