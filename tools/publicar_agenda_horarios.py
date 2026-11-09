"""Instala los avisos de Agenda sobre las variantes revisadas de Hostinger."""

import argparse
import difflib
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile


BASE = "10b875ce"
ENTREGA = "HEAD"
PRIMERA_ENTREGA = "fa814c2a"
CONTROLADOR = "app/controllers/ReminderController.php"
BARRA = "app/views/layout/topbar.php"
SCRIPT = "public/javascript/recordatorios.js"


def ejecutar(*args, **opciones):
    return subprocess.run(args, check=True, **opciones)


def reemplazar(texto, anterior, nuevo, archivo):
    if not anterior or texto.count(anterior) != 1:
        raise SystemExit("Fragmento incompatible en " + archivo + ": " + anterior[:100].strip())
    return texto.replace(anterior, nuevo, 1)


def aplicar_fragmentos(actual, anterior, nuevo, archivo):
    a = anterior.splitlines(keepends=True)
    b = nuevo.splitlines(keepends=True)
    contexto = 1 if archivo == CONTROLADOR else 3
    for grupo in difflib.SequenceMatcher(None, a, b, autojunk=False).get_grouped_opcodes(contexto):
        antes = "".join(a[grupo[0][1]:grupo[-1][2]])
        despues = "".join(b[grupo[0][3]:grupo[-1][4]])
        actual = reemplazar(actual, antes, despues, archivo)
    return actual


def adaptar(actual, anterior, nuevo, archivo):
    if archivo == BARRA:
        patron = r"(?m)^\$mostrarCentroAvisos\s*=[^;]*;"
        actual, cantidad = re.subn(patron, "$mostrarCentroAvisos = !empty($_SESSION['usuario_id']);", actual)
        if cantidad != 1:
            raise SystemExit("No se identificó la asignación de la campana en " + archivo)
        return actual

    if archivo == CONTROLADOR:
        patron = r"(?m)^        if \(\n            \$usuarioId <= 0 \|\|\n.*?^        \) \{"
        base = re.search(patron, anterior, re.S).group()
        instalado = re.search(patron, actual, re.S)
        if not instalado or "$puedeComentariosAgenda" not in instalado.group():
            raise SystemExit("No se identificó la autorización anterior de Agenda en " + archivo)
        inicio, fin = instalado.span()
        actual = actual[:inicio] + base + actual[fin:]
        # Conservar el permiso de tareas de Aliados que precede a este bloque.
        permiso = "        $puedeComentariosAgenda = tienePermiso('reuniones.ver');\n"
        anterior = reemplazar(anterior, permiso, "", archivo)
        actual = reemplazar(actual, permiso, "", archivo)

    if archivo == SCRIPT:
        marcador = "+(Number(recordatorio.agenda_comentario_id)>0?"
        atributo = "+(recordatorio.agenda_solo_aviso?'data-agenda-solo-aviso=\"1\" ':'')"
        linea = next(linea for linea in anterior.splitlines(keepends=True) if marcador in linea)
        nueva_linea = next(linea for linea in nuevo.splitlines(keepends=True) if marcador in linea)
        nuevo = reemplazar(nuevo, nueva_linea, linea, archivo)
        actual = reemplazar(actual, marcador, atributo + marcador, archivo)

    return aplicar_fragmentos(actual, anterior, nuevo, archivo)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True, type=Path)
    parser.add_argument("--backup-root", type=Path)
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    sitio = args.site.resolve(strict=True)
    repo = Path(__file__).resolve().parent.parent
    if args.apply:
        if args.backup_root is None:
            parser.error("--apply requiere --backup-root")
        raiz = args.backup_root.resolve()
        if raiz == sitio or sitio in raiz.parents:
            parser.error("--backup-root debe estar fuera del sitio")

    javascript = (sitio / SCRIPT).read_bytes()
    instalada = b"const CLAVE_HORARIOS_MOSTRADOS" in javascript
    if instalada and b"![30, 20, 10, 5, 1].includes(minutos)" in javascript:
        print("El aviso de 1 minuto ya está instalado.")
        return
    base = PRIMERA_ENTREGA if instalada else BASE
    nombres = ejecutar("git", "diff", "--name-only", base, ENTREGA, "--", "app", "public",
                       cwd=repo, stdout=subprocess.PIPE).stdout.decode("ascii").splitlines()
    with tempfile.TemporaryDirectory() as temporal:
        temporal = Path(temporal)
        for nombre in nombres:
            ruta = sitio / nombre
            if not ruta.is_file() or ruta.resolve() != ruta:
                raise SystemExit("Archivo ausente o enlazado: " + nombre)
            datos = ruta.read_bytes()
            actual = datos.decode("utf-8").replace("\r\n", "\n")
            anterior, nuevo = [
                ejecutar("git", "show", revision + ":" + nombre, cwd=repo, stdout=subprocess.PIPE)
                .stdout.decode("utf-8").replace("\r\n", "\n") for revision in (base, ENTREGA)
            ]
            if actual == anterior:
                destino = nuevo
            elif instalada:
                destino = aplicar_fragmentos(actual, anterior, nuevo, nombre)
            else:
                destino = adaptar(actual, anterior, nuevo, nombre)
            if b"\r\n" in datos:
                destino = destino.replace("\n", "\r\n")
            for carpeta, contenido in (("antes", datos), ("despues", destino.encode("utf-8"))):
                copia = temporal / carpeta / nombre
                copia.parent.mkdir(parents=True, exist_ok=True)
                copia.write_bytes(contenido)
            if nombre.endswith(".php"):
                ejecutar("php", "-l", str(temporal / "despues" / nombre))

        resultado = subprocess.run(
            ("git", "-c", "core.autocrlf=false", "diff", "--no-index", "--binary", "--", "antes", "despues"),
            cwd=temporal, stdout=subprocess.PIPE,
        )
        if resultado.returncode != 1:
            raise SystemExit("No se pudo preparar el parche.")
        parche = resultado.stdout.replace(b"a/antes/", b"a/").replace(b"b/despues/", b"b/")
        ejecutar("git", "apply", "--check", "-", cwd=sitio, input=parche)

        if args.check:
            print("Parche de Agenda compatible con el sitio.")
            return
        raiz.mkdir(parents=True, exist_ok=True, mode=0o700)
        respaldo = Path(tempfile.mkdtemp(prefix="agenda-horarios-", dir=raiz))
        with tarfile.open(respaldo / "archivos-antes.tar.gz", "w:gz") as copia:
            for nombre in nombres:
                copia.add(sitio / nombre, arcname=nombre)
        (respaldo / "cambios.patch").write_bytes(parche)
        ejecutar("git", "apply", "-", cwd=sitio, input=parche)
        print("Publicación terminada. Copia anterior en " + str(respaldo))


if __name__ == "__main__":
    main()
