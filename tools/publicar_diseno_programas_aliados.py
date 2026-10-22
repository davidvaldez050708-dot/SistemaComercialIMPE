#!/usr/bin/env python3
"""Publicación selectiva de la mejora visual de programas; Python >=3.6.

Sólo cambia dos archivos, nunca realiza SQL ni modifica Telefonía/Inscripción.
--check no escribe. --apply exige la versión exacta del publicador anterior.
--restore necesita el respaldo exacto generado por --apply.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
from urllib.request import Request, urlopen

REVISION = "8b790e1cca121298efcd5905b92318a38dfb8bb9"
BASE = ("https://raw.githubusercontent.com/davidvaldez050708-dot/"
        "SistemaComercialIMPE/" + REVISION + "/")
ARCHIVOS = {
    "app/views/aliados/partials/registro_gestion.php": {
        "antes": "40a62ef0ffa5421c1cd05a4ccf654650cd2cd6e1",
        "despues": "20f2dacd497f97e7c3faa32541a232dd03fdd353"
    },
    "public/css/aliados.css": {
        "antes": "b302fded702b217bd69d8b1ec38deb3fbbe1a59e",
        "despues": "f20f115e21eadb121aba9d7770cdb4c5742fa1ad"
    }
}


def normalizar(contenido):
    return contenido.replace(b"\r\n", b"\n")


def git_blob(contenido):
    limpio = normalizar(contenido)
    cabecera = ("blob " + str(len(limpio)) + "\0").encode("ascii")
    return hashlib.sha1(cabecera + limpio).hexdigest()


def leer_archivo(sitio, relativo):
    destino = sitio / relativo
    if destino.is_symlink() or not destino.is_file():
        raise RuntimeError("Archivo ausente o enlace simbólico: " + relativo)
    if os.path.commonpath([str(sitio), str(destino.resolve())]) != str(sitio):
        raise RuntimeError("Archivo fuera del sitio: " + relativo)
    return destino.read_bytes()


def validar_sitio(sitio):
    if not sitio.is_dir():
        raise RuntimeError("La ruta del sitio no existe.")
    estados = {}
    for relativo, huellas in ARCHIVOS.items():
        contenido = leer_archivo(sitio, relativo)
        sha = git_blob(contenido)
        if sha == huellas["antes"]:
            estados[relativo] = "antes"
        elif sha == huellas["despues"]:
            estados[relativo] = "despues"
        else:
            raise RuntimeError("CONFLICTO: archivo personalizado o inesperado: " +
                               relativo + " (" + sha + "). No reemplazarlo.")
    if len(set(estados.values())) != 1:
        raise RuntimeError("CONFLICTO: los dos archivos tienen versiones mezcladas.")
    return estados


def reemplazar(destino, contenido):
    modo = stat.S_IMODE(destino.stat().st_mode)
    descriptor, temporal = tempfile.mkstemp(prefix=".programas-", dir=str(destino.parent))
    try:
        os.chmod(temporal, modo)
        with os.fdopen(descriptor, "wb") as archivo:
            archivo.write(contenido)
            archivo.flush()
            os.fsync(archivo.fileno())
        os.replace(temporal, str(destino))
    finally:
        if os.path.exists(temporal):
            os.unlink(temporal)


def descargar(relativo, sha, original):
    solicitud = Request(BASE + relativo, headers={"User-Agent": "IMPE-aliados-diseno"})
    with urlopen(solicitud, timeout=30) as respuesta:
        contenido = respuesta.read()
    if git_blob(contenido) != sha:
        raise RuntimeError("La fuente no coincide con el commit verificado: " + relativo)
    if b"\r\n" in original:
        return normalizar(contenido).replace(b"\n", b"\r\n")
    return contenido


def comprobar(sitio):
    estados = validar_sitio(sitio)
    for relativo, estado in estados.items():
        print(("SIN CAMBIOS" if estado == "despues" else "PENDIENTE") + ": " + relativo)
    print("REVISION COMPATIBLE. No se ha escrito en el sitio ni ejecutado SQL.")


def aplicar(sitio, carpeta):
    estados = validar_sitio(sitio)
    if all(e == "despues" for e in estados.values()):
        print("SIN CAMBIOS: el estilo nuevo ya está instalado.")
        return
    if os.path.commonpath([str(sitio), str(carpeta)]) == str(sitio):
        raise RuntimeError("El respaldo debe quedar fuera de public_html.")
    originales = {p: leer_archivo(sitio, p) for p in ARCHIVOS}
    nuevos = {p: descargar(p, ARCHIVOS[p]["despues"], originales[p]) for p in ARCHIVOS}
    carpeta.mkdir(parents=True, mode=0o700, exist_ok=True)
    respaldo = carpeta / ("diseno-programas-" +
                         datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S") +
                         "-" + str(os.getpid()))
    respaldo.mkdir(mode=0o700)
    for relativo, datos in originales.items():
        destino = respaldo / relativo
        destino.parent.mkdir(parents=True, exist_ok=True)
        destino.write_bytes(datos)
        os.chmod(str(destino), 0o600)
    (respaldo / "manifest.json").write_text(
        json.dumps({"revision": REVISION, "archivos": ARCHIVOS}, indent=2),
        encoding="utf-8")
    os.chmod(str(respaldo / "manifest.json"), 0o600)
    instalados = []
    try:
        if any(leer_archivo(sitio, p) != originales[p] for p in ARCHIVOS):
            raise RuntimeError("Un archivo cambió durante la preparación.")
        for relativo, datos in nuevos.items():
            reemplazar(sitio / relativo, datos)
            instalados.append(relativo)
            if git_blob(leer_archivo(sitio, relativo)) != ARCHIVOS[relativo]["despues"]:
                raise RuntimeError("Fallo al verificar archivo: " + relativo)
    except Exception:
        for relativo in reversed(instalados):
            reemplazar(sitio / relativo, originales[relativo])
        raise
    print("PUBLICADO: estilo de selección de programas.")
    print("Respaldo para --restore: " + str(respaldo))
    print("No se modificó BD, Registro público, Inscripción ni Telefonía.")


def restaurar(sitio, carpeta):
    if os.path.commonpath([str(sitio), str(carpeta)]) == str(sitio):
        raise RuntimeError("Respaldo dentro del sitio: no autorizado.")
    manifest = json.loads((carpeta / "manifest.json").read_text(encoding="utf-8"))
    if manifest.get("revision") != REVISION or manifest.get("archivos") != ARCHIVOS:
        raise RuntimeError("El manifiesto del respaldo no corresponde a esta entrega.")
    estados = validar_sitio(sitio)
    if not all(e == "despues" for e in estados.values()):
        raise RuntimeError("CONFLICTO: la versión publicada cambió. No restaurar a ciegas.")
    antiguos = {}
    for relativo, hashes in ARCHIVOS.items():
        antiguos[relativo] = leer_archivo(carpeta, relativo)
        if git_blob(antiguos[relativo]) != hashes["antes"]:
            raise RuntimeError("Respaldo alterado: " + relativo)
    for relativo, original in antiguos.items():
        reemplazar(sitio / relativo, original)
    print("RESTAURADO: sólo los dos archivos del diseño.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True, help="Directorio public_html real")
    parser.add_argument("--backup-dir", default=str(Path.home() / "respaldos-impe-aliados"))
    grupo = parser.add_mutually_exclusive_group(required=True)
    grupo.add_argument("--check", action="store_true")
    grupo.add_argument("--apply", action="store_true")
    grupo.add_argument("--restore", metavar="RESPALDO")
    args = parser.parse_args()
    sitio = Path(args.site).expanduser().resolve()
    try:
        if args.check:
            comprobar(sitio)
        elif args.apply:
            aplicar(sitio, Path(args.backup_dir).expanduser().resolve())
        else:
            restaurar(sitio, Path(args.restore).expanduser().resolve())
    except Exception as error:
        print("ERROR: " + str(error), file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
