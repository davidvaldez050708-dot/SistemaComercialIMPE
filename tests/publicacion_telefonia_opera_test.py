#!/usr/bin/env python3
"""Simulacion de instalacion segura: parciales, CRLF, rollback y conflictos."""
import ast
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
BASE = '34d9d4ccd80d3b35fda56daa8de9569667a4a787'
SCRIPT = ROOT / 'tools/publicar_telefonia_opera.py'
FILES = [r['path'] for r in json.loads(
    (ROOT / 'tools/telefonia_opera_release.json').read_text(encoding='utf8'))['files']]

def git_old(rel):
    return subprocess.check_output(['git','show',BASE+':'+rel],cwd=str(ROOT))

class PublicacionOperaTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base=pathlib.Path(self.tmp.name)
        self.site=self.base/'public_html'
        self.site.mkdir()
        (self.site/'index.php').write_text('<?php // site test',encoding='utf8')
        self.backups=self.base/'private'
        for path in FILES:
            dest=self.site/path
            dest.parent.mkdir(parents=True,exist_ok=True)
            dest.write_bytes(git_old(path))
        self.original={rel:(self.site/rel).read_bytes() for rel in FILES}
    def runpub(self,*args):
        return subprocess.run([sys.executable,str(SCRIPT),
            '--site',str(self.site),'--backup-root',str(self.backups)]+list(args),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    def assertAllUpdated(self):
        for rel in FILES:
            self.assertEqual((self.site/rel).read_bytes(),
                (ROOT/rel).read_bytes(),rel)
    def test_apply_check_restore(self):
        r=self.runpub('--check')
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertIn('CHECK OK',r.stdout)
        self.assertEqual(self.original,{rel:(self.site/rel).read_bytes() for rel in FILES})
        self.assertFalse(self.backups.exists())
        r=self.runpub('--apply')
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertIn('APLICADO. RESPALDO:',r.stdout)
        backup=pathlib.Path(r.stdout.split('APLICADO. RESPALDO: ')[1].splitlines()[0])
        self.assertTrue((backup/'manifest.json').exists())
        self.assertAllUpdated()
        r=self.runpub('--check')
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertIn('CHECK OK',r.stdout)
        r=self.runpub('--apply')
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertIn('SIN CAMBIOS',r.stdout)
        self.assertEqual(len(list(self.backups.iterdir())),1)
        r=self.runpub('--restore',str(backup))
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertEqual(self.original,{rel:(self.site/rel).read_bytes() for rel in FILES})
    def test_unknown_variant_is_blocked_atomically(self):
        path=self.site/FILES[0]
        data=path.read_bytes().replace(
            b'const writeOwner = () => {',b'const writeOwnerAlterado = () => {')
        path.write_bytes(data)
        snapshot={rel:(self.site/rel).read_bytes() for rel in FILES}
        r=self.runpub('--apply')
        self.assertNotEqual(r.returncode,0)
        self.assertIn('CONFLICTO',r.stderr)
        self.assertEqual(snapshot,{rel:(self.site/rel).read_bytes() for rel in FILES})
        self.assertFalse(self.backups.exists())
    def test_partial_existing(self):
        (self.site/FILES[0]).write_bytes((ROOT/FILES[0]).read_bytes())
        r=self.runpub('--apply')
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertAllUpdated()
        folder=pathlib.Path(r.stdout.split('APLICADO. RESPALDO: ')[1].splitlines()[0])
        journal=json.loads((folder/'manifest.json').read_text())
        self.assertEqual(len(journal['files']),3)
    def test_crlf_and_custom_text(self):
        suffix=b'\n/* Personalizacion ajena conservar */\n'
        js=self.site/FILES[0]
        js.write_bytes(js.read_bytes().replace(b'\n',b'\r\n')+
            suffix.replace(b'\n',b'\r\n'))
        r=self.runpub('--check')
        self.assertEqual(r.returncode,0,r.stderr)
        r=self.runpub('--apply')
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertEqual(js.read_bytes(),
           (ROOT/FILES[0]).read_bytes().replace(b'\n',b'\r\n')+
           suffix.replace(b'\n',b'\r\n'))
    def test_no_restore_over_later_edits(self):
        r=self.runpub('--apply')
        self.assertEqual(r.returncode,0,r.stderr)
        folder=pathlib.Path(r.stdout.split('APLICADO. RESPALDO: ')[1].splitlines()[0])
        target=self.site/FILES[1]
        target.write_bytes(target.read_bytes()+b'// after publication\n')
        r=self.runpub('--restore',str(folder))
        self.assertNotEqual(r.returncode,0)
        self.assertIn('Cambio posterior',r.stderr)
    def test_python36_syntax(self):
        ast.parse(SCRIPT.read_text(encoding='utf8'),feature_version=(3,6))

if __name__ == '__main__':
    unittest.main()
