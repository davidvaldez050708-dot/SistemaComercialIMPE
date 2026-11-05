#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Diagnóstico local de tres archivos de telefonía, sin escritura en producción.

No se conecta a internet y no ejecuta SQL. Genera un ZIP en la carpeta privada
del usuario SSH para comparar el código REAL de Hostinger con el PR #213.
Compatible con Python 3.6.
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

TARGETS = (
    "app/controllers/TelefoniaController.php",
    "app/views/telefonia/ventas_llamadas.php",
    "public/javascript/telefonia_host.js",
)
LIMIT = 1024 * 1024  # 1 MB por archivo; sólo código fuente, nunca carpetas.
EXPECTED_BRANCH = "fix/ventas-cierre-automatico-registro-20261105"


def git_blob(value):
    return hashlib.sha1(
        b"blob " + str(len(value)).encode("ascii") + b"\0" + value
    ).hexdigest()


def check_source(site, relative):
    file_path = site / relative
    if file_path.is_symlink() or not file_path.is_file():
        raise RuntimeError("No existe como archivo regular: " + relative)
    if os.path.commonpath((str(site), str(file_path.resolve()))) != str(site):
        raise RuntimeError("Ruta fuera del sitio: " + relative)
    if not stat.S_ISREG(file_path.stat().st_mode):
        raise RuntimeError("Tipo de archivo no permitido: " + relative)
    if file_path.stat().st_size > LIMIT:
        raise RuntimeError("Archivo inesperadamente grande: " + relative)
    return file_path.read_bytes()


def read_snapshot(site):
    if not (site / "index.php").is_file():
        raise RuntimeError("No se reconoce public_html: falta index.php.")
    files = {}
    metadata = []
    for relative in TARGETS:
        data = check_source(site, relative)
        files[relative] = data
        metadata.append({
            "archivo": relative,
            "bytes": len(data),
            "git_blob": git_blob(data),
            "sha256": hashlib.sha256(data).hexdigest(),
        })
    return files, metadata


def create_bundle(out_dir, files, metadata):
    out_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(str(out_dir), 0o700)
    timestamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    filename = "impe_ventas_3_conflictos_" + timestamp + "_" + os.urandom(3).hex() + ".zip"
    destination = out_dir / filename
    fd = os.open(str(destination), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, "wb") as output:
            with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                for relative, data in files.items():
                    archive.writestr(relative, data)
                archive.writestr("manifest.json", json.dumps({
                    "objetivo": "Conciliación de 3 conflictos del PR #213",
                    "rama": EXPECTED_BRANCH,
                    "archivos": metadata,
                    "solo_lectura": True,
                    "no_contiene_sql_ni_archivos_de_configuracion": True,
                }, indent=2, ensure_ascii=False).encode("utf-8"))
                archive.writestr("LEEME.txt", (
                    "Paquete de diagnostico privado. No publicarlo dentro de public_html.\n"
                    "Contiene exclusivamente tres archivos de codigo del CRM.\n"
                    "Revisar su contenido antes de compartir si existen datos sensibles.\n"
                    "Ningun archivo de produccion ha sido modificado.\n"
                ))
    except BaseException:
        destination.unlink()
        raise
    return destination


def main():
    parser = argparse.ArgumentParser(description="Capturar sólo tres fuentes conflictivas de Hostinger")
    parser.add_argument("--site", required=True)
    parser.add_argument("--out-dir", default=str(Path.home() / "diagnosticos-impe-ventas"))
    parser.add_argument("--resumen", action="store_true", help="Sólo huellas, sin generar ZIP")
    arguments = parser.parse_args()
    site = Path(arguments.site).expanduser().resolve()
    out_dir = Path(arguments.out_dir).expanduser().resolve()
    files, metadata = read_snapshot(site)
    for item in metadata:
        print("LEIDO:", item["archivo"])
        print("  bytes:", item["bytes"], "blob:", item["git_blob"])
    if arguments.resumen:
        print("SITIO INTACTO. No se generó ningún archivo.")
        return
    if os.path.commonpath((str(site), str(out_dir))) == str(site):
        raise RuntimeError("El diagnóstico no puede guardarse dentro de public_html.")
    package = create_bundle(out_dir, files, metadata)
    print("DIAGNÓSTICO PRIVADO:", package)
    print("SITIO INTACTO: sin SQL, sin modificaciones ni publicación.")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("ERROR:", exc)
        sys.exit(1)
