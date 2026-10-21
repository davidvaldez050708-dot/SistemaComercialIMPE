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

ROOT=Path(__file__).resolve().parent.parent
PUBLISH=ROOT/'tools/publicar_aliados_registro.py'

class PublicacionAliados(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.site=self.root/'public_html';self.site.mkdir()
        (self.site/'index.php').write_text('<?php echo "CRM";')
        self.old=b'<?php\n// institucional\n'+b'// separacion\n'*12+b'$value = 1;\n// final\n'
        self.new=self.old.replace(b'$value = 1;',b'$value = 2;')
        (self.site/'app.php').write_bytes(self.old)
        self.manifest=self.root/'entrega.json'
        def row(path,old,new):
            pack=lambda d:base64.b64encode(gzip.compress(d)).decode() if d is not None else None
            return {'path':path,'base':pack(old),'target':pack(new),'sha256':hashlib.sha256(new).hexdigest()}
        self.manifest.write_text(json.dumps({'source':'prueba','files':[row('app.php',self.old,self.new),row('nuevo.php',None,b'<?php // modulo nuevo\n')]}))
    def run_tool(self,*args,ok=True):
        p=subprocess.run([sys.executable,str(PUBLISH),'--site',str(self.site),'--backup-root',str(self.root/'privado'),'--manifest',str(self.manifest)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
        self.assertEqual(p.returncode,0 if ok else 1,p.stdout+p.stderr)
        return p.stdout+p.stderr
    def test_check_apply_restore_idempotencia(self):
        before=(self.site/'app.php').read_bytes()
        self.run_tool('--check');self.assertEqual((self.site/'app.php').read_bytes(),before);self.assertFalse((self.site/'nuevo.php').exists())
        out=self.run_tool('--apply');backup=out.split('Respaldo: ')[1].splitlines()[0]
        self.assertEqual((self.site/'app.php').read_bytes(),self.new)
        self.assertIn('ya publicada',self.run_tool('--apply'))
        self.run_tool('--restore',backup);self.assertEqual((self.site/'app.php').read_bytes(),self.old);self.assertFalse((self.site/'nuevo.php').exists())
    def test_personalizacion_y_crlf(self):
        local=self.old.replace(b'// institucional',b'// institucional personalizado').replace(b'\n',b'\r\n')
        (self.site/'app.php').write_bytes(local)
        out=self.run_tool('--apply');self.assertIn(b'personalizado', (self.site/'app.php').read_bytes());self.assertIn(b'$value = 2;', (self.site/'app.php').read_bytes())
        self.assertEqual((self.site/'app.php').read_bytes().count(b'\r\n'),self.old.count(b'\n'))
        self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.assertEqual((self.site/'app.php').read_bytes(),local)
    def test_conflicto_detiene_todo(self):
        local=self.old.replace(b'$value = 1;',b'$value = 7;');(self.site/'app.php').write_bytes(local)
        self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.assertEqual((self.site/'app.php').read_bytes(),local);self.assertFalse((self.site/'nuevo.php').exists())
    def test_archivo_nuevo_preexistente(self):
        (self.site/'nuevo.php').write_text('<?php // personalizado')
        self.run_tool('--apply',ok=False);self.assertEqual((self.site/'app.php').read_bytes(),self.old)
    def test_respaldo_fuera_del_sitio(self):
        self.run_tool('--check','--backup-root',str(self.site/'respaldos'),ok=False)
    def test_restaurar_no_sobrescribe_edicion_posterior(self):
        out=self.run_tool('--apply');(self.site/'app.php').write_bytes(self.new+b'// posterior\n')
        self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0],ok=False);self.assertTrue((self.site/'nuevo.php').exists())
    def test_integridad_de_entrega(self):
        data=json.loads(self.manifest.read_text());data['files'][0]['sha256']='0'*64;self.manifest.write_text(json.dumps(data))
        self.run_tool('--apply',ok=False);self.assertEqual((self.site/'app.php').read_bytes(),self.old)
    def test_sintaxis_detiene_publicacion(self):
        data=json.loads(self.manifest.read_text());bad=b'<?php function (';data['files'][1]['target']=base64.b64encode(gzip.compress(bad)).decode();data['files'][1]['sha256']=hashlib.sha256(bad).hexdigest();self.manifest.write_text(json.dumps(data))
        self.run_tool('--apply',ok=False);self.assertEqual((self.site/'app.php').read_bytes(),self.old)
    def test_entrega_real(self):
        release=ROOT/'tools/aliados_registro_release.json'
        if not release.exists():self.skipTest('Generar manifiesto primero')
        self.manifest=release
        data=json.loads(release.read_text(encoding='utf-8'))
        before={}
        for row in data['files']:
            self.assertNotIn('telefonia',row['path'].lower())
            self.assertNotIn('inscripcion',row['path'].lower())
            file=self.site/row['path']
            original=gzip.decompress(base64.b64decode(row['base'])) if row['base'] is not None else None
            before[row['path']]=original
            if original is not None:
                file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(original)
        self.run_tool('--check')
        out=self.run_tool('--apply')
        for row in data['files']:
            self.assertEqual(hashlib.sha256((self.site/row['path']).read_bytes().replace(b'\r\n',b'\n')).hexdigest(),hashlib.sha256(gzip.decompress(base64.b64decode(row['target'])).replace(b'\r\n',b'\n')).hexdigest())
        self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0])
        for name,original in before.items():
            if original is None:self.assertFalse((self.site/name).exists())
            else:self.assertEqual((self.site/name).read_bytes(),original)
    def test_python36(self):
        import ast
        for name in ['publicar_aliados_registro.py','preparar_entrega_aliados_registro.py']:
            ast.parse((ROOT/'tools'/name).read_text(encoding='utf-8'),feature_version=(3,6))

if __name__=='__main__':unittest.main()
