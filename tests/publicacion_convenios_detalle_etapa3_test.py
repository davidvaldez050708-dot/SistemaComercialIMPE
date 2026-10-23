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
MANIFEST = ROOT / 'tools/convenios_detalle_etapa3_release.json'
PUBLISHER = ROOT / 'tools/publicar_aliados_registro.py'
FILES = {
    'app/services/AliadoRegistroService.php',
    'app/views/convocatorias/crear_desde_convenio.php',
    'public/css/aliados_v2_extension.css',
    'public/javascript/crear_convocatoria.js',
    'public/javascript/crear_convocatoria_v2_extension.js',
}


class ConveniosDetalleSelectivo(unittest.TestCase):
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
            if row['base'] is None:
                originals[row['path']] = None
                continue
            data = gzip.decompress(base64.b64decode(row['base'])).replace(b'\r\n', b'\n')
            if customized:
                # Personalizaciones de cabecera ajenas a las secciones modificadas.
                if row['path'].endswith('.php'):
                    data=data.replace(b'<?php',b'<?php\n// Personalizacion Hostinger preservada\n',1)
                else:
                    data=b'/* Personalizacion Hostinger preservada */\n'+data
            if crlf:
                data = data.replace(b'\n', b'\r\n')
            file = self.site / row['path']
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(data)
            originals[row['path']] = data
        # Se conservan CSS y componentes de Canales ya publicados en la etapa 1.
        for name in ['public/css/aliados.css','app/views/aliados/partials/registro_gestion.php','public/javascript/aliados_registro.js','app/services/AliadoCompartirService.php','app/controllers/AliadoController.php','public/javascript/aliados.js','app/views/aliados/estado.php']:
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
            self.assertEqual((self.site / name).read_bytes() if (self.site / name).exists() else None, data)
        out = self.run_tool('--apply')
        for row in self.manifest['files']:
            data = (self.site / row['path']).read_bytes()
            target = gzip.decompress(base64.b64decode(row['target'])).replace(b'\r\n', b'\n')
            actual = data.replace(b'\r\n', b'\n')
            clean=actual.replace(b'/* Personalizacion Hostinger preservada */\n',b'').replace(b'\n// Personalizacion Hostinger preservada\n',b'')
            self.assertEqual(clean,target,row['path'])
            if customized and row['base'] is not None:
                self.assertIn(b'Personalizacion Hostinger preservada', data)
            if crlf and row['base'] is not None:
                self.assertEqual(data.count(b'\r\n'), data.count(b'\n'))
        self.assertIn('ya publicada', self.run_tool('--apply'))
        self.run_tool('--restore', out.split('Respaldo: ')[1].splitlines()[0])
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes() if (self.site / name).exists() else None, data)

    def test_check_apply_restore(self):
        self.verify()

    def test_personalizaciones_y_crlf(self):
        self.verify(crlf=True, customized=True)

    def test_conflicto_no_escribe_ningun_archivo(self):
        originals = self.install()
        name = 'public/css/aliados_v2_extension.css'
        changed = originals[name].replace(b'gap: 13px; padding: 16px 18px;', b'gap: 25px; padding: 30px;')
        self.assertNotEqual(changed, originals[name])
        (self.site / name).write_bytes(changed)
        originals[name] = changed
        self.assertIn('CONFLICTO', self.run_tool('--apply', ok=False))
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes() if (self.site / name).exists() else None, data)

    def test_publicador_python36(self):
        ast.parse(PUBLISHER.read_text(encoding='utf-8'), feature_version=(3, 6))



if __name__ == '__main__':
    unittest.main()
