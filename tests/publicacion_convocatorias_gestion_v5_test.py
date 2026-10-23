"""Prueba el publicador compartido sobre variantes reales, sin BD ni producción."""
import ast,base64,gzip,importlib.util,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('base_publicacion',ROOT/'tests/publicacion_convenios_detalle_etapa3_test.py');base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
base.MANIFEST=ROOT/'tools/convocatorias_gestion_v5_release.json';base.PUBLISHER=ROOT/'tools/publicar_convocatorias_gestion_v5.py'
base.FILES={'app/controllers/ConvocatoriaController.php','app/models/ConvocatoriaModel.php','app/services/ConvocatoriaOrigenService.php','app/views/convocatorias/index.php','public/javascript/crear_convocatoria_v2_extension.js','public/javascript/convocatorias_gestion_v5.js','public/css/convocatorias_gestion_v5.css'}
unpack=lambda s:gzip.decompress(base64.b64decode(s)).replace(b'\r\n',b'\n')
class PublicacionV5(base.ConveniosDetalleSelectivo):
    hosted=False
    def install(self,crlf=False,customized=False):
        originals=super().install(crlf,customized)
        if self.hosted:
            for row in self.manifest['files']:
                if not row.get('variants'):continue
                data=unpack(row['variants'][0]['base'])
                if customized:data=data.replace(b'<?php',b'<?php\n// Personalizacion Hostinger preservada\n',1)
                if crlf:data=data.replace(b'\n',b'\r\n')
                (self.site/row['path']).write_bytes(data);originals[row['path']]=data
        for dependency in self.manifest['dependencies']:
            file=self.site/dependency['path'];file.parent.mkdir(parents=True,exist_ok=True);data=unpack(dependency['content'])
            if crlf:data=data.replace(b'\n',b'\r\n')
            file.write_bytes(data);originals[dependency['path']]=data
        return originals
    def unchanged(self,originals):
        for name,data in originals.items():self.assertEqual((self.site/name).read_bytes() if (self.site/name).exists() else None,data,name)
    def verify(self,crlf=False,customized=False):
        originals=self.install(crlf,customized);self.run_tool('--check');self.unchanged(originals);out=self.run_tool('--apply')
        for row in self.manifest['files']:
            expected=unpack(row['variants'][0]['target'] if self.hosted and row.get('variants') else row['target'])
            actual=(self.site/row['path']).read_bytes().replace(b'\r\n',b'\n').replace(b'\n// Personalizacion Hostinger preservada\n',b'').replace(b'/* Personalizacion Hostinger preservada */\n',b'')
            self.assertEqual(actual,expected,row['path'])
        self.assertIn('ya publicada',self.run_tool('--apply'));self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.unchanged(originals)
    def test_hostinger_modular(self):
        self.hosted=True;self.verify()
    def test_hostinger_crlf_y_personalizaciones(self):
        self.hosted=True;self.verify(True,True)
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='app/views/convocatorias/index.php'
        old=b'        const grupos = new Map();';self.assertIn(old,originals[name]);originals[name]=originals[name].replace(old,b'        const grupos = new Map([["personalizado",1]]);');(self.site/name).write_bytes(originals[name]);self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_dependencia_no_revisada(self):
        originals=self.install();name=self.manifest['dependencies'][0]['path'];originals[name]+=b'\n// Cambio posterior\n';(self.site/name).write_bytes(originals[name]);self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_restore_vacio_no_publica(self):
        originals=self.install();self.assertIn('Falta la ruta real',self.run_tool('--restore','',ok=False));self.unchanged(originals)
    def test_conflicto_al_restaurar(self):
        self.install();out=self.run_tool('--apply');name='public/javascript/convocatorias_gestion_v5.js';p=self.site/name;data=p.read_bytes()+b'\n// Cambio posterior\n';p.write_bytes(data)
        self.assertIn('archivo modificado desde la',self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0],ok=False));self.assertEqual(p.read_bytes(),data)
    def test_paquete_pinned(self):
        for row in self.manifest['files']:
            data=subprocess.check_output(['git','show',self.manifest['source']+':'+row['path']],cwd=ROOT);self.assertEqual(unpack(row['target']),data.replace(b'\r\n',b'\n'))
    def test_no_sql_ni_borradores(self):
        self.assertTrue(all(not r['path'].endswith('.sql') and 'Borrador' not in r['path'] and 'borrador' not in r['path'] for r in self.manifest['files']))
        self.assertNotIn('subprocess',base.PUBLISHER.read_text(encoding='utf-8'))
    def test_conserva_internacionales_y_modular(self):
        rows={r['path']:r for r in self.manifest['files']};c=unpack(rows['app/controllers/ConvocatoriaController.php']['variants'][0]['target'])
        self.assertIn(b'private function guardarInternacional()',c);self.assertIn(b'Esta convocatoria se gestiona desde Aliados internacionales.',c)
        model=unpack(rows['app/models/ConvocatoriaModel.php']['variants'][0]['target']);self.assertIn(b'public function __construct(?mysqli $connection = null)',model);self.assertIn(b'if ($relacionar)',model)
        index=unpack(rows['app/views/convocatorias/index.php']['variants'][0]['target']);self.assertIn(b"'/partials/crear_modal.php'",index);self.assertIn(b"'/partials/aliado_programas_modular.php'",index)
if __name__=='__main__':unittest.main()
