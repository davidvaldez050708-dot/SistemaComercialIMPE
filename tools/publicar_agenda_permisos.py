"""Publica únicamente los permisos de Agenda y conserva los cambios de Hostinger."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


BASE = "2540754bd2cbdad59de5d1462e9231c46d65b620"
ENTREGA = "2d0ccac2246c0fb40a655f18ab8dedcfcefda6b7"
BASE_SELECTOR = "b7a033486d109409200c3eb939d31067002fbb43"
ARCHIVOS_SELECTOR = (
    "app/views/seguimiento_vinculacion/partials/agenda_comentarios.php",
    "public/css/agenda_comentarios.css",
    "public/javascript/agenda_comentarios.js",
)
REPO = Path(__file__).resolve().parents[1]


def git(*args):
    return subprocess.check_output(("git", "-C", str(REPO)) + args)


def huella(datos):
    return hashlib.sha256(datos).hexdigest()


def preparar(sitio, temporal, selector):
    base_ref, entrega_ref = (BASE_SELECTOR, "HEAD") if selector else (BASE, ENTREGA)
    rutas = ARCHIVOS_SELECTOR if selector else ("app", "public")
    archivos = git("diff", "--name-only", "-z", base_ref, entrega_ref, "--", *rutas)
    cambios = {}
    for nombre in archivos.decode("ascii").split("\0"):
        if not nombre:
            continue
        ruta = sitio / nombre
        if not ruta.is_file() or ruta.resolve() != ruta:
            raise ValueError("Archivo ausente o enlazado: " + nombre)
        datos = ruta.read_bytes()
        actual = datos.replace(b"\r\n", b"\n")
        base, nuevo = [git("show", ref + ":" + nombre) for ref in (base_ref, entrega_ref)]
        if actual == nuevo:
            continue
        if actual != base:
            partes = []
            for etiqueta, contenido in (("Hostinger", actual), ("base", base), ("Agenda", nuevo)):
                parte = temporal / etiqueta
                parte.write_bytes(contenido)
                partes.append(str(parte))
            resultado = subprocess.run(
                ("git", "merge-file", "-p") + tuple(partes), stdout=subprocess.PIPE
            )
            if resultado.returncode:
                raise ValueError("Conflicto con la versión instalada: " + nombre)
            nuevo = resultado.stdout
        if nuevo == actual:
            continue
        if b"\r\n" in datos and datos.count(b"\r\n") == datos.count(b"\n"):
            nuevo = nuevo.replace(b"\n", b"\r\n")
        if nombre.endswith(".php"):
            prueba = temporal / "validar.php"
            prueba.write_bytes(nuevo)
            resultado = subprocess.run(("php", "-l", str(prueba)), stdout=subprocess.PIPE)
            if resultado.returncode:
                raise ValueError("La versión integrada no pasa la validación PHP: " + nombre)
        cambios[nombre] = (datos, nuevo)
        print("Compatible | " + nombre)
    return cambios


def publicar(sitio, raiz, cambios):
    raiz.mkdir(mode=0o700, parents=True, exist_ok=True)
    respaldo = Path(tempfile.mkdtemp(prefix="agenda-permisos-", dir=str(raiz)))
    manifiesto = {}
    for nombre, (datos, nuevo) in cambios.items():
        ruta = sitio / nombre
        anterior = respaldo / "antes" / nombre
        candidato = respaldo / "nuevo" / nombre
        anterior.parent.mkdir(parents=True, exist_ok=True)
        candidato.parent.mkdir(parents=True, exist_ok=True)
        anterior.write_bytes(datos)
        candidato.write_bytes(nuevo)
        shutil.copymode(str(ruta), str(candidato))
        shutil.copystat(str(ruta), str(anterior))
        manifiesto[nombre] = {"antes": huella(datos), "despues": huella(nuevo)}
    (respaldo / "manifiesto.json").write_text(json.dumps(manifiesto, indent=2), encoding="utf-8")

    for nombre, (datos, _) in cambios.items():
        ruta = sitio / nombre
        if ruta.resolve() != ruta or ruta.read_bytes() != datos:
            raise ValueError("El archivo cambió durante la verificación: " + nombre)

    instalados = []
    try:
        for nombre, (_, nuevo) in cambios.items():
            ruta = sitio / nombre
            os.replace(str(respaldo / "nuevo" / nombre), str(ruta))
            instalados.append(nombre)
            if ruta.read_bytes() != nuevo:
                raise OSError("Falló la verificación posterior: " + nombre)
    except (OSError, KeyboardInterrupt):
        for nombre in reversed(instalados):
            shutil.copy2(str(respaldo / "antes" / nombre), str(sitio / nombre))
        raise ValueError("Publicación interrumpida. Se restauraron los archivos. Respaldo: " + str(respaldo))

    print("Publicación verificada. Respaldo: " + str(respaldo / "antes"))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True, type=Path)
    parser.add_argument("--backup-root", type=Path)
    parser.add_argument("--selector-destinatarios", action="store_true",
                        help="Publicar únicamente el selector de destinatarios")
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    if args.apply and args.backup_root is None:
        parser.error("--apply requiere --backup-root")
    sitio = args.site.resolve()
    raiz = args.backup_root.resolve() if args.backup_root else None
    if raiz is not None and (raiz == sitio or sitio in raiz.parents):
        parser.error("El respaldo debe estar fuera del sitio")
    if not (sitio / "index.php").is_file():
        parser.error("--site debe apuntar a la raíz del CRM")

    os.umask(0o077)
    with tempfile.TemporaryDirectory(prefix="agenda-permisos-check-") as temporal:
        cambios = preparar(sitio, Path(temporal), args.selector_destinatarios)
    if not cambios:
        print("La actualización de Agenda ya está publicada.")
    elif args.check:
        print("Comprobación completa. Sitio intacto.")
    else:
        publicar(sitio, raiz, cambios)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        raise SystemExit(str(error))
