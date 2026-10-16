#!/usr/bin/env python3
"""Pruebas de seguridad del instalador selectivo: no necesita MySQL ni Hostinger."""
import importlib.util
from pathlib import Path

root = Path(__file__).resolve().parents[1]
path = root / "tools/desplegar_contexto_transferencias_historial_hostinger.py"
spec = importlib.util.spec_from_file_location("deploy_contexto", path)
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)

controller = "app/controllers/TelefoniaController.php"
observer = "public/javascript/telefonia_analista_historial_pasivo.js"
original_php = deploy.git("-C", str(root), "show", deploy.BASE + ":" + controller)
original_js = deploy.git("-C", str(root), "show", deploy.BASE + ":" + observer)
marker_php = "                    if (!$contextoGuardado) {\n"
marker_js = "if (attempt < 8 && active?.id === id)"
assert original_php.count(marker_php) == 1
assert original_js.count(marker_js) == 1

# Simular modificaciones locales que desajustan los hunks de 5 líneas,
# conservando los puntos exactos de inserción.
personal_php = original_php.replace(
    marker_php, "                    // Personalización previa de producción\n" + marker_php, 1
)
personal_js = original_js.replace(
    marker_js, "/* Personalización de la tarjeta */\n        " + marker_js, 1
)
patched_php, mode_php = deploy.aplicar_ajuste_selectivo(personal_php, controller)
patched_js, mode_js = deploy.aplicar_ajuste_selectivo(personal_js, observer)
assert "Personalización previa de producción" in patched_php
assert "Personalización de la tarjeta" in patched_js
assert patched_php.count("No fue posible conservar la institución confirmada.") == 2
assert "if (attempt < 16 && active?.id === id)" in patched_js
assert mode_php == mode_js == "ajuste selectivo verificado"

# No intentar aplicar dos veces ni actuar si se perdió el punto de anclaje.
for sample, kind in [
    (patched_php, controller),
    (patched_js, observer),
    (personal_php.replace(marker_php, ""), controller),
    (personal_js.replace(marker_js, ""), observer),
]:
    try:
        deploy.aplicar_ajuste_selectivo(sample, kind)
        raise AssertionError("El instalador aceptó un caso ambiguo o ya actualizado")
    except ValueError:
        pass
print("Instalador selectivo: personalizaciones preservadas y conflictos bloqueados OK")
