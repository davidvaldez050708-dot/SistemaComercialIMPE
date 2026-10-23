"""Entrega conjunta sobre archivos anteriores, actuales y componentes personalizados."""
import ast
import base64
import gzip
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / 'tools/publicar_convenios_v3_20261022.py'
MANIFEST = ROOT / 'tools/convenios_v3_integracion_release.json'
spec = importlib.util.spec_from_file_location('versiones', str(SCRIPT))
overlay = importlib.util.module_from_spec(spec)
spec.loader.exec_module(overlay)
unpack = lambda value: gzip.decompress(base64.b64decode(value))


class ConveniosV3Integracion(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.site = self.home / 'public_html'
        self.site.mkdir()
        (self.site / 'index.php').write_bytes(b'<?php // Replica local\n')
        self.manifest = json.loads(MANIFEST.read_text(encoding='utf-8'))

    def fixture(self, crlf=False, already_current=False):
        originals, desired = {}, {}
        for row in self.manifest['files']:
            name = row['path']
            if row.get('overlay'):
                data = (ROOT / 'tests/fixtures/v3_hostinger/aliado_programas_modular.php').read_bytes().replace(b'\r\n', b'\n')
                data = data.replace(b'<?php\n', b'<?php\n// Personalizacion productiva conservada\n', 1)
                target = overlay.versionar_componente(data)
                if already_current:
                    data = target
            else:
                source = row.get('variants', [row])[0]
                data, target = unpack(source['base']), unpack(source['target'])
                data, target = data.replace(b'\r\n', b'\n'), target.replace(b'\r\n', b'\n')
                # V3 instalado: la entrega no debe reinstalarlo innecesariamente.
                if 'multiprograma_v3.js' in name or already_current:
                    data = target
            if crlf:
                data, target = data.replace(b'\n', b'\r\n'), target.replace(b'\n', b'\r\n')
            file = self.site / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(data)
            originals[name], desired[name] = data, target
        for name in ['public/css/convocatorias_aliado_multiprograma_v3.css', 'public/css/convocatorias_aliado_modular.css', 'public/javascript/convocatorias_aliado_modular.js']:
            file = self.site / name
            file.parent.mkdir(parents=True, exist_ok=True)
            source = ROOT / name
            if not source.exists():
                source = ROOT / 'tests/fixtures/v3_hostinger' / Path(name).name
            data = source.read_bytes()
            file.write_bytes(data)
            originals[name] = desired[name] = data
        for name in ['app/views/convocatorias/partials/crear_modal.php', 'public/javascript/convocatoria_crear_compartido.js', 'app/views/layout/dashboard_head.php']:
            file = self.site / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(b'Personalizacion intocable\r\n')
            originals[name] = desired[name] = file.read_bytes()
        return originals, desired

    def run_tool(self, *args, ok=True):
        run = subprocess.run([sys.executable, str(SCRIPT), '--site', str(self.site), '--manifest', str(MANIFEST), '--backup-root', str(self.home / 'respaldos')] + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE, encoding='utf-8')
        self.assertEqual(run.returncode, 0 if ok else 1, run.stdout + run.stderr)
        return run.stdout + run.stderr

    def verify_files(self, files):
        for name, data in files.items():
            self.assertEqual((self.site / name).read_bytes(), data, name)

    def verify(self, crlf=False):
        originals, desired = self.fixture(crlf)
        self.assertIn('REVISIÓN COMPATIBLE: 6 archivos', self.run_tool('--check'))
        self.verify_files(originals)
        out = self.run_tool('--apply')
        self.verify_files(desired)
        self.assertIn('ya publicada', self.run_tool('--apply'))
        self.run_tool('--restore', out.split('Respaldo: ')[1].splitlines()[0])
        self.verify_files(originals)

    def test_check_apply_restore_lf(self):
        self.verify()

    def test_check_apply_restore_crlf(self):
        self.verify(crlf=True)

    def test_ya_instalado_no_escribe(self):
        originals, unused = self.fixture(already_current=True)
        self.assertIn('REVISIÓN COMPATIBLE: 0 archivos', self.run_tool('--apply'))
        self.verify_files(originals)

    def test_componente_desconocido_no_escribe(self):
        originals, unused = self.fixture()
        name = overlay.COMPONENT
        data = originals[name].replace(b'multiprograma_v3.js?v=3', b'multiprograma_v3.js?v=personalizado')
        (self.site / name).write_bytes(data)
        originals[name] = data
        self.assertIn('CONFLICTO', self.run_tool('--apply', ok=False))
        self.verify_files(originals)

    def test_dependencia_faltante_no_escribe(self):
        originals, unused = self.fixture()
        name = 'public/css/convocatorias_aliado_multiprograma_v3.css'
        (self.site / name).unlink()
        originals.pop(name)
        self.assertIn('Falta recurso', self.run_tool('--apply', ok=False))
        self.verify_files(originals)

    def test_no_cambia_diseno_ni_contexto(self):
        original = (ROOT / 'tests/fixtures/v3_hostinger/aliado_programas_modular.php').read_bytes()
        candidate = overlay.versionar_componente(original)
        self.assertEqual(candidate, overlay.versionar_componente(candidate))
        # El único cambio permitido son las versiones de cuatro URLs.
        restored = candidate
        for asset, version in overlay.ASSETS:
            expression = (asset + "?v=<?= substr(hash_file('sha256', __DIR__ . '/../../../../" + asset + "'), 0, 16) ?>\"").encode('ascii')
            restored = restored.replace(expression, (asset + '?v=' + version + '"').encode('ascii'))
        self.assertEqual(restored, original)

    def test_python36(self):
        ast.parse(SCRIPT.read_text(encoding='utf-8'), feature_version=(3, 6))

    def test_conserva_permisos_y_territorios_posteriores(self):
        originals, desired = self.fixture()
        for name in ['app/services/AliadoRegistroService.php', 'app/views/convocatorias/crear_desde_convenio.php']:
            row = next(row for row in self.manifest['files'] if row['path'] == name)
            # Freeze the territorial-permissions baseline: HEAD now also carries
            # the independent Bachillerato General migration, which overlaps the
            # historical reverse-merge but is covered by its own release tests.
            current = subprocess.check_output(['git', 'show', 'f0474b9eb6e40ef9e228a7d87e8d7a2040b2b419:' + name], cwd=str(ROOT))
            for part, data in [('current', current), ('stage3', unpack(row['target'])), ('previous', unpack(row['base']))]:
                (self.home / part).write_bytes(data.replace(b'\r\n', b'\n'))
            reverse = subprocess.run(['git', 'merge-file', '-p', str(self.home / 'current'), str(self.home / 'stage3'), str(self.home / 'previous')], stdout=subprocess.PIPE)
            self.assertEqual(reverse.returncode, 0)
            (self.site / name).write_bytes(reverse.stdout)
            originals[name] = reverse.stdout
            desired[name] = current.replace(b'\r\n', b'\n')
        self.run_tool('--check')
        self.verify_files(originals)
        out = self.run_tool('--apply')
        self.verify_files(desired)
        self.run_tool('--restore', out.split('Respaldo: ')[1].splitlines()[0])
        self.verify_files(originals)


if __name__ == '__main__':
    unittest.main()
