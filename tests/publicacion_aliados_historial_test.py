"""Publicación acotada y reversible, incluida la variante exacta de Hostinger."""
import ast
import base64
import gzip
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT/'tools/aliados_historial_release.json'
PUBLISHER = ROOT/'tools/publicar_aliados_registro.py'
FILES = {'app/services/AliadoHistorialService.php','app/models/AliadoModel.php','app/services/AliadoCompartirService.php','app/controllers/AliadoController.php','public/javascript/aliados.js','public/javascript/aliados_registro.js','app/views/aliados/partials/registro_gestion.php','app/views/aliados/estado.php'}
unpack = lambda value: gzip.decompress(base64.b64decode(value)) if value is not None else None

class HistorialSelectivo(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.site = self.root/'public_html'; self.site.mkdir()
        (self.site/'index.php').write_bytes(b'<?php // fixture\n')
        self.manifest = json.loads(MANIFEST.read_text(encoding='utf-8'))
        self.assertEqual({r['path'] for r in self.manifest['files']},FILES)

    def install(self, host=False, crlf=False, custom=False):
        self.originals = {}; self.targets = {}
        for row in self.manifest['files']:
            variant = row['variants'][0] if host and row.get('variants') else row
            data = unpack(variant['base']); self.targets[row['path']] = unpack(variant['target'])
            if data is not None:
                if custom:
                    data = data.replace(b'<?php',b'<?php\n// Personalizacion conservada\n',1) if row['path'].endswith('.php') else b'/* Personalizacion conservada */\n'+data
                if crlf: data = data.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')
                file = self.site/row['path']; file.parent.mkdir(parents=True,exist_ok=True); file.write_bytes(data)
            self.originals[row['path']] = data
        for name in ['app/services/AliadoRegistroService.php','app/views/prospectos/index.php','public/css/aliados_v2_extension.css','public/javascript/convenios_borradores_v4.js']:
            file = self.site/name; file.parent.mkdir(parents=True,exist_ok=True)
            file.write_bytes(b'Personalizacion ajena intocable\r\n'); self.originals[name] = file.read_bytes()

    def run_tool(self,*args,ok=True):
        result = subprocess.run([sys.executable,str(PUBLISHER),'--site',str(self.site),'--manifest',str(MANIFEST),'--backup-root',str(self.root/'backup')]+list(args),stdout=subprocess.PIPE,stderr=subprocess.STDOUT,encoding='utf-8')
        self.assertEqual(result.returncode,0 if ok else 1,result.stdout)
        return result.stdout

    def intact(self):
        for name,data in self.originals.items():
            file = self.site/name
            self.assertEqual(file.read_bytes() if file.exists() else None,data,name)

    def verify(self,**options):
        self.install(**options); self.run_tool('--check'); self.intact()
        self.assertFalse((self.root/'backup').exists())
        output = self.run_tool('--apply')
        for name,target in self.targets.items():
            actual = (self.site/name).read_bytes().replace(b'\r\n',b'\n')
            cleaned = actual.replace(b'// Personalizacion conservada\n',b'').replace(b'/* Personalizacion conservada */\n',b'')
            self.assertEqual(cleaned,target,name)
        self.assertIn('ya publicada',self.run_tool('--apply'))
        self.run_tool('--restore',output.split('Respaldo: ')[1].splitlines()[0]); self.intact()

    def test_base(self): self.verify()
    def test_hostinger_exacto(self):
        self.verify(host=True)
        self.assertEqual(hashlib.sha256(self.originals['app/controllers/AliadoController.php']).hexdigest(),'6cdfd9b2bb299623e91425632cfcd8a2a39ef7e8d4c9cd31a6053b2cd9918170')
    def test_crlf_y_personalizaciones(self): self.verify(crlf=True,custom=True)
    def test_conflicto_sin_escrituras(self):
        self.install()
        name = 'app/models/AliadoModel.php'
        before = self.originals[name]
        changed = before.replace(b'return $registro->decorar($filas);',b'return $filas; // variante incompatible')
        self.assertNotEqual(changed,before)
        (self.site/name).write_bytes(changed); self.originals[name] = changed
        self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False)); self.intact()
        self.assertFalse((self.root/'backup').exists())
    def test_controlador_conserva_bloques_ajenos(self):
        self.install(host=True)
        name = 'app/controllers/AliadoController.php'
        old,new = self.originals[name],self.targets[name]
        start,end = b'    public function internacionales()',b'    public function estado()'
        self.assertEqual(old[old.index(start):old.index(end)],new[new.index(start):new.index(end)])
        self.assertEqual(old[:old.index(start)],new[:new.index(start)])
    def test_sintaxis_publicada_y_python36(self):
        self.install(host=True); self.run_tool('--apply')
        ast.parse(PUBLISHER.read_text(encoding='utf-8'),feature_version=(3,6))
        for name in FILES:
            file = self.site/name
            cmd = [os.environ.get('PHP_BINARY','php'),'-l',str(file)] if name.endswith('.php') else ['node','--check',str(file)]
            subprocess.run(cmd,check=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)

if __name__ == '__main__': unittest.main()
