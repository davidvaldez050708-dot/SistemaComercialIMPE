import base64,gzip,hashlib,importlib.util,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
MANIFEST=ROOT/'tools/horario_contacto_release.json'
spec=importlib.util.spec_from_file_location('horario',ROOT/'tools/publicar_horario_contacto.py');pub=importlib.util.module_from_spec(spec);spec.loader.exec_module(pub)
unpack=lambda v:gzip.decompress(base64.b64decode(v)) if v else None
class PublicacionHorario(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name);self.site=self.root/'site';self.site.mkdir();self.data=json.loads(MANIFEST.read_text(encoding='utf8'))
    def install(self,hosted=False,crlf=False):
        originals={}
        (self.site/'index.php').write_bytes(b'<?php // Sitio sintetico');originals['index.php']=b'<?php // Sitio sintetico'
        for r in self.data['files']:
            content=unpack(r['variants'][0]['base'] if hosted and r.get('variants') else r['base'])
            if content is not None:
                if crlf:content=content.replace(b'\n',b'\r\n')
                p=self.site/r['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(content)
            originals[r['path']]=content
        for dep in self.data['dependencies']:
            p=self.site/dep['path'];p.parent.mkdir(parents=True,exist_ok=True);content=('\n'.join(dep['contains'])+'\n// Personalizacion intocable').encode();p.write_bytes(content);originals[dep['path']]=content
        return originals
    def run_tool(self,*args,ready=True,ok=True):
        code="import importlib.util,sys;s=importlib.util.spec_from_file_location('p',sys.argv.pop(1));p=importlib.util.module_from_spec(s);s.loader.exec_module(p);p.validar_bd=lambda site:"+str(ready)+";\ntry:p.main()\nexcept Exception as e:print('ERROR: '+str(e));sys.exit(1)"
        result=subprocess.run([sys.executable,'-B','-c',code,str(ROOT/'tools/publicar_horario_contacto.py'),'--site',str(self.site),'--manifest',str(MANIFEST),'--backup-root',str(self.root/'backups'),*args],capture_output=True,text=True,encoding='utf8')
        self.assertEqual(result.returncode,0 if ok else 1,result.stdout+result.stderr);return result.stdout+result.stderr
    def unchanged(self,orig):
        for name,b in orig.items():p=self.site/name;self.assertEqual(p.read_bytes() if p.exists() else None,b,name)
    def test_source_bytes(self):
        self.assertEqual({r['path'] for r in self.data['files']},pub.FILES)
        for r in self.data['files']:
            b=unpack(r['target']);self.assertEqual(hashlib.sha256(b).hexdigest(),r['sha256']);self.assertEqual(b,subprocess.check_output(['git','show',self.data['source']+':'+r['path']],cwd=ROOT))
    def test_review_apply_restore(self):
        for hosted,crlf in [(False,False),(True,True)]:
            with self.subTest(hosted=hosted):
                orig=self.install(hosted,crlf);self.run_tool('--check');self.unchanged(orig);out=self.run_tool('--apply')
                for r in self.data['files']:
                    expected=unpack(r['variants'][0]['target'] if hosted and r.get('variants') else r['target']);self.assertEqual((self.site/r['path']).read_bytes().replace(b'\r\n',b'\n'),expected)
                self.assertIn('ya publicada',self.run_tool('--apply'));self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.unchanged(orig)
    def test_pending_schema_blocks_apply(self):
        orig=self.install();self.run_tool('--check',ready=False);self.unchanged(orig);self.assertIn('Falta aplicar',self.run_tool('--apply',ready=False,ok=False));self.unchanged(orig)
    def test_public_customizations_preserved(self):
        orig=self.install(True);p=self.site/'app/views/formularios/publico_registro.php';b=p.read_bytes().replace(b'data-formulario-registro-publico>',b'data-formulario-registro-publico>\n<input type="hidden" name="csrf_formulario_publico" value="conservar">').replace(b'<h1>Formulario de registro</h1>',b'<h1>Formulario de registro</h1>\n<?php /* Personalizacion publicada */ ?>');p.write_bytes(b);orig[p.relative_to(self.site).as_posix()]=b
        out=self.run_tool('--apply');result=p.read_bytes();self.assertIn(b'csrf_formulario_publico',result);self.assertIn(b'Personalizacion publicada',result);self.assertIn(b'partials/horario_contacto.php',result);self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.unchanged(orig)
    def test_conflict_no_partial_install(self):
        orig=self.install();name='app/models/FormularioRegistroModel.php';b=orig[name].replace(b'            $origen\n        );',b'            $otroOrigen\n        );');self.assertNotEqual(b,orig[name]);(self.site/name).write_bytes(b);orig[name]=b;self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(orig)
    def test_dependencies_and_blank_restore(self):
        orig=self.install();self.assertIn('Falta la ruta',self.run_tool('--restore','',ok=False));self.unchanged(orig)
        dep=self.data['dependencies'][0]['path'];(self.site/dep).unlink();orig[dep]=None;self.assertIn('Falta dependencia',self.run_tool('--apply',ok=False));self.unchanged(orig)
    def test_readonly_preflight(self):
        result=subprocess.CompletedProcess([],0,'{"ok":true,"listo":true}','')
        with patch.object(pub.subprocess,'run',return_value=result) as run:
            self.assertTrue(pub.validar_bd(self.site));code=run.call_args[0][0][2];self.assertIn('START TRANSACTION READ ONLY',code);self.assertNotRegex(code,r'(?i)\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\s')
    @unittest.skipUnless(os.getenv('APP_ENV')=='testing' and os.getenv('IMPE_DB_NAME','').startswith('impe_test_handly_horario_'),'BD real exclusivamente en CI sintético')
    def test_real_preflight(self):self.assertTrue(pub.validar_bd(ROOT))
if __name__=='__main__':unittest.main()
