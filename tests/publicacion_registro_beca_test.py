import base64,gzip,hashlib,importlib.util,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1];MANIFEST=ROOT/'tools/registro_beca_release.json'
spec=importlib.util.spec_from_file_location('beca',ROOT/'tools/publicar_registro_beca.py');pub=importlib.util.module_from_spec(spec);spec.loader.exec_module(pub)
unpack=lambda v:gzip.decompress(base64.b64decode(v)) if v else None
class PublicacionBeca(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name);self.site=self.root/'site';self.site.mkdir();self.data=json.loads(MANIFEST.read_text(encoding='utf8'))
    def install(self,crlf=False):
        originals={}
        for r in self.data['files']:
            b=unpack(r['base']);originals[r['path']]=b
            if b is not None:
                if crlf:b=b.replace(b'\n',b'\r\n');originals[r['path']]=b
                p=self.site/r['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
        for name in ['index.php','.htaccess','app/controllers/FormularioPublicoController.php','app/models/ProspectoModel.php',*(d['path'] for d in self.data['dependencies'])]:
            b=subprocess.check_output(['git','show',self.data['base']+':'+name],cwd=ROOT);p=self.site/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b);originals[name]=b
        return originals
    def unchanged(self,orig):
        for name,b in orig.items():p=self.site/name;self.assertEqual(p.read_bytes() if p.exists() else None,b,name)
    def run_tool(self,*args,ok=True):
        code="import importlib.util,sys;s=importlib.util.spec_from_file_location('p',sys.argv.pop(1));p=importlib.util.module_from_spec(s);s.loader.exec_module(p);p.validar_bd=lambda site:None;\ntry:p.main()\nexcept Exception as e:print('ERROR: '+str(e));sys.exit(1)"
        result=subprocess.run([sys.executable,'-B','-c',code,str(ROOT/'tools/publicar_registro_beca.py'),'--site',str(self.site),'--manifest',str(MANIFEST),'--backup-root',str(self.root/'backups'),*args],capture_output=True,text=True,encoding='utf8')
        self.assertEqual(result.returncode,0 if ok else 1,result.stdout+result.stderr);return result.stdout+result.stderr
    def test_source_and_scope(self):
        self.assertEqual({r['path'] for r in self.data['files']},pub.FILES)
        for r in self.data['files']:
            b=unpack(r['target']);self.assertEqual(hashlib.sha256(b).hexdigest(),r['sha256']);self.assertEqual(b,subprocess.check_output(['git','show',self.data['source']+':'+r['path']],cwd=ROOT))
        self.assertEqual(unpack(self.data['files'][1]['target']),subprocess.check_output(['git','show',self.data['base']+':registro/el-primer-paso-hacia-tu-meta-comienza-aqui/.htaccess'],cwd=ROOT))
        self.assertNotIn('.htaccess',pub.FILES);self.assertFalse(any('migrar' in name for name in pub.FILES))
    def test_check_apply_and_restore_keep_both_routes(self):
        orig=self.install(True);self.run_tool('--check');self.unchanged(orig);out=self.run_tool('--apply')
        installed={}
        for r in self.data['files']:
            b=(self.site/r['path']).read_bytes();installed[r['path']]=b;self.assertEqual(b.replace(b'\r\n',b'\n'),unpack(r['target']))
        self.assertIn('ya publicada',self.run_tool('--apply'));self.assertIn('CONSERVADO',self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]))
        for name,b in orig.items():
            p=self.site/name;self.assertEqual(p.read_bytes() if p.exists() else None,installed[name] if name in pub.PERSISTENTES else b,name)
    def test_customization_preserved(self):
        orig=self.install();name='public/javascript/convocatorias_gestion_v5.js';p=self.site/name;orig[name]+=b'\n// Personalizacion Hostinger intocable\n';p.write_bytes(orig[name]);out=self.run_tool('--apply');self.assertIn(b'Personalizacion Hostinger intocable',p.read_bytes());self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.assertEqual(p.read_bytes(),orig[name])
    def test_conflict_has_no_partial_writes(self):
        orig=self.install();name='app/services/RegistroEnlaceService.php';b=orig[name].replace(b"public const RUTA = 'registro/el-primer-paso-hacia-tu-meta-comienza-aqui/';",b"public const RUTA = 'personalizado/';");self.assertNotEqual(b,orig[name]);(self.site/name).write_bytes(b);orig[name]=b;self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(orig)
    def test_legacy_route_required(self):
        orig=self.install();name=self.data['dependencies'][0]['path'];(self.site/name).unlink();orig[name]=None;self.assertIn('Falta la ruta anterior',self.run_tool('--apply',ok=False));self.unchanged(orig)
    def test_restore_does_not_overwrite_later_edits(self):
        self.install();out=self.run_tool('--apply');p=self.site/'app/services/RegistroEnlaceService.php';b=p.read_bytes()+b'\n// Modificado posteriormente\n';p.write_bytes(b);self.assertIn('modificado desde',self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0],ok=False));self.assertEqual(p.read_bytes(),b)
    def test_readonly_schema_check(self):
        result=subprocess.CompletedProcess([],0,'{"ok":true}','')
        with patch.object(pub.subprocess,'run',return_value=result) as run:
            pub.validar_bd(self.site);code=run.call_args[0][0][2];self.assertIn('START TRANSACTION READ ONLY',code);self.assertNotRegex(code,r'(?i)\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\s')
    @unittest.skipUnless(os.getenv('APP_ENV')=='testing' and os.getenv('IMPE_DB_NAME','').startswith('impe_test_handly_horario_'),'Esquema real sólo en CI sintético')
    def test_real_preflight(self):pub.validar_bd(ROOT)
if __name__=='__main__':unittest.main()
