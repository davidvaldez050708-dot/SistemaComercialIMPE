#!/usr/bin/env python3
"""Diagnóstico de compatibilidad sin mostrar contenido privado ni modificar producción."""
import argparse
import difflib
import pathlib
import subprocess
import sys

ORIG = "9ed452e134cf15520387e8a942c9f804dfd442b4^"
NEW = "9ed452e134cf15520387e8a942c9f804dfd442b4"
P = "public/javascript/seguimiento_llamadas_expediente.js"
MARKERS = {
    "funcion_tarjeta": "const crearTarjetaHistorial = function (llamada)",
    "metadatos_duracion": "derecha.appendChild(duracion);",
    "estado_audio_anterior": "const estadoAudio = document.createElement('span');",
    "nuevo_pbx": "const pbxVerificado = /^[A-Za-z0-9_-]{6,100}$/.test(",
    "cierre_metadatos": "derecha.appendChild(estadoAudio);",
    "filtro_grabaciones": "return Boolean(llamada.tiene_grabacion && llamada.grabacion_url);",
    "panel_audio": "if (panelAudio) cuerpo.appendChild(panelAudio);",
    "ficha_termina": "tarjeta.appendChild(icono);",
}


def git(repo, ref):
    return subprocess.check_output(
        ["git", "-C", str(repo), "show", ref + ":" + P],
        stderr=subprocess.DEVNULL,
    ).decode("utf-8")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--live", required=True)
    args = parser.parse_args()
    repo = pathlib.Path(__file__).resolve().parents[1]
    path = pathlib.Path(args.live).expanduser().resolve() / P
    if not path.is_file():
        parser.error("No existe el archivo de vista en la ruta indicada")
    try:
        actual = path.read_text(encoding="utf-8")
        original = git(repo, ORIG)
        nuevo = git(repo, NEW)
    except (UnicodeDecodeError, subprocess.CalledProcessError):
        print("No se pudo obtener el código de comparación.", file=sys.stderr)
        return 2
    print("REVISIÓN PASIVA: grabaciones del expediente")
    print("Archivo presente: sí; sin escritura ni cambios")
    print("Código aprobado ya instalado:", "sí" if actual == nuevo else "no")
    print("Coincidencia aproximada con base:",
          f"{difflib.SequenceMatcher(None, original, actual, autojunk=False).ratio():.1%}")
    for key, marker in MARKERS.items():
        print(f"  {key}: {actual.count(marker)}")
    # Reportar la forma del conflicto, nunca texto de archivos ni IDs PBX.
    d = list(difflib.unified_diff(original.splitlines(keepends=True),
                                  nuevo.splitlines(keepends=True), n=5))
    chunks = []
    before = after = ""
    for line in d[2:]:
        if line.startswith("@@"):
            if before or after:
                chunks.append((before, after))
            before = after = ""
        elif line.startswith(" "):
            before += line[1:]
            after += line[1:]
        elif line.startswith("-"):
            before += line[1:]
        elif line.startswith("+"):
            after += line[1:]
    if before or after:
        chunks.append((before, after))
    for i, (old, new) in enumerate(chunks, 1):
        print(f"  cambio_{i}: bloque_anterior={actual.count(old)}, "
              f"bloque_nuevo={actual.count(new)}")
    print("FIN: no se modificó ni publicó ningún archivo.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
