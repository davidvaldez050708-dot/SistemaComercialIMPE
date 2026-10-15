#!/usr/bin/env python3
"""Instalar consultas compartidas cuando el JS del expediente ya fue actualizado
por el instalador personalizado de audio. Nunca pisa este archivo.
"""
import importlib.util
import pathlib
import sys

HERE = pathlib.Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location(
    "historial_base", HERE / "desplegar_historial_reintentos_hostinger.py"
)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

ARCHIVO_JS = "public/javascript/seguimiento_llamadas_expediente.js"
ARCHIVOS = (
    "public/javascript/seguimiento_historial_consulta.js",
    "public/javascript/seguimiento_grabaciones.js",
    "app/views/layout/dashboard_footer.php",
)
MARCAS = (
    "IMPE_CONSULTAR_LLAMADAS_EXPEDIENTE(seguimientoId)",
    "grabacion_transferida_pbx",
    "const crearTarjetaHistorial = function (llamada)",
)


def inspeccion():
    try:
        i = sys.argv.index("--live")
        root = pathlib.Path(sys.argv[i+1]).expanduser().resolve()
    except (ValueError, IndexError):
        print("Indica --live RUTA con --check o --apply.", file=sys.stderr)
        return False
    archivo = root / ARCHIVO_JS
    if not archivo.is_file():
        print("CONFLICTO: archivo de expediente ausente.", file=sys.stderr)
        return False
    try:
        content = archivo.read_text(encoding="utf-8")
    except UnicodeDecodeError:
        print("CONFLICTO: JS incompatible con UTF-8.", file=sys.stderr)
        return False
    for item in MARCAS:
        if content.count(item) != 1:
            print("CONFLICTO: archivo personalizado no tiene marcador único: " + item, file=sys.stderr)
            print("No se modifica ningún archivo.", file=sys.stderr)
            return False
    print("COMPATIBLE:", ARCHIVO_JS, "(ya integra API compartida y audio PBX; preservar)")
    return True


if __name__ == "__main__":
    if not inspeccion():
        sys.exit(2)
    base.FILES = ARCHIVOS
    sys.exit(base.main())
