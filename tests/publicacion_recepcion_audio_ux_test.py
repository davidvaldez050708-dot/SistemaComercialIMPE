#!/usr/bin/env python3
"""Entrega de recepción: pruebas aisladas sobre réplica; nunca toca producción."""
import ast
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='37d4f2e2dd3a3e8a5e9a5ae9c0972ebe86118b83'
SCRIPT=ROOT/'tools/publicar_recepcion_audio_ux.py'
FILES=[x['path'] for x in json.loads((ROOT/'tools/recepcion_audio_ux_release.json').read_text(encoding='utf8'))['files']]

def old(path):
    return subprocess.check_output(['git','show',BASE+':'+path],cwd=str(ROOT))

class PublicacionRecepcionTests(unittest.TestCase):
    def setUp(self):
        temp=tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.base=pathlib.Path(temp.name)
        self.site=self.base/'public_html'
        self.site.mkdir()
        (self.site/'index.php').write_text('<?php // replica',encoding='utf8')
        self.backups=self.base/'private'
        for filename in FILES:
            path=self.site/filename
            path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(old(filename))
        self.before={name:(self.site/name).read_bytes() for name in FILES}
    def run_pub(self,*flags):
        return subprocess.run([sys.executable,str(SCRIPT),
            '--site',str(self.site),'--backup-root',str(self.backups)]+list(flags),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    def installed(self):
        for name in FILES:
            self.assertEqual((self.site/name).read_bytes(),(ROOT/name).read_bytes(),name)
    def backup_from(self,result):
        return pathlib.Path(result.stdout.split('APLICADO. RESPALDO: ')[1].splitlines()[0])
    def test_check_apply_repeat_restore(self):
        checked=self.run_pub('--check')
        self.assertEqual(checked.returncode,0,checked.stderr)
        self.assertIn('CHECK OK',checked.stdout)
        self.assertEqual(self.before,{name:(self.site/name).read_bytes() for name in FILES})
        self.assertFalse(self.backups.exists())
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        backup=self.backup_from(result)
        self.installed()
        again=self.run_pub('--check')
        self.assertEqual(again.returncode,0,again.stderr)
        self.assertIn('CHECK OK',again.stdout)
        duplicate=self.run_pub('--apply')
        self.assertEqual(duplicate.returncode,0,duplicate.stderr)
        self.assertIn('SIN CAMBIOS',duplicate.stdout)
        self.assertEqual(len(list(self.backups.iterdir())),1)
        restored=self.run_pub('--restore',str(backup))
        self.assertEqual(restored.returncode,0,restored.stderr)
        self.assertEqual(self.before,{name:(self.site/name).read_bytes() for name in FILES})
    def test_partial_install(self):
        (self.site/FILES[0]).write_bytes((ROOT/FILES[0]).read_bytes())
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        self.installed()
        contents=json.loads((self.backup_from(result)/'manifest.json').read_text(encoding='utf8'))
        self.assertEqual([x['path'] for x in contents['files']],[FILES[1]])
    def test_conflict_blocks_all_changes(self):
        target=self.site/FILES[0]
        target.write_bytes(target.read_bytes().replace(
            b'Consulta tus llamadas entrantes y salientes desde esta extension',
            b'Consulta tus llamadas entrantes y salientes desde esta variante'))
        # El texto real usa acentos: forzar una diferencia en el anclaje protegido.
        data=target.read_bytes().replace('Consulta tus llamadas entrantes'.encode('utf8'),
                                         'Consulta registros especiales'.encode('utf8'))
        target.write_bytes(data)
        snapshot={name:(self.site/name).read_bytes() for name in FILES}
        result=self.run_pub('--apply')
        self.assertNotEqual(result.returncode,0)
        self.assertIn('CONFLICTO',result.stderr)
        self.assertEqual(snapshot,{name:(self.site/name).read_bytes() for name in FILES})
        self.assertFalse(self.backups.exists())
    def test_css_custom_remains_byte_identical(self):
        css=self.site/'public/css/telefonia_recepcion_marketing.css'
        css.parent.mkdir(parents=True,exist_ok=True)
        custom=b'.marketing-reception-panel{padding:42px}/* custom Hostinger */\n'
        css.write_bytes(custom)
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        self.installed()
        self.assertEqual(css.read_bytes(),custom)
        stored=json.loads((self.backup_from(result)/'manifest.json').read_text())
        self.assertNotIn('public/css/telefonia_recepcion_marketing.css',
                         [entry['path'] for entry in stored['files']])
    def test_crlf_and_other_custom_code(self):
        js=self.site/FILES[1]
        suffix=b'\n/* otra personalizacion que no debe alterarse */\n'
        data=js.read_bytes().replace(b'\n',b'\r\n')+suffix.replace(b'\n',b'\r\n')
        js.write_bytes(data)
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(js.read_bytes(),
          (ROOT/FILES[1]).read_bytes().replace(b'\n',b'\r\n')+suffix.replace(b'\n',b'\r\n'))
    def test_restore_rejects_later_edits(self):
        applied=self.run_pub('--apply')
        self.assertEqual(applied.returncode,0,applied.stderr)
        backup=self.backup_from(applied)
        changed=self.site/FILES[1]
        changed.write_bytes(changed.read_bytes()+b'\n// local edit')
        result=self.run_pub('--restore',str(backup))
        self.assertNotEqual(result.returncode,0)
        self.assertIn('Cambio posterior',result.stderr)
    def test_python36_syntax(self):
        ast.parse(SCRIPT.read_text(encoding='utf8'),feature_version=(3,6))

if __name__=='__main__':
    unittest.main()
