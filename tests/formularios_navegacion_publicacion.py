"""Verifica publicación aditiva, idempotencia, bloqueo y restauración sin servidor."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PUBLISHER = ROOT / 'tools/publicar_formularios_navegacion.py'
SPEC = importlib.util.spec_from_file_location('publisher', PUBLISHER)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
PAYLOAD = (ROOT / 'tools/formularios_navegacion_compacta.css').read_bytes()


class PublicacionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.site = self.root / 'public_html'
        self.backups = self.root / 'respaldos'
        self.css = self.site / MODULE.CSS
        self.css.parent.mkdir(parents=True)
        updated = (ROOT / MODULE.CSS).read_bytes().replace(b'\r\n', b'\n')
        self.assertTrue(updated.endswith(PAYLOAD))
        self.original = updated[:-len(PAYLOAD)]
        self.css.write_bytes(self.original)
        for relative, expected in MODULE.VIEWS.items():
            dest = self.site / relative
            dest.parent.mkdir(parents=True, exist_ok=True)
            data = (ROOT / relative).read_bytes().replace(b'\r\n', b'\n')
            self.assertEqual(MODULE.sha(data), expected)
            dest.write_bytes(data)

    def invoke(self, *args, success=True):
        result = subprocess.run([sys.executable, str(PUBLISHER), '--site', str(self.site),
                                 '--backup-root', str(self.backups), *args], capture_output=True, text=True)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result.stdout

    def test_apply_idempotence_and_restore(self):
        self.invoke('--check')
        self.assertFalse(self.backups.exists())
        self.assertEqual(self.css.read_bytes(), self.original)
        self.invoke('--apply')
        self.assertEqual(self.css.read_bytes(), self.original + PAYLOAD)
        backups = list(self.backups.glob('formularios-*'))
        self.assertEqual(len(backups), 1)
        self.assertEqual((backups[0] / 'formularios.css').read_bytes(), self.original)
        manifest = json.loads((backups[0] / 'manifest.json').read_text())
        self.assertEqual(manifest['after'], MODULE.sha(self.css.read_bytes()))
        self.invoke('--apply')
        self.assertEqual(list(self.backups.glob('formularios-*')), backups)
        self.invoke('--check')
        self.css.write_bytes(self.css.read_bytes() + b'/* cambio posterior */')
        self.invoke('--restore', str(backups[0]), success=False)
        self.css.write_bytes(self.original + PAYLOAD)
        self.invoke('--restore', str(backups[0]))
        self.assertEqual(self.css.read_bytes(), self.original)
        self.invoke('--restore', str(backups[0]))

    def test_unknown_styles_blocked(self):
        custom = self.original + b'/* desconocido */'
        self.css.write_bytes(custom)
        self.invoke('--apply', success=False)
        self.assertEqual(self.css.read_bytes(), custom)
        self.assertFalse(self.backups.exists())

    def test_replaces_previous_design_and_can_restore_it(self):
        previous = (ROOT / 'tools/formularios_navegacion_compacta_v1.css').read_bytes()
        installed = self.original + previous
        self.css.write_bytes(installed)
        self.invoke('--check')
        self.invoke('--apply')
        self.assertEqual(self.css.read_bytes(), self.original + PAYLOAD)
        self.assertNotIn(b'FORMULARIOS_NAVEGACION_COMPACTA_V1', self.css.read_bytes())
        backup = next(self.backups.glob('formularios-*'))
        self.assertEqual((backup / 'formularios.css').read_bytes(), installed)
        self.invoke('--restore', str(backup))
        self.assertEqual(self.css.read_bytes(), installed)

    def test_protected_view_blocked(self):
        view = self.site / next(iter(MODULE.VIEWS))
        view.write_bytes(view.read_bytes() + b'<!-- cambio -->')
        self.invoke('--apply', success=False)
        self.assertEqual(self.css.read_bytes(), self.original)

    def test_public_backup_rejected(self):
        self.backups = self.site / 'respaldos'
        self.invoke('--apply', success=False)
        self.assertFalse(self.backups.exists())


if __name__ == '__main__':
    unittest.main()
