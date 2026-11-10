"""Verifica y prepara la revisión selectiva conciliada con la copia de Hostinger."""

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import tarfile
import tempfile


PAQUETE = Path(__file__).resolve().parent / "mysql_capacidad"


def huella(contenido):
    return hashlib.sha256(contenido).hexdigest()


def resolver(sitio, nombre):
    relativa = PurePosixPath(nombre)
    if relativa.is_absolute() or ".." in relativa.parts or "\\" in nombre:
        raise SystemExit("Ruta fuera del alcance: " + nombre)
    ruta = sitio / relativa
    if ruta.resolve() != ruta:
        raise SystemExit("Enlace no permitido: " + nombre)
    return ruta


def verificar(sitio, huellas):
    diferencias = []
    for nombre, esperado in huellas.items():
        ruta = resolver(sitio, nombre)
        real = huella(ruta.read_bytes()) if ruta.is_file() else None
        if ruta.is_dir() or real != esperado:
            diferencias.append(nombre)
    if diferencias:
        raise SystemExit("Conflicto de integridad. Sitio intacto:\n" + "\n".join(diferencias))


def ejecutar_parche(sitio, parche, *opciones):
    comando = ("git", "-c", "core.autocrlf=false", "apply", "--whitespace=nowarn")
    entorno = dict(os.environ, GIT_CEILING_DIRECTORIES=str(sitio.parent))
    subprocess.run(comando + opciones + ("-",), cwd=sitio, input=parche,
                   env=entorno, check=True)


def verificar_alcance(sitio, datos, parche):
    entorno = dict(os.environ, GIT_CEILING_DIRECTORIES=str(sitio.parent))
    listado = subprocess.run(("git", "apply", "--numstat", "-z", "-"),
                             cwd=sitio, input=parche, env=entorno, stdout=subprocess.PIPE, check=True)
    rutas = []
    for entrada in listado.stdout.split(b"\0"):
        if entrada:
            columnas = entrada.split(b"\t", 2)
            if len(columnas) != 3 or not columnas[2]:
                raise SystemExit("El paquete contiene un cambio de ruta no permitido.")
            rutas.append(columnas[2].decode("utf-8"))
    if sorted(rutas) != sorted(datos["antes"]) or set(rutas) != set(datos["despues"]):
        raise SystemExit("El alcance del parche no coincide con el manifiesto.")

def validar_candidato(sitio, datos, parche):
    verificar(sitio, datos["contexto"])
    verificar(sitio, datos["antes"])
    ejecutar_parche(sitio, parche, "--check")
    with tempfile.TemporaryDirectory() as carpeta:
        prueba = Path(carpeta).resolve()
        for nombre, hash_antes in datos["antes"].items():
            destino = resolver(prueba, nombre)
            destino.parent.mkdir(parents=True, exist_ok=True)
            if hash_antes is not None:
                shutil.copyfile(resolver(sitio, nombre), destino)
        ejecutar_parche(prueba, parche)
        verificar(prueba, datos["despues"])
        for nombre in datos["despues"]:
            if nombre.endswith(".php"):
                subprocess.run(("php", "-l", str(resolver(prueba, nombre))), check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", type=Path, required=True)
    parser.add_argument("--backup-root", type=Path)
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--apply", action="store_true")
    modo.add_argument("--restore", type=Path)
    args = parser.parse_args()
    sitio = args.site.resolve(strict=True)
    datos = json.loads((PAQUETE / "huellas.json").read_text(encoding="utf-8"))
    parche = (PAQUETE / "hostinger.patch").read_bytes()
    if huella(parche) != datos["parche"]:
        raise SystemExit("El parche no coincide con su huella.")

    verificar_alcance(sitio, datos, parche)

    if args.restore:
        respaldo = args.restore.resolve(strict=True)
        registro = json.loads((respaldo / "publicacion.json").read_text(encoding="utf-8"))
        if registro != {"sitio": str(sitio), "parche": datos["parche"]}:
            raise SystemExit("El respaldo no corresponde a este sitio y esta revisión.")
        verificar(sitio, datos["despues"])
        ejecutar_parche(sitio, parche, "--reverse", "--check")
        ejecutar_parche(sitio, parche, "--reverse")
        verificar(sitio, datos["antes"])
        print("Archivos anteriores restaurados.")
        return

    if args.apply and not datos["apto_para_publicar"]:
        raise SystemExit("Aplicación bloqueada: validación funcional y de capacidad pendiente.")
    validar_candidato(sitio, datos, parche)
    if args.check:
        print("Huellas, parche y sintaxis verificados. Sitio intacto.")
        return
    if args.backup_root is None:
        parser.error("--apply requiere --backup-root")
    raiz = args.backup_root.resolve()
    if raiz == sitio or sitio in raiz.parents:
        parser.error("El respaldo debe guardarse fuera del sitio")
    raiz.mkdir(parents=True, exist_ok=True, mode=0o700)
    respaldo = Path(tempfile.mkdtemp(prefix="mysql-capacidad-", dir=raiz))
    with tarfile.open(respaldo / "archivos-antes.tar.gz", "w:gz") as copia:
        for nombre, hash_antes in datos["antes"].items():
            if hash_antes is not None:
                copia.add(resolver(sitio, nombre), arcname=nombre)
    (respaldo / "publicacion.json").write_text(json.dumps(
        {"sitio": str(sitio), "parche": datos["parche"]}), encoding="utf-8")
    verificar(sitio, datos["contexto"])
    verificar(sitio, datos["antes"])
    ejecutar_parche(sitio, parche)
    verificar(sitio, datos["despues"])
    print("Publicación completada. Respaldo: " + str(respaldo))


if __name__ == "__main__":
    main()
