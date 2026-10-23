"""Entrega de sólo presentación: conciliación, conflicto y restauración real."""
import ast
import base64
import gzip
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
MANIFEST = ROOT / 'tools/canales_aliado_etapa1_release.json'
PUBLISHER = ROOT / 'tools/publicar_aliados_registro.py'
FILES = {
    'app/views/aliados/partials/registro_gestion.php',
    'public/css/aliados_v2_extension.css',
    'public/javascript/aliados_registro.js',
}


class CanalesSelectivos(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.site = self.root / 'public_html'
        self.site.mkdir()
        (self.site / 'index.php').write_bytes(b'<?php // sitio de prueba\n')
        self.manifest = json.loads(MANIFEST.read_text(encoding='utf-8'))
        self.assertEqual({r['path'] for r in self.manifest['files']}, FILES)

    def install(self, crlf=False, customized=False):
        originals = {}
        for row in self.manifest['files']:
            data = gzip.decompress(base64.b64decode(row['base'])).replace(b'\r\n', b'\n')
            if customized:
                # Cambios ajenos al modal y al bloque de estilos retirado.
                data += b'\n/* Personalizacion Hostinger preservada */\n' if row['path'].endswith(('.css', '.js')) else b'\n<!-- Personalizacion Hostinger preservada -->\n'
            if crlf:
                data = data.replace(b'\n', b'\r\n')
            file = self.site / row['path']
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(data)
            originals[row['path']] = data
        # Ni el CSS institucional personalizado ni el modal de Vinculación se publican.
        for name in ['public/css/aliados.css', 'app/views/aliados/estado.php']:
            file = self.site / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(b'Personalizacion institucional intocable\r\n')
            originals[name] = file.read_bytes()
        return originals

    def run_tool(self, *args, ok=True):
        result = subprocess.run([
            sys.executable, str(PUBLISHER), '--site', str(self.site),
            '--manifest', str(MANIFEST), '--backup-root', str(self.root / 'respaldos'),
        ] + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
        self.assertEqual(result.returncode, 0 if ok else 1, result.stdout + result.stderr)
        return result.stdout + result.stderr

    def verify(self, crlf=False, customized=False):
        originals = self.install(crlf, customized)
        self.run_tool('--check')
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes(), data)
        out = self.run_tool('--apply')
        for row in self.manifest['files']:
            data = (self.site / row['path']).read_bytes()
            target = gzip.decompress(base64.b64decode(row['target'])).replace(b'\r\n', b'\n')
            actual = data.replace(b'\r\n', b'\n')
            self.assertTrue(actual.startswith(target), row['path'])
            if customized:
                self.assertIn(b'Personalizacion Hostinger preservada', data)
            if crlf:
                self.assertEqual(data.count(b'\r\n'), data.count(b'\n'))
        self.assertIn('ya publicada', self.run_tool('--apply'))
        self.run_tool('--restore', out.split('Respaldo: ')[1].splitlines()[0])
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes(), data)

    def test_check_apply_restore(self):
        self.verify()

    def test_personalizaciones_y_crlf(self):
        self.verify(crlf=True, customized=True)

    def test_conflicto_no_escribe_ningun_archivo(self):
        originals = self.install()
        name = 'app/views/aliados/partials/registro_gestion.php'
        changed = originals[name].replace(b'modal-xl aliados-share-dialog', b'modal-fullscreen personalizado')
        self.assertNotEqual(changed, originals[name])
        (self.site / name).write_bytes(changed)
        originals[name] = changed
        self.assertIn('CONFLICTO', self.run_tool('--apply', ok=False))
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes(), data)

    def test_publicador_python36(self):
        ast.parse(PUBLISHER.read_text(encoding='utf-8'), feature_version=(3, 6))


if __name__ == '__main__':
    unittest.main()
