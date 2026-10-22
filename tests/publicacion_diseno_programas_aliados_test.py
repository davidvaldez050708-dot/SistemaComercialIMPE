#!/usr/bin/env python3
"""Prueba aislada del publicador visual: no accede a Hostinger ni a su BD."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "tools" / "publicar_diseno_programas_aliados.py"
spec = importlib.util.spec_from_file_location("publicador_diseno_programas", str(SCRIPT))
publicador = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publicador)

FUENTE = "bc8e5c1611e7bff62fc91e12af7166e9dc36861d"
originales = {}
actualizados = {}
for nombre, hashes in publicador.ARCHIVOS.items():
    original = subprocess.check_output(
        ["git", "show", FUENTE + ":" + nombre], cwd=str(ROOT)
    )
    actualizado = (ROOT / nombre).read_bytes()
    assert publicador.git_blob(original) == hashes["antes"], nombre + " fuente"
    assert publicador.git_blob(actualizado) == hashes["despues"], nombre + " destino"
    originales[nombre] = original
    actualizados[nombre] = actualizado

with tempfile.TemporaryDirectory(prefix="aliados-programas-test-") as temporal:
    sitio = Path(temporal) / "public_html"
    sitio.mkdir()
    respaldos = Path(temporal) / "respaldos"
    respaldos.mkdir()
    for nombre, contenido in originales.items():
        destino = sitio / nombre
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_bytes(contenido)

    publicador.comprobar(sitio)
    assert {n: (sitio / n).read_bytes() for n in originales} == originales

    def descarga_aislada(nombre, sha, original):
        contenido = actualizados[nombre]
        assert publicador.git_blob(contenido) == sha
        return contenido.replace(b"\n", b"\r\n") if b"\r\n" in original else contenido

    with patch.object(publicador, "descargar", side_effect=descarga_aislada):
        publicador.aplicar(sitio, respaldos)

    assert {n: (sitio / n).read_bytes() for n in actualizados} == actualizados
    publicador.comprobar(sitio)
    assert len(list(respaldos.iterdir())) == 1
    publicador.restaurar(sitio, next(respaldos.iterdir()))
    assert {n: (sitio / n).read_bytes() for n in originales} == originales

    # La preparación debe negarse a reemplazar personalizaciones no revisadas.
    primer_archivo = next(iter(originales))
    (sitio / primer_archivo).write_bytes(originales[primer_archivo] + b"\nCAMBIO PRIVADO\n")
    try:
        publicador.comprobar(sitio)
    except RuntimeError as error:
        assert "CONFLICTO" in str(error)
    else:
        raise AssertionError("El publicador aceptó una variante desconocida.")

print("OK: --check sin escritura, apply, integridad, restore y conflicto.")
