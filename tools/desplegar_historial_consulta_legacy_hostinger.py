#!/usr/bin/env python3
"""Actualización conservadora del historial antiguo de Hostinger.

Admite el historial AJAX previo a la consulta compartida, sin reemplazar
el JS personalizado ni modificar la lógica PBX o las tarjetas existentes.
El instalador base realiza --check, copia de seguridad y sustitución atómica.
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
original_merge = base.aplicar_hunks

JS_START = "const cargar = function () {"
JS_END = "solicitud\n                        .then(function (data) {"
TARGET_API = "window.IMPE_CONSULTAR_LLAMADAS_EXPEDIENTE(seguimientoId)"


def prefix(source):
    if source.count(JS_START) != 1:
        raise ValueError("Ancla 'cargar' ausente o repetida")
    left = source.index(JS_START)
    right = source.find(JS_END, left)
    if right < 0 or source.count(JS_END) != 1:
        raise ValueError("Fin de la solicitud ausente o repetido")
    return source[left:right]


def patch(actual, original, nuevo):
    # Otros archivos conservan validación y fusión conservadora originales.
    if JS_START not in original or JS_END not in original:
        return original_merge(actual, original, nuevo)
    old = prefix(original)
    desired = prefix(nuevo)
    if TARGET_API in actual:
        if actual.count(TARGET_API) != 1 or actual.count(JS_START) != 1:
            raise ValueError("Carga compartida parcial o duplicada")
        if prefix(actual) != desired:
            raise ValueError("La carga compartida está personalizada: no se sobrescribe")
        return actual, "consulta compartida ya instalada; personalizaciones intactas"
    if actual.count(old) != 1:
        raise ValueError("Bloque de consulta antigua personalizado: no sobrescribir")
    output = actual.replace(old, desired, 1)
    if output.count(TARGET_API) != 1:
        raise ValueError("La integración no es única")
    if actual == original and output != nuevo:
        raise ValueError("El parche no reproduce el código aprobado")
    return output, "consulta compatible; tarjetas y audio personalizados intactos"


base.aplicar_hunks = patch

if __name__ == "__main__":
    sys.exit(base.main())
