"""Conciliación y reversión de generadores sin romper ligas ya compartidas."""
import importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('v5',ROOT/'tests/publicacion_convocatorias_gestion_v5_test.py');v5=importlib.util.module_from_spec(spec);spec.loader.exec_module(v5)
spec=importlib.util.spec_from_file_location('publisher',ROOT/'tools/publicar_registro_enlaces.py');publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(publisher)
v5.base.MANIFEST=ROOT/'tools/registro_enlaces_release.json';v5.base.PUBLISHER=ROOT/'tools/publicar_registro_enlaces.py';v5.base.FILES={r['path'] for r in json.loads(v5.base.MANIFEST.read_text(encoding='utf-8'))['files']}
class RegistroEnlaces(v5.PublicacionV5):
    def verify(self,crlf=False,customized=False):
        originals=self.install(crlf,customized);self.run_tool('--check');self.unchanged(originals);out=self.run_tool('--apply');installed={}
        for row in self.manifest['files']:
            expected=v5.unpack(row['variants'][0]['target'] if self.hosted and row.get('variants') else row['target']);file=self.site/row['path'];installed[row['path']]=file.read_bytes()
            clean=file.read_bytes().replace(b'\r\n',b'\n').replace(b'\n// Personalizacion Hostinger preservada\n',b'').replace(b'/* Personalizacion Hostinger preservada */\n',b'');self.assertEqual(clean,expected,row['path'])
        self.assertIn('ya publicada',self.run_tool('--apply'));self.assertIn('CONSERVADO',self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]))
        for name,data in originals.items():self.assertEqual((self.site/name).read_bytes() if (self.site/name).exists() else None,installed[name] if name in publisher.PERSISTENTES else data,name)
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='app/services/AliadoRegistroService.php';old=b"$url=(($marketing || $mostrarEnlace)";self.assertNotIn(old,originals[name]);old=b"'url'=>(($marketing || $mostrarEnlace)";self.assertIn(old,originals[name]);originals[name]=originals[name].replace(old,b"'url'=>(($marketing && $mostrarEnlace)");(self.site/name).write_bytes(originals[name]);self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_conflicto_al_restaurar(self):
        self.install();out=self.run_tool('--apply');file=self.site/'app/services/RegistroEnlaceService.php';data=file.read_bytes()+b'\n// Cambio posterior\n';file.write_bytes(data);self.assertIn('archivo modificado desde la',self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0],ok=False));self.assertEqual(file.read_bytes(),data)
    def test_dependencia_no_revisada(self):
        self.assertEqual(self.manifest['dependencies'],[]);self.install();file=self.site/'app/views/convocatorias/index.php';file.parent.mkdir(parents=True,exist_ok=True);data=b'<?php // Index modular intocable';file.write_bytes(data);self.run_tool('--apply');self.assertEqual(file.read_bytes(),data)
    def test_conserva_internacionales_y_modular(self):
        rows={r['path']:r for r in self.manifest['files']};self.assertEqual(len(rows),12);self.assertNotIn('app/views/convocatorias/index.php',rows);self.assertNotIn('.htaccess',rows);self.assertNotIn('app/views/aliados/internacionales.php',rows)
        controller=v5.unpack(rows['app/controllers/ConvocatoriaController.php']['variants'][0]['target']);self.assertIn(b'private function guardarInternacional()',controller)
        model=v5.unpack(rows['app/models/ConvocatoriaModel.php']['variants'][0]['target']);self.assertIn(b'public function __construct(?mysqli $connection = null)',model);self.assertIn(b'if ($relacionar)',model)
    def test_modulo_internacional_eliminado_permanece_ausente(self):
        self.hosted=True;self.install();paths=['app/views/aliados/internacionales.php','app/services/AliadoInternacionalService.php']
        for name in paths:self.assertFalse((self.site/name).exists())
        self.run_tool('--check');out=self.run_tool('--apply')
        for name in paths:self.assertFalse((self.site/name).exists())
        self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0])
        for name in paths:self.assertFalse((self.site/name).exists())
    def test_vista_internacional_desactivada_no_se_modifica(self):
        self.hosted=True;self.install();file=self.site/'app/views/aliados/internacionales.php';file.parent.mkdir(parents=True,exist_ok=True);data=b'<?php http_response_code(404); exit; // Retirado por el usuario\r\n';file.write_bytes(data)
        self.run_tool('--check');out=self.run_tool('--apply');self.assertEqual(file.read_bytes(),data);self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.assertEqual(file.read_bytes(),data)
    def test_controlador_sin_internacionales_no_los_reintroduce(self):
        self.hosted=True;self.install();row=next(r for r in self.manifest['files'] if r['path']=='app/controllers/ConvocatoriaController.php');file=self.site/row['path'];native=v5.unpack(row['base']);self.assertNotIn(b'private function guardarInternacional()',native);file.write_bytes(native)
        self.run_tool('--check');self.run_tool('--apply');self.assertEqual(file.read_bytes().replace(b'\r\n',b'\n'),v5.unpack(row['target']));self.assertNotIn(b'AliadoInternacionalService',file.read_bytes())
    def test_no_sql_ni_borradores(self):
        self.assertTrue(all(not row['path'].endswith('.sql') and 'Borrador' not in row['path'] for row in self.manifest['files']));self.assertNotIn('subprocess',(ROOT/'tools/publicar_registro_enlaces.py').read_text(encoding='utf-8'))
if __name__=='__main__':unittest.main()
