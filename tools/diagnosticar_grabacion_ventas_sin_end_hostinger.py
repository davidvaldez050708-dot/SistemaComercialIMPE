#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Captura de SOLO código PHP del recorrido de grabación en Hostinger.

No lee configuración privada, credenciales, SQL, sesiones, audios ni registros.
No hace peticiones al proveedor, ni modifica public_html. Python 3.6+.
"""
import argparse
import datetime
import hashlib
import json
import os
import stat
import sys
import zipfile
from pathlib import Path

SOURCE_FILES = (
    "app/services/TelefoniaVentasLlamadasService.php",
    "app/services/TelefoniaVentasEvidenciaService.php",
    "app/services/TelefoniaPbxSegmentosService.php",
    "app/services/ZadarmaRecordingService.php",
    "app/helpers/ZadarmaPbxIdentifierHelper.php",
    "prueba_telefonia/api/grabacion_ventas.php",
)
MAX_SIZE = 1024 * 1024


def read_sources(site):
    if not (site / "index.php").is_file():
        raise RuntimeError("Ruta incorrecta: falta index.php")
    sources = {}
    manifest = []
    for rel in SOURCE_FILES:
        path = site / rel
        if path.is_symlink() or not path.is_file():
            raise RuntimeError("Archivo ausente/no regular: " + rel)
        resolved = path.resolve()
        if os.path.commonpath([str(site), str(resolved)]) != str(site):
            raise RuntimeError("Archivo fuera de public_html: " + rel)
        details = path.stat()
        if not stat.S_ISREG(details.st_mode) or details.st_size > MAX_SIZE:
            raise RuntimeError("Archivo de tipo/tamaño inesperado: " + rel)
        data = path.read_bytes()
        sources[rel] = data
        manifest.append({
            "file": rel,
            "bytes": len(data),
            "sha256": hashlib.sha256(data).hexdigest(),
            "git_blob": hashlib.sha1(
                b"blob " + str(len(data)).encode("ascii") + b"\\0" + data
            ).hexdigest(),
        })
    return sources, manifest


def make_zip(directory, sources, manifest):
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(str(directory), 0o700)
    timestamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    destination = directory / (
        "impe_ventas_grabacion_154_" + timestamp + "_" +
        os.urandom(3).hex() + ".zip"
    )
    fd = os.open(str(destination), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    try:
        with os.fdopen(fd, "wb") as stream:
            with zipfile.ZipFile(stream, mode="w",
                                 compression=zipfile.ZIP_DEFLATED) as archive:
                for rel, data in sources.items():
                    archive.writestr(rel, data)
                archive.writestr("manifest.json", json.dumps({
                    "purpose": "Diagnosticar grabación de llamada comercial sin NOTIFY_OUT_END",
                    "source_files": manifest,
                    "read_only": True,
                    "contains_call_audio": False,
                    "contains_configuration": False,
                }, ensure_ascii=False, indent=2).encode("utf-8"))
                archive.writestr("LEEME.txt",
                    "Contiene exclusivamente seis archivos de código del CRM.\\n"
                    "Comprueba su contenido antes de compartirlo.\\n"
                    "No contiene audio, credenciales ni resultados de llamadas.\\n"
                    "No se modificó producción.\\n")
    except BaseException:
        destination.unlink()
        raise
    return destination


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--site", required=True)
    parser.add_argument("--out-dir", default=str(
        Path.home() / "diagnosticos-impe-ventas"))
    args = parser.parse_args()
    site = Path(args.site).expanduser().resolve()
    out = Path(args.out_dir).expanduser().resolve()
    if os.path.commonpath([str(site), str(out)]) == str(site):
        raise RuntimeError("El ZIP debe mantenerse fuera de public_html.")
    sources, manifest = read_sources(site)
    for item in manifest:
        print("LEIDO:", item["file"], "bytes:", item["bytes"],
              "SHA256:", item["sha256"])
    dest = make_zip(out, sources, manifest)
    print("DIAGNÓSTICO PRIVADO:", dest)
    print("SITIO INTACTO. Sin SQL, grabaciones, credenciales ni cambios.")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("ERROR:", error)
        sys.exit(1)
