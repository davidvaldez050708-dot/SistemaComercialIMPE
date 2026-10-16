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

# Variante real reportada por Hostinger: registrar() recibe
# referencia_llamada y numero_origen; anteriormente ignoraba el bool.
production_original = """                    (new TelefoniaTransferenciaContextoService())->registrar(
                        (int)$_SESSION['usuario_id'], (string)($_SESSION['rol'] ?? ''),
                        (string)($origen['extension'] ?? ''),
                        (int)$destino['usuario_id'], (string)$destino['extension'],
                        (int)$destino['estado_id'], (int)$data['seguimiento_id'],
                        (string)($data['referencia_llamada'] ?? ''),
                        (string)($data['numero_origen'] ?? '')
                    );
                } catch (Throwable $e) {
                    error_log('[telefonia_contexto_guardar] ' . get_class($e));
                    // La transferencia de audio se mantiene independiente.
                }
"""
base_original = """                    $contextoGuardado = (new TelefoniaTransferenciaContextoService())->registrar(
                        (int)$_SESSION['usuario_id'], (string)($_SESSION['rol'] ?? ''),
                        (string)($origen['extension'] ?? ''),
                        (int)$destino['usuario_id'], (string)$destino['extension'],
                        (int)$destino['estado_id'], (int)$data['seguimiento_id'],
                        (string)($data['pbx_call_id'] ?? '')
                    );
                    if (!$contextoGuardado) {
                        // No mostrar errores de metadatos al teléfono. Dejar
                        // evidencia técnica sin revelar datos del expediente.
                        error_log('[telefonia_contexto_transferencia] Validación de traspaso no confirmada.');
                    }
                } catch (Throwable $error) {
                    // La PBX puede transferir aunque fallen datos opcionales.
                    error_log('[telefonia_contexto_no_disponible] ' . get_class($error));
                }
"""
assert original_php.count(base_original) == 1
custom_controller = original_php.replace(base_original, production_original, 1)
custom_controller = custom_controller.replace(
    "            $pbxCallId = trim((string)($_POST['pbx_call_id'] ?? ''));",
    "            $numeroOrigen = trim((string)($_POST['numero_origen'] ?? ''));\n"
    "            $referencia = trim((string)($_POST['referencia_llamada'] ?? ''));",
    1,
)
custom_controller = custom_controller.replace(
    "                'pbx_call_id'=>$pbxCallId,",
    "                'numero_origen'=>$numeroOrigen,\n"
    "                'referencia_llamada'=>$referencia,",
    1,
)
assert "'referencia_llamada'=>$referencia" in custom_controller
assert custom_controller.count(production_original) == 1

patched_custom, custom_mode = deploy.aplicar_ajuste_selectivo(
    custom_controller, controller
)
assert custom_mode == "contexto Hostinger validado"
assert patched_custom.count("No fue posible conservar la institución confirmada.") == 2
assert "$contextoGuardado = (new TelefoniaTransferenciaContextoService())->registrar(" in patched_custom
assert "(string)($data['numero_origen'] ?? '')" in patched_custom
assert "(string)($data['referencia_llamada'] ?? '')" in patched_custom
assert "'numero_origen'=>$numeroOrigen" in patched_custom
assert "'referencia_llamada'=>$referencia" in patched_custom
assert "'pbx_call_id'=>$pbxCallId," not in patched_custom

# Comprobación de sintaxis PHP sin ejecutar ni conectar la base de datos.
import tempfile
import subprocess
with tempfile.NamedTemporaryFile(mode="w", suffix=".php", encoding="utf-8") as fixture:
    fixture.write(patched_custom)
    fixture.flush()
    check = subprocess.run(
        ["php", "-l", fixture.name],
        capture_output=True, text=True,
    )
    assert check.returncode == 0, check.stderr or check.stdout

for invalid in (
    patched_custom,
    custom_controller.replace("'referencia_llamada'=>$referencia,", "", 1),
    custom_controller.replace(production_original, "", 1),
    custom_controller.replace(production_original,
                              production_original + production_original, 1),
):
    try:
        deploy.aplicar_ajuste_selectivo(invalid, controller)
        raise AssertionError("El instalador aceptó un controlador incompatible o ya actualizado")
    except ValueError:
        pass

print("Instalador selectivo: personalizaciones preservadas y conflictos bloqueados OK")
