"""Publica únicamente el cambio de Respaldo desde el clon de preparación."""

import argparse
from difflib import SequenceMatcher
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile


EXISTENTES = (
    "index.php",
    "app/views/layout/topbar.php",
    "app/views/layout/my_profile_modal.php",
)
NUEVOS = (
    "app/controllers/RespaldoController.php",
    "app/services/RespaldoBaseDatos.php",
    "app/views/respaldo/index.php",
)
MENU = (
    "app/views/layout/sidebar.php",
    "app/views/layout/topbar.php",
    "app/views/layout/my_profile_modal.php",
)


def ejecutar(*comando, **opciones):
    return subprocess.run(comando, check=True, **opciones)


def adaptar_menu(sitio, repo, commit):
    originales = {nombre: (sitio / nombre).read_bytes() for nombre in MENU}
    sidebar = originales[MENU[0]]
    if b"controller=respaldo" in sidebar:
        raise SystemExit("El menú lateral ya contiene un enlace a Respaldo.")

    reportes = re.compile(
        rb'<a\s+href="<\?= BASE_URL \?>index\.php\?controller=reporte(?:&amp;|&)action=index"'
        rb'\s+class="sidebar-link <\?= \$claseReportes \?>">\s*<i[^>]*></i>\s*Reportes\s*</a>[ \t]*\r?\n'
        rb'(?P<sangria>[ \t]*)<\?php endif; \?>'
    )
    salto = b"\r\n" if b"\r\n" in sidebar else b"\n"

    def agregar(enlace):
        sangria = enlace.group("sangria")
        lineas = (
            b"<?php if ((int)($_SESSION['rol_id'] ?? 0) === 1): ?>",
            b'    <a href="<?= BASE_URL ?>index.php?controller=respaldo&amp;action=index"',
            b'       class="sidebar-link <?= $opcionActiva === \'respaldo\' ? \'active\' : \'\' ?>">',
            b'        <i class="bi bi-database"></i>',
            b"        Respaldo",
            b"    </a>",
            b"<?php endif; ?>",
        )
        return enlace.group() + salto * 2 + salto.join(sangria + linea for linea in lineas)

    actualizado, enlaces = reportes.subn(agregar, sidebar)
    if enlaces != 2:
        raise SystemExit("No se identificaron los dos enlaces de Reportes en el menú lateral.")
    cambios = {MENU[0]: actualizado}

    for nombre in MENU[1:]:
        contenido = originales[nombre]
        if b"controller=respaldo" not in contenido:
            continue
        antes = ejecutar("git", "show", "6049d55c:" + nombre, cwd=repo, stdout=subprocess.PIPE).stdout.splitlines()
        despues = ejecutar("git", "show", commit + ":" + nombre, cwd=repo, stdout=subprocess.PIPE).stdout.splitlines()
        eliminados = [
            antes[inicio:fin]
            for operacion, inicio, fin, _, _ in SequenceMatcher(None, antes, despues).get_opcodes()
            if operacion == "delete"
        ]
        salto = b"\r\n" if b"\r\n" in contenido else b"\n"
        for lineas in eliminados:
            if any(b"controller=respaldo" in linea for linea in lineas):
                bloque = salto.join(lineas) + salto
                if contenido.count(bloque) == 1:
                    cambios[nombre] = contenido.replace(bloque, b"", 1)
                    break
        if nombre not in cambios or b"controller=respaldo" in cambios[nombre]:
            raise SystemExit("No se pudo identificar el bloque de Respaldo en " + nombre)

    with tempfile.TemporaryDirectory() as directorio:
        temporal = Path(directorio)
        for nombre, contenido in cambios.items():
            for carpeta, datos in (("antes", originales[nombre]), ("despues", contenido)):
                archivo = temporal / carpeta / nombre
                archivo.parent.mkdir(parents=True, exist_ok=True)
                archivo.write_bytes(datos)
            ejecutar("php", "-l", input=contenido)
        resultado = subprocess.run(
            ("git", "diff", "--no-index", "--binary", "--", "antes", "despues"),
            cwd=temporal, stdout=subprocess.PIPE,
        )
        if resultado.returncode != 1:
            raise SystemExit("No se pudo generar el parche para el menú instalado.")
        return resultado.stdout.replace(b"a/antes/", b"a/").replace(b"b/despues/", b"b/")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True, type=Path)
    parser.add_argument("--backup-root", type=Path)
    alcance = parser.add_mutually_exclusive_group()
    alcance.add_argument("--menu", action="store_true", help="Publica el traslado de Respaldo al menú lateral")
    alcance.add_argument("--vista", action="store_true", help="Publica solo la vista de Respaldo")
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    sitio = args.site.resolve(strict=True)
    if args.apply:
        if args.backup_root is None:
            parser.error("--apply requiere --backup-root")
        raiz = args.backup_root.resolve()
        if raiz == sitio or sitio in raiz.parents:
            parser.error("--backup-root debe estar fuera del sitio")

    repo = Path(__file__).resolve().parent.parent
    if args.menu:
        existentes = archivos = MENU
    elif args.vista:
        existentes = archivos = ("app/views/respaldo/index.php",)
    else:
        existentes, archivos = EXISTENTES, EXISTENTES + NUEVOS
    filtro = () if args.menu or args.vista else ("--diff-filter=A",)
    archivo_commit = archivos[0] if args.menu or args.vista else NUEVOS[0]
    commit = ejecutar(
        "git", "log", "-1", "--format=%H", *filtro, "HEAD", "--", archivo_commit,
        cwd=repo, stdout=subprocess.PIPE,
    ).stdout.decode("ascii").strip()
    if not commit:
        parser.error("El clon de preparación no contiene el commit de Respaldo")

    revision = ("diff", "6049d55c", commit) if args.menu else ("show", "--format=", commit)
    parche = ejecutar("git", *revision, "--binary", "--", *archivos, cwd=repo, stdout=subprocess.PIPE).stdout
    if not parche:
        parser.error("El parche de Respaldo está vacío")

    for nombre in archivos:
        contenido = ejecutar("git", "show", f"{commit}:{nombre}", cwd=repo, stdout=subprocess.PIPE).stdout
        ejecutar("php", "-l", input=contenido)

    aplicar = ("git", "apply", "--", "-")
    comprobar = ("git", "apply", "--check", "--", "-")
    if args.menu:
        compatible = subprocess.run(
            comprobar, cwd=sitio, input=parche, stderr=subprocess.PIPE,
        ).returncode == 0
        sin_enlaces_perfil = all(b"controller=respaldo" not in (sitio / nombre).read_bytes() for nombre in MENU[1:])
        if not compatible and sin_enlaces_perfil and b"controller=respaldo" in (sitio / MENU[0]).read_bytes():
            # Una instalación intermedia ya retiró los enlaces del perfil.
            parche = ejecutar(
                "git", "show", "--format=", "--binary", commit, "--", MENU[0],
                cwd=repo, stdout=subprocess.PIPE,
            ).stdout
        elif not compatible:
            parche = adaptar_menu(sitio, repo, commit)
    ejecutar(*comprobar, cwd=sitio, input=parche)
    if args.check:
        print("Parche compatible con el sitio.")
        return

    raiz.mkdir(parents=True, exist_ok=True, mode=0o700)
    respaldo = Path(tempfile.mkdtemp(prefix="respaldo-bd-", dir=raiz))
    with tarfile.open(respaldo / "archivos-antes.tar.gz", "w:gz") as copia:
        for nombre in existentes:
            copia.add(sitio / nombre, arcname=nombre)
    (respaldo / "cambios.patch").write_bytes(parche)
    print(f"Copia del código anterior: {respaldo}", flush=True)
    ejecutar(*aplicar, cwd=sitio, input=parche)

    try:
        for nombre in archivos:
            ejecutar("php", "-l", nombre, cwd=sitio)
    except subprocess.CalledProcessError:
        ejecutar("git", "apply", "--reverse", "--", "-", cwd=sitio, input=parche)
        raise
    print("Publicación terminada.")


if __name__ == "__main__":
    main()
