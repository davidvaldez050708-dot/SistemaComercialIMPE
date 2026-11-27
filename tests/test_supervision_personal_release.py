"""Sólo archivos temporales locales; no utiliza SSH, HTTP ni MySQL."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.util.spec_from_file_location('sp_publisher', ROOT / 'tools/publicar_supervision_personal.py')
publisher = importlib.util.module_from_spec(loader)
loader.loader.exec_module(publisher)
SPEC = json.loads((ROOT / 'tools/supervision_personal_release.json').read_text(encoding='utf-8'))
BASE = Path(os.environ.get('SP_BASELINE_DIR', ROOT / 'tmp/hostinger-base'))


class ReleaseTest(unittest.TestCase):
    def test_paquete_coincide_con_fuentes(self):
        for row in SPEC['files']:
            self.assertEqual(publisher.sha((ROOT / row['path']).read_bytes().replace(b'\r\n', b'\n')), row['after_sha'], row['path'])

    def test_parches_conservan_personalizaciones_y_son_idempotentes(self):
        for row in SPEC['files']:
            if row['new_file']:
                once = publisher.candidate(None, row)
                self.assertEqual(once, publisher.candidate(once, row))
                with self.assertRaises(RuntimeError):
                    publisher.candidate(once + b'// cambio ajeno', row)
                continue
            old = (BASE / row['path']).read_bytes().replace(b'\r\n', b'\n')
            self.assertEqual(publisher.sha(old), row['baseline_sha'])
            for ending in (b'\n', b'\r\n'):
                custom = (old + b'\n// Personalizacion ajena conservada.\n').replace(b'\n', ending)
                new = publisher.candidate(custom, row)
                self.assertTrue(new.endswith(b'// Personalizacion ajena conservada.' + ending))
                self.assertEqual(new, publisher.candidate(new, row))
                altered = old.decode('utf-8').replace(row['hunks'][0]['before'], '// BLOQUE AJENO\n', 1).encode('utf-8')
                with self.assertRaises(RuntimeError, msg=row['path']):
                    publisher.candidate(altered, row)

    def test_publicacion_local_y_reversion_segura(self):
        with tempfile.TemporaryDirectory(prefix='sp-release-test-') as folder:
            root = Path(folder)
            site = root / 'sitio'
            site.mkdir()
            for row in SPEC['files']:
                if not row['new_file']:
                    p = site / row['path']
                    p.parent.mkdir(parents=True, exist_ok=True)
                    p.write_bytes((BASE / row['path']).read_bytes())
            command = [sys.executable, str(ROOT / 'tools/publicar_supervision_personal.py'), '--site', str(site), '--backup-root', str(root / 'privado')]
            run = lambda *args: subprocess.run(command + list(args), capture_output=True, text=True)
            self.assertEqual(run('--check').returncode, 0)
            self.assertNotEqual(run('--apply').returncode, 0, 'Publicar exige autorización explícita')
            # Toda discrepancia se detecta antes de escribir el primer archivo.
            row = next(x for x in SPEC['files'] if not x['new_file'])
            p = site / row['path']
            original = p.read_bytes()
            p.write_bytes(original.replace(row['hunks'][0]['before'].encode(), b'// bloque distinto', 1))
            before = {str(p.relative_to(site)): p.read_bytes() for p in site.rglob('*') if p.is_file()}
            self.assertNotEqual(run('--apply', '--autorizar-publicacion').returncode, 0)
            self.assertEqual(before, {str(p.relative_to(site)): p.read_bytes() for p in site.rglob('*') if p.is_file()})
            p.write_bytes(original)
            result = run('--apply', '--autorizar-publicacion')
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('SIN_CAMBIOS', run('--apply', '--autorizar-publicacion').stdout)
            backup = next((root / 'privado').glob('supervision-personal-*'))
            current = p.read_bytes()
            p.write_bytes(current + b'\n// Cambio posterior\n')
            self.assertNotEqual(run('--restore', str(backup), '--autorizar-publicacion').returncode, 0)
            self.assertTrue(p.read_bytes().endswith(b'// Cambio posterior\n'))
            p.write_bytes(current)
            result = run('--restore', str(backup), '--autorizar-publicacion')
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            for row in SPEC['files']:
                p = site / row['path']
                if row['new_file']:
                    self.assertFalse(p.exists())
                else:
                    self.assertEqual(p.read_bytes(), (BASE / row['path']).read_bytes())
            with self.assertRaises(RuntimeError):
                publisher.safe(site.resolve(), '../fuera.php')


if __name__ == '__main__':
    if not BASE.is_dir():
        raise SystemExit('Define SP_BASELINE_DIR con la copia privada de referencia; no se descarga producción automáticamente.')
    unittest.main()
