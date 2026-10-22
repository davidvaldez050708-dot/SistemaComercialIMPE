#!/usr/bin/env python3
"""Validate lossless overlay for Hostinger-customized Convenios controller."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "alcance_publicador", str(ROOT / "tools" / "publicar_alcance_aliados_convenios_20261022.py"))
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)

NAME = "app/controllers/CrearConvocatoriaController.php"
BASE = "beb99ed3e9b40f1758d1493b27a8cdf40fdbd820"
SOURCE = "a5da21270d57d737f2debed9074a906d3123ead5"


def php_source(ref):
    return subprocess.check_output(["git", "show", ref + ":" + NAME], cwd=str(ROOT))


def lint_php(data):
    with tempfile.TemporaryDirectory() as tmp:
        path = Path(tmp) / "CrearConvocatoriaController.php"
        path.write_bytes(data)
        result = subprocess.run(["php", "-l", str(path)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode:
            raise AssertionError((result.stdout + result.stderr).decode("utf-8", "replace"))


class ConveniosOverlayTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.base = php_source(BASE)
        cls.desired = php_source(SOURCE)

    def test_base_target_exact_and_lint(self):
        result = module.overlay_crear_convocatoria_controller(self.base, self.base, self.desired)
        self.assertIn(b"$modelo->listarConveniosRecibidos($estadoIds)", result)
        self.assertEqual(result.count(b"$puedeCrearDesdeConvenio"), 1)
        self.assertEqual(result.count(b"public function index()"), 1)
        lint_php(result)
        self.assertEqual(module.overlay_crear_convocatoria_controller(result, self.base, self.desired), result)

    def test_custom_content_near_overlap_retained_bytewise(self):
        anchor = b"        $estados = $modelo->listarEstados();"
        self.assertEqual(self.base.count(anchor), 1)
        custom = (b"        // HOSTINGER: hook personalizado en listado\n"
                  b"        $identificadorVista = 'convenios-personalizados';\n")
        local = self.base.replace(anchor, custom + anchor, 1)
        result = module.overlay_crear_convocatoria_controller(local, self.base, self.desired)
        self.assertEqual(result.count(custom), 1)
        self.assertEqual(result.count(b"$modelo->listarConveniosRecibidos($estadoIds)"), 1)
        lint_php(result)
        self.assertEqual(module.overlay_crear_convocatoria_controller(result, self.base, self.desired), result)

    def test_crlf_customizations_and_idempotency(self):
        local = self.base.replace(
            b"        $tituloPagina = 'Crear convocatoria';",
            b"        // HOSTINGER: opción personalizada\n"
            b"        $personalizacionVista = true;\n"
            b"        $tituloPagina = 'Crear convocatoria';", 1).replace(b"\n", b"\r\n")
        result = module.overlay_crear_convocatoria_controller(local, self.base, self.desired)
        self.assertIn(b"$personalizacionVista = true;", result)
        self.assertNotIn(b"\n", result.replace(b"\r\n", b""))
        lint_php(result)
        self.assertEqual(module.overlay_crear_convocatoria_controller(result, self.base, self.desired), result)

    def test_unknown_custom_data_source_fails_closed(self):
        self.assertEqual(self.base.count(b"$modelo->listarConveniosRecibidos()"), 1)
        local = self.base.replace(b"$modelo->listarConveniosRecibidos()",
                                  b"$modelo->listarConveniosPersonalizados()", 1)
        with self.assertRaisesRegex(RuntimeError, "no reconocida"):
            module.overlay_crear_convocatoria_controller(local, self.base, self.desired)

    def test_partial_installation_fails_closed(self):
        local = self.base.replace(
            b"$modelo->listarConveniosRecibidos()",
            b"$modelo->listarConveniosRecibidos($estadoIds)", 1)
        with self.assertRaisesRegex(RuntimeError, "integración parcial"):
            module.overlay_crear_convocatoria_controller(local, self.base, self.desired)


if __name__ == "__main__":
    unittest.main()
