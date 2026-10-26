#!/usr/bin/env python3
"""Instalador selectivo del fin SIP del Asesor: pruebas en réplica, sin DB real."""
import ast
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='b8cf8ec97962dc671f027f5c5b1112712a4822bf'
SCRIPT=ROOT/'tools/publicar_ventas_transferida_fin.py'
SPEC=ROOT/'tools/ventas_transferida_fin_release.json'
FILES=[x['path'] for x in json.loads(SPEC.read_text(encoding='utf8'))['files']]

class PublicacionAsesorTests(unittest.TestCase):
    def setUp(self):
        temp=tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root=pathlib.Path(temp.name)
        self.site=self.root/'public_html'
        self.site.mkdir()
        (self.site/'index.php').write_text('<?php // replica',encoding='utf8')
        self.backups=self.root/'private'
        for filename in FILES:
            source=subprocess.check_output(['git','show',BASE+':'+filename],cwd=str(ROOT))
            target=self.site/filename
            target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes(source)
        self.before={name:(self.site/name).read_bytes() for name in FILES}
    def run_pub(self,*args):
        return subprocess.run([sys.executable,str(SCRIPT),
            '--site',str(self.site),'--backup-root',str(self.backups)]+list(args),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    def installed(self):
        for name in FILES:
            self.assertEqual((self.site/name).read_bytes(),(ROOT/name).read_bytes(),name)
    def backup(self,result):
        return pathlib.Path(result.stdout.split('APLICADO. RESPALDO: ')[1].splitlines()[0])
    def test_check_apply_idempotent_restore(self):
        check=self.run_pub('--check')
        self.assertEqual(check.returncode,0,check.stderr)
        self.assertIn('CHECK OK',check.stdout)
        self.assertEqual(self.before,{name:(self.site/name).read_bytes() for name in FILES})
        self.assertFalse(self.backups.exists())
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        saved=self.backup(result)
        self.installed()
        result=self.run_pub('--check')
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertIn('CHECK OK',result.stdout)
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertIn('SIN CAMBIOS',result.stdout)
        self.assertEqual(len(list(self.backups.iterdir())),1)
        result=self.run_pub('--restore',str(saved))
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(self.before,{name:(self.site/name).read_bytes() for name in FILES})
    def test_partial_install(self):
        (self.site/FILES[0]).write_bytes((ROOT/FILES[0]).read_bytes())
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        self.installed()
        manifest=json.loads((self.backup(result)/'manifest.json').read_text(encoding='utf8'))
        self.assertEqual([x['path'] for x in manifest['files']],[FILES[1]])
    def test_unknown_variant_blocks_both_files(self):
        target=self.site/'public/javascript/telefonia_host.js'
        data=target.read_bytes()
        self.assertIn(b'        consultEnded:op=>{',data)
        target.write_bytes(data.replace(b'        consultEnded:op=>{',b'        consultEnded:(operacion)=>{'))
        previous={name:(self.site/name).read_bytes() for name in FILES}
        result=self.run_pub('--apply')
        self.assertNotEqual(result.returncode,0)
        self.assertIn('CONFLICTO',result.stderr)
        self.assertEqual(previous,{name:(self.site/name).read_bytes() for name in FILES})
        self.assertFalse(self.backups.exists())
    def test_custom_hostinger_changes_are_preserved(self):
        target=self.site/FILES[0]
        comment=b'\n// Hostinger: conservar personalizacion ajena fuera del cierre SIP\n'
        target.write_bytes(target.read_bytes()+comment)
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(target.read_bytes(),(ROOT/FILES[0]).read_bytes()+comment)
        self.assertEqual((self.site/FILES[1]).read_bytes(),(ROOT/FILES[1]).read_bytes())
    def test_crlf(self):
        target=self.site/FILES[1]
        original=target.read_bytes().replace(b'\r\n',b'\n')
        target.write_bytes(original.replace(b'\n',b'\r\n'))
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(target.read_bytes(),
            (ROOT/FILES[1]).read_bytes().replace(b'\r\n',b'\n').replace(b'\n',b'\r\n'))
    def test_restore_rejects_post_publication_edits(self):
        result=self.run_pub('--apply')
        self.assertEqual(result.returncode,0,result.stderr)
        saved=self.backup(result)
        target=self.site/FILES[0]
        target.write_bytes(target.read_bytes()+b'\n// cambio posterior')
        restore=self.run_pub('--restore',str(saved))
        self.assertNotEqual(restore.returncode,0)
        self.assertIn('Cambio posterior',restore.stderr)
    def test_python36_syntax(self):
        ast.parse(SCRIPT.read_text(encoding='utf8'),feature_version=(3,6))

if __name__=='__main__':
    unittest.main()
