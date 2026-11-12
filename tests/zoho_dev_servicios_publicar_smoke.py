#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Pruebas offline: publicador solo crea 3 archivos exactos y revierte sin dañar otros."""
import importlib.util
from pathlib import Path
import tempfile

script = Path(__file__).resolve().parents[1] / "tools/zoho_dev_servicios_publicar.py"
spec = importlib.util.spec_from_file_location("publicador", str(script))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

repo = Path(__file__).resolve().parents[1]
archivos = mod.datos_validados(repo)
assert len(archivos) == 3

with tempfile.TemporaryDirectory(prefix="zoho-publicador-ci-") as temp:
    sitio = Path(temp)
    (sitio / "app" / "services").mkdir(parents=True)
    (sitio / "tools").mkdir()
    (sitio / "app" / "models").mkdir()
    modelo = sitio / "app" / "models" / "FormularioRegistroModel.php"
    modelo.write_text("MODELO_COMERCIAL_NO_TOCAR")

    assert set(mod.planificar(sitio, archivos).values()) == {"AUSENTE"}
    assert mod.instalar_solo_nuevos(sitio, archivos) == 3
    assert set(mod.planificar(sitio, archivos).values()) == {"IDENTICO"}
    assert modelo.read_text() == "MODELO_COMERCIAL_NO_TOCAR"
    print("OK: se crean exactamente tres servicios, modelo intacto")

    try:
        mod.instalar_solo_nuevos(sitio, archivos)
    except ValueError:
        print("OK: segunda publicación bloqueada, sin sobreescritura")
    else:
        raise AssertionError("se permitió segunda publicación")

    primero = list(archivos)[0]
    (sitio / primero).write_bytes(b"<?php // PERSONALIZADO")
    try:
        mod.planificar(sitio, archivos)
    except ValueError:
        print("OK: diferencia personalizada bloquea proceso")
    else:
        raise AssertionError("se permitió alterar un archivo personalizado")
    try:
        mod.revertir_solo_identicos(sitio, archivos)
    except ValueError:
        print("OK: rollback no borra archivos modificados")
    else:
        raise AssertionError("se borró archivo personalizado")

    (sitio / primero).write_bytes(archivos[primero])
    assert mod.revertir_solo_identicos(sitio, archivos) == 3
    assert set(mod.planificar(sitio, archivos).values()) == {"AUSENTE"}
    assert modelo.read_text() == "MODELO_COMERCIAL_NO_TOCAR"
    print("OK: rollback de los tres archivos exactos, modelo conservado")

print("PUBLICADOR_ZOHO_SOLO_TRES_ARCHIVOS_OK")
