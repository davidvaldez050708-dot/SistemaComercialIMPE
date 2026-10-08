#!/usr/bin/env python3
"""Evita que el endpoint de sincronización oficial exija asignaciones territoriales.

El permiso global de actualización oficial sí es obligatorio; consultar
o editar la ficha de cada Estado sigue sujeto al ámbito del usuario.
"""
from pathlib import Path
import re

controller = Path("app/controllers/DataTerritorialController.php").read_text(encoding="utf-8")
model = Path("app/models/DataTerritorialModel.php").read_text(encoding="utf-8")
view = Path("app/views/data_territorial/index.php").read_text(encoding="utf-8")

def metodo(php: str, nombre: str) -> str:
    match = re.search(r"\bfunction\s+" + re.escape(nombre) + r"\s*\(", php)
    assert match, f"No se encontró el método {nombre}"
    inicio = php.find("{", match.end())
    assert inicio >= 0, f"Falta cuerpo de {nombre}"
    nivel = 0
    for i in range(inicio, len(php)):
        if php[i] == "{":
            nivel += 1
        elif php[i] == "}":
            nivel -= 1
            if nivel == 0:
                return php[inicio:i+1]
    raise AssertionError(f"No cierra el método {nombre}")

actualizar = metodo(controller, "actualizarEscolaridadAdultaOficial")
validar = metodo(controller, "validarPermisoActualizacionOficialJson")
index = metodo(controller, "index")
puede = metodo(model, "puedeAccederEstado")

assert "validarPermisoActualizacionOficialJson()" in actualizar, (
    "Actualizar datos oficiales debe exigir el permiso global"
)
assert "tienePermiso('data_territorial.actualizar_oficial')" in validar, (
    "No eliminar la autorización de actualización"
)
assert "obtenerEstado($estadoId)" in actualizar and "if (!$estado)" in actualizar, (
    "Solo deben actualizarse Estados válidos y activos"
)
assert "puedeAccederEstado(" not in actualizar, (
    "No bloquear la actualización de datos oficiales públicos por asignación individual"
)
assert "puedeAccederEstado(" in index and "asignaciones_territorio" in puede, (
    "No cambiar el acceso a fichas de Estados ajenos"
)
assert "obtenerEstadosActivosParaActualizacionOficial()" in index, (
    "La actualización masiva debe mantener su ámbito global autorizado"
)
assert view.count('value="escolaridad_adulta"') == 2, (
    "Conservar actualización individual y masiva de los tres indicadores"
)
print("OK: autorización global para INEGI, sin alterar restricciones de fichas territoriales.")
