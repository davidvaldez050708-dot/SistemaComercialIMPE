"""Publicación V4 sobre los bytes productivos confirmados, sin SQL implícito."""
import ast
import importlib.util
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('base_test',str(ROOT/'tests/publicacion_convenios_detalle_etapa3_test.py'))
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
base.MANIFEST=ROOT/'tools/convenios_inline_v4_release.json';base.PUBLISHER=ROOT/'tools/publicar_convenios_inline_v4.py'
base.FILES={'app/controllers/ConvocatoriaController.php','app/controllers/CrearConvocatoriaController.php','app/models/ConvocatoriaModel.php','app/services/ConvocatoriaBorradorService.php','app/views/convocatorias/crear_desde_convenio.php','app/views/convocatorias/partials/editor_convenio_v4.php','public/javascript/convocatorias_aliado_multiprograma_v3.js','public/javascript/convenios_borradores_v4.js','public/css/convenios_borradores_v4.css','tools/migrar_convocatoria_borradores_v4.php','database/migrations/2026_10_23_convocatoria_borradores_v4.sql'}
unpack=lambda x:base.gzip.decompress(base.base64.b64decode(x)).replace(b'\r\n',b'\n')
class PublicacionV4(base.ConveniosDetalleSelectivo):
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
        for dependency in self.manifest['dependencies']:self.assertEqual((self.site/dependency['path']).read_bytes(),originals[dependency['path']])
        self.assertIn('ya publicada',self.run_tool('--apply'));self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.unchanged(originals)
    def test_hostinger_confirmado(self):
        self.hosted=True;self.verify()
    def test_hostinger_crlf_y_personalizaciones(self):
        self.hosted=True;self.verify(crlf=True,customized=True)
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='app/controllers/ConvocatoriaController.php';data=originals[name].replace(b'count($entradas) > 30',b'count($entradas) > 99');self.assertNotEqual(data,originals[name]);(self.site/name).write_bytes(data);originals[name]=data
        self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_dependencia_no_revisada_bloquea(self):
        originals=self.install();name='public/javascript/convocatorias_aliado_modular.js';originals[name]+=b'\n/* Cambio posterior sin revisar */\n';(self.site/name).write_bytes(originals[name]);self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_restore_vacio_nunca_se_interpreta_como_apply(self):
        originals=self.install();self.assertIn('Falta la ruta real',self.run_tool('--restore','',ok=False));self.unchanged(originals)
    def test_paquete_corresponde_a_su_commit(self):
        for row in self.manifest['files']:
            data=subprocess.check_output(['git','show',self.manifest['source']+':'+row['path']],cwd=str(ROOT));self.assertEqual(unpack(row['target']),data.replace(b'\r\n',b'\n'))
        for file in ['tools/publicar_aliados_registro.py','tools/publicar_convenios_inline_v4.py']:ast.parse((ROOT/file).read_text(encoding='utf-8'),feature_version=(3,6))
    def test_sql_solo_es_aditivo_y_explicito(self):
        sql=unpack(next(r for r in self.manifest['files'] if r['path'].endswith('.sql'))['target']).decode()
        self.assertEqual(sql.count('CREATE TABLE IF NOT EXISTS'),3)
        self.assertNotRegex(sql,r'(?im)^\s*(ALTER|DROP|UPDATE|DELETE|INSERT)\s')
        self.assertNotIn('subprocess',base.PUBLISHER.read_text(encoding='utf-8'))
    def test_flujos_internacionales_se_conservan(self):
        row=next(r for r in self.manifest['files'] if r['path']=='app/controllers/ConvocatoriaController.php');before,after=[unpack(row['variants'][0][k]) for k in ('base','target')]
        start=before.index(b'    private function guardarInternacional()');end=before.index(b'    /**',start)
        self.assertIn(before[start:end],after)
        model=next(r for r in self.manifest['files'] if r['path']=='app/models/ConvocatoriaModel.php');v=model['variants'][0]
        self.assertEqual(unpack(v['target']).replace(b'    public function conexionLote(): mysqli { return $this->connection; }\n',b''),unpack(v['base']))
if __name__=='__main__':unittest.main()
