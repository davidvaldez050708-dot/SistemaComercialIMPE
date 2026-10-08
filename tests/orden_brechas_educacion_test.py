#!/usr/bin/env python3
"""Previene que el bloque de brechas vuelva a adelantarse al perfil prioritario."""
from pathlib import Path

view = Path("app/views/data_territorial/index.php").read_text(encoding="utf-8")
script = Path("public/javascript/educacion_objetivo.js").read_text(encoding="utf-8")
css = Path("public/css/dashboard.css").read_text(encoding="utf-8")

priority_placeholder = '<section class="data-education-target" data-education-target'
gap = '<section class="data-education-gap"'
context_official = '<div class="data-education-official">'
assert view.count(priority_placeholder) == 1, "El perfil prioritario debe reservarse solo una vez"
assert view.count(gap) == 1, "Debe existir una sola sección de brechas"
assert view.index(priority_placeholder) < view.index(gap) < view.index(context_official), (
    "El orden HTML debe ser perfil prioritario -> brechas -> contexto"
)
assert "(brecha || rezago).insertAdjacentElement('beforebegin', contenedor)" in script, (
    "El perfil debe insertarse antes de la brecha si falta su contenedor"
)
assert "(brecha || contenedor).insertAdjacentElement('afterend', contexto)" in script, (
    "El contexto adicional debe colocarse después de las brechas"
)
assert 'data-education-priority-cards is-segmented data-education-gap-cards' in view
assert view.count("class=\"data-education-priority-card data-education-gap-card") == 1, (
    "Las brechas deben reutilizar el componente visual del perfil prioritario"
)
for code in ("SIN_EDUCACION_SUPERIOR_25_MAS",
             "SIN_MEDIA_SUPERIOR_CONCLUIDA_18_MAS",
             "SIN_MEDIA_SUPERIOR_CONCLUIDA_15_17"):
    assert code in view, f"Falta el indicador {code}"
# Los detalles documentales se conservan en BD, pero no deben ocupar la vista.
brechas = view.split('<section class="data-education-gap"', 1)[1].split('<div class="data-education-official">', 1)[0]
for eliminado in (
    'id="formEscolaridadAdultaCsv"',
    'data-education-gap-import',
    'data-education-gap-source',
    'Fuente y metodología',
    'Importación manual alternativa',
):
    assert eliminado not in brechas, f"El bloque muestra un elemento eliminado: {eliminado}"
# La actualización INEGI (masiva e individual) sigue vigente.
assert view.count('value="escolaridad_adulta"') == 2, (
    "La actualización oficial individual y masiva debe seguir disponible"
)
assert '.data-education-gap-card-youth' not in css, "No restaurar tarjeta juvenil de ancho completo"
assert 'grid-template-columns: repeat(3, minmax(0, 1fr));' in css, (
    "Las tres tarjetas deben compartir el mismo grid responsive"
)
print("OK: prioridad primero, tres tarjetas, sin CSV ni fuente/metodología visible, INEGI automático vigente.")
