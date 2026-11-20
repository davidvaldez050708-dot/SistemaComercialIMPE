import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT/'tools/publicar_formularios_fundacion_presencia.py'
SPEC = importlib.util.spec_from_file_location('publisher', SCRIPT)
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)
DELTA = (ROOT/'tools/formularios_fundacion_presencia.css').read_bytes()


class PublicacionTest(unittest.TestCase):
    def test_canonical_and_production_preserve_background_restore_and_block_edits(self):
        current = (ROOT/M.CSS).read_bytes().replace(b'\r\n', b'\n')
        self.assertTrue(current.endswith(DELTA))
        canonical = current[:-len(DELTA)]
        production = canonical.replace(
            b"url('../img/brand/red-graduacion.jpeg') center 78% / cover no-repeat;",
            b"url('../img/brand/formulario-graduacion-20261022.webp') center 93% / cover no-repeat,\n        url('../img/brand/red-graduacion.jpeg') center 93% / cover no-repeat;")
        for original in [canonical, production]:
            with self.subTest(hash=M.sha(original)), tempfile.TemporaryDirectory() as directory:
                self.assertIn(M.sha(original), M.BASES)
                site = Path(directory)/'public_html'
                css = site/M.CSS
                css.parent.mkdir(parents=True)
                css.write_bytes(original)
                backups = Path(directory)/'respaldos'
                def invoke(*args, ok=True):
                    r = subprocess.run([sys.executable, str(SCRIPT), '--site', str(site),
                                        '--backup-root', str(backups), *args], capture_output=True, text=True)
                    self.assertEqual(r.returncode == 0, ok, r.stdout+r.stderr)
                invoke('--check')
                self.assertFalse(backups.exists())
                invoke('--apply')
                self.assertEqual(css.read_bytes(), original+DELTA)
                before = list(backups.glob('formularios-*'))
                invoke('--apply')
                self.assertEqual(list(backups.glob('formularios-*')), before)
                invoke('--check')
                css.write_bytes(original+DELTA+b'/* otro cambio */')
                invoke('--restore', str(before[0]), ok=False)
                css.write_bytes(original+DELTA)
                invoke('--restore', str(before[0]))
                self.assertEqual(css.read_bytes(), original)
                css.write_bytes(original+b'/* desconocido */')
                invoke('--apply', ok=False)
                self.assertEqual(css.read_bytes(), original+b'/* desconocido */')


if __name__ == '__main__':
    unittest.main()
