#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Diagnóstico privado, solo lectura, del audio en Supervisión de llamadas.

Lee SOLO cuatro archivos fuente PHP del módulo Supervisión. Nunca lee BD,
llamadas, audio, configuración, tokens ni credenciales. Nunca modifica
public_html. Guarda un ZIP con permisos privados, fuera del sitio.
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

SOURCE_FILES = (
    "app/controllers/SupervisionLlamadasController.php",
    "app/services/SupervisionLlamadasRepository.php",
    "app/services/SupervisionLlamadasAudioService.php",
    "app/views/telefonia/supervision_llamadas.php",
)
MAX_SIZE = 1024 * 1024


def git_blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest()


def read_snapshot(site):
    if not (site / "index.php").is_file():
        raise RuntimeError("La ruta no corresponde al sitio: falta index.php.")
    files, hashes = {}, []
    for relative in SOURCE_FILES:
        path = site / relative
        if path.is_symlink() or not path.is_file() or not stat.S_ISREG(path.stat().st_mode):
            raise RuntimeError("Archivo inexistente o no regular: " + relative)
        if os.path.commonpath([str(site), str(path.resolve())]) != str(site):
            raise RuntimeError("Ruta no permitida: " + relative)
        if path.stat().st_size > MAX_SIZE:
            raise RuntimeError("Archivo inesperadamente grande: " + relative)
        raw = path.read_bytes()
        files[relative] = raw
        hashes.append({
            "archivo": relative,
            "bytes": len(raw),
            "git_blob": git_blob(raw),
            "sha256": hashlib.sha256(raw).hexdigest(),
        })
    return files, hashes


def save_private(out_dir, site, files, hashes):
    if os.path.commonpath([str(site), str(out_dir)]) == str(site):
        raise RuntimeError("No guardar el ZIP dentro de public_html.")
    out_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(str(out_dir), 0o700)
    suffix = datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "_" + os.urandom(3).hex()
    path = out_dir / ("supervision_154_codigo_" + suffix + ".zip")
    fd = os.open(str(path), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    try:
        with os.fdopen(fd, "wb") as fp:
            with zipfile.ZipFile(fp, "w", compression=zipfile.ZIP_DEFLATED) as z:
                for name, raw in files.items():
                    z.writestr(name, raw)
                z.writestr("manifest.json", json.dumps({
                    "proposito": "Conciliar reproducción de audio de Supervisión, sin OUT_END",
                    "archivos": hashes,
                    "solo_lectura": True,
                    "sin_datos_ni_grabaciones": True,
                }, ensure_ascii=False, indent=2).encode("utf-8"))
                z.writestr("LEEME.txt",
                           "Incluye únicamente código del módulo Supervisión.\n"
                           "No contiene grabaciones, datos comerciales ni configuración privada.\n"
                           "Revisa el contenido antes de compartir.\n"
                           "El sistema de producción sigue intacto.\n")
    except BaseException:
        path.unlink()
        raise
    return path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--site", required=True)
    parser.add_argument("--out-dir", default=str(Path.home() / "diagnosticos-impe-supervision"))
    parser.add_argument("--resumen", action="store_true")
    args = parser.parse_args()
    site = Path(args.site).expanduser().resolve()
    out_dir = Path(args.out_dir).expanduser().resolve()
    files, hashes = read_snapshot(site)
    for item in hashes:
        print("COMPATIBLE PARA DIAGNOSTICO:", item["archivo"], "blob:", item["git_blob"])
    if args.resumen:
        print("SITIO INTACTO. Sin ZIP.")
        return
    dest = save_private(out_dir, site, files, hashes)
    print("DIAGNOSTICO PRIVADO:", dest)
    print("SITIO INTACTO. No SQL, no audio, no publicación.")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("ERROR:", error)
        sys.exit(1)
