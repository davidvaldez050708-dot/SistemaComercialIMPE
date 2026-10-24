"""Publicador real; preflight BD simulado salvo la prueba explícita de CI."""
import ast,base64,gzip,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('base',ROOT/'tests/publicacion_convenios_detalle_etapa3_test.py');base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
spec=importlib.util.spec_from_file_location('publisher',ROOT/'tools/publicar_prospectos_fase1.py');publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(publisher)
base.MANIFEST=ROOT/'tools/prospectos_fase1_release.json';base.PUBLISHER=ROOT/'tools/publicar_prospectos_fase1.py';base.FILES=publisher.FILES
unpack=lambda x:gzip.decompress(base64.b64decode(x))
class Publicacion(base.ConveniosDetalleSelectivo):
    hosted=False
    def install(self,crlf=False,customized=False):
        originals=super().install(crlf,customized)
        for dep in self.manifest['dependencies']:
            file=self.site/dep['path'];file.parent.mkdir(parents=True,exist_ok=True)
            content=('\n'.join(dep.get('contains',[]))+'\n// Dependencia personalizada intocable').encode();file.write_bytes(content);originals[dep['path']]=content
        if self.hosted:
            row=next(r for r in self.manifest['files'] if r['path']=='app/views/prospectos/index.php');data=unpack(row['variants'][0]['base'])
            if customized:data=data.replace(b'<?php',b'<?php\n// Personalizacion Hostinger preservada\n',1)
            if crlf:data=data.replace(b'\n',b'\r\n')
            (self.site/row['path']).write_bytes(data);originals[row['path']]=data
        return originals
    def run_tool(self,*args,ok=True):
        # La validación real de BD es un caso independiente, sin una opción de
        # omisión en el publicador distribuido. PHP-l y git merge sí son reales.
        code="import importlib.util,sys; s=importlib.util.spec_from_file_location('p',sys.argv.pop(1));p=importlib.util.module_from_spec(s);s.loader.exec_module(p);p.validar_bd=lambda site:None;\ntry:p.main()\nexcept Exception as e:print('ERROR: '+str(e));sys.exit(1)"
        result=subprocess.run([sys.executable,'-B','-c',code,str(base.PUBLISHER),'--site',str(self.site),'--manifest',str(base.MANIFEST),'--backup-root',str(self.root/'respaldos')]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
        self.assertEqual(result.returncode,0 if ok else 1,result.stdout+result.stderr);return result.stdout+result.stderr
    def unchanged(self,originals):
        for name,data in originals.items():self.assertEqual((self.site/name).read_bytes() if (self.site/name).exists() else None,data,name)
    def verify(self,crlf=False,customized=False):
        originals=self.install(crlf,customized);self.run_tool('--check');self.unchanged(originals);out=self.run_tool('--apply')
        for row in self.manifest['files']:
            data=(self.site/row['path']).read_bytes().replace(b'\r\n',b'\n').replace(b'\n// Personalizacion Hostinger preservada\n',b'').replace(b'/* Personalizacion Hostinger preservada */\n',b'')
            self.assertEqual(data,unpack(row['variants'][0]['target'] if self.hosted and row.get('variants') else row['target']),row['path'])
        self.assertIn('ya publicada',self.run_tool('--apply'));self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.unchanged(originals)
    def test_hostinger_conserva_tipografia_v3(self):self.hosted=True;self.verify(True,True)
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='app/models/ProspectoModel.php';changed=originals[name].replace(b'$porPagina = max(1, min(100, (int)$porPagina));',b'$porPagina = 17;')
        # Se superpone sobre la condición de búsqueda que esta entrega amplía.
        changed=changed.replace(b"r.movil LIKE ?",b"r.correo = ?");self.assertNotEqual(changed,originals[name]);(self.site/name).write_bytes(changed);originals[name]=changed;self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_falta_mapa_detiene_sin_escribir(self):
        originals=self.install();name='public/img/estados/morelos.png';(self.site/name).unlink();originals[name]=None;self.assertIn('Falta dependencia',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_globales_y_modulos_intactos(self):
        originals=self.install();paths=['.htaccess','app/controllers/FormularioPublicoController.php','app/services/AliadoRegistroService.php','public/javascript/crear_convocatoria.js','app/views/aliados/internacionales.php']
        for name in paths:
            file=self.site/name;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(b'Personalizacion posterior intocable');originals[name]=file.read_bytes()
        out=self.run_tool('--apply');self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.unchanged(originals)
    def test_no_recrea_internacionales(self):
        self.install();self.run_tool('--apply');self.assertFalse((self.site/'app/views/aliados/internacionales.php').exists())
    def test_restore_no_pisa_cambio_posterior(self):
        self.install();out=self.run_tool('--apply');file=self.site/'public/css/prospectos_fase1.css';data=file.read_bytes()+b'\n/* Cambiado */';file.write_bytes(data);self.assertIn('modificado desde la',self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0],ok=False));self.assertEqual(file.read_bytes(),data)
    def test_restore_vacio_no_publica(self):
        originals=self.install();self.assertIn('Falta la ruta real',self.run_tool('--restore','',ok=False));self.unchanged(originals)
    def test_paquete_fijado_y_sin_migraciones(self):
        self.assertRegex(self.manifest['source'],r'^[a-f0-9]{40}$');self.assertEqual(len(self.manifest['files']),6)
        for row in self.manifest['files']:
            self.assertEqual(unpack(row['target']),subprocess.check_output(['git','show',self.manifest['source']+':'+row['path']],cwd=ROOT));self.assertNotIn('migrar',row['path'])
    def test_preflight_fallido_bloquea(self):
        self.install();bad=subprocess.CompletedProcess([],1,'{"ok":false}','');
        with patch.object(publisher.subprocess,'run',return_value=bad):
            with self.assertRaisesRegex(RuntimeError,'Dependencias de BD'):publisher.validar_bd(self.site)
    def test_preflight_ausencia_procedencias_es_aviso(self):
        self.install();ok=subprocess.CompletedProcess([],0,'{"ok":true,"procedencias":false}','')
        with patch.object(publisher.subprocess,'run',return_value=ok) as run:
            publisher.validar_bd(self.site);code=run.call_args[0][0][2];self.assertIn('START TRANSACTION READ ONLY',code);self.assertNotRegex(code,r'(?i)\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\s')
    @unittest.skipUnless(os.getenv('IMPE_PROSPECTOS_PROBE_REAL')=='1','Preflight real sólo sobre esquema sintético de CI')
    def test_preflight_real(self):
        self.install()
        for name in ['config/db_connection.php','config/config.php','config/private_config.php']:(self.site/name).write_bytes((ROOT/name).read_bytes())
        publisher.validar_bd(self.site)
if __name__=='__main__':unittest.main()
