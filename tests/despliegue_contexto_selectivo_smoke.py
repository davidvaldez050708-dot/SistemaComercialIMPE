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
# Variante observada en el servidor de Hostinger (sin descargar código
# privado): fetchContext con nueve reintentos de 750 milisegundos.
live_js = original_js.replace(
    "fetchReceptionContext", "fetchContext"
).replace(
    "if (attempt < 8 && active?.id === id)",
    "if (attempt < 9 && active?.id === id)"
).replace(
    "root.setTimeout?.(() => {\n                    void fetchContext(id, phone, attempt + 1);\n                }, 800);",
    "root.setTimeout?.(() => void fetchContext(id, phone, attempt + 1), 750);"
)
assert "if (attempt < 9 && active?.id === id)" in live_js
assert "root.setTimeout?.(() => void fetchContext(id, phone, attempt + 1), 750);" in live_js
live_js = live_js.replace(
    "const fetchContext = async",
    "// Adaptación Hostinger: mantener intacta\n    const fetchContext = async",
    1
)
patched_live, mode_live = deploy.aplicar_ajuste_selectivo(live_js, observer)
assert mode_live == "ajuste selectivo verificado"
assert "Adaptación Hostinger: mantener intacta" in patched_live
assert "if (attempt < 17 && active?.id === id)" in patched_live
assert "root.setTimeout?.(() => void fetchContext(id, phone, attempt + 1), 750);" in patched_live
assert "if (attempt < 9 && active?.id === id)" not in patched_live
for ambiguous in (
    patched_live,
    live_js.replace("if (attempt < 9 && active?.id === id)", ""),
    live_js.replace(
        "if (attempt < 9 && active?.id === id)",
        "if (attempt < 9 && active?.id === id) /* repetido */\n"
        "if (attempt < 9 && active?.id === id)"
    ),
):
    try:
        deploy.aplicar_ajuste_selectivo(ambiguous, observer)
        raise AssertionError("Se aceptó un observador ambiguo o ya actualizado")
    except ValueError:
        pass

print("Instalador selectivo: personalizaciones preservadas y conflictos bloqueados OK")
