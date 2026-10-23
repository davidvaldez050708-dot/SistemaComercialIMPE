"""Entrega backend de enlaces: no reemplaza el editor ni personalizaciones internacionales."""
import importlib.util,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('publicacion_v5',ROOT/'tests/publicacion_convocatorias_gestion_v5_test.py');v5=importlib.util.module_from_spec(spec);spec.loader.exec_module(v5)
v5.base.MANIFEST=ROOT/'tools/aliado_enlaces_cuenta_clave_release.json'
v5.base.FILES={'app/services/AliadoRegistroService.php','app/controllers/ConvocatoriaController.php'}
class EnlacesCuentaClave(v5.PublicacionV5):
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='app/services/AliadoRegistroService.php';old='Operación exclusiva de Marketing.'.encode();self.assertIn(old,originals[name]);originals[name]=originals[name].replace(old,b'Acceso personalizado');(self.site/name).write_bytes(originals[name]);self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_conflicto_al_restaurar(self):
        self.install();out=self.run_tool('--apply');file=self.site/'app/services/AliadoRegistroService.php';data=file.read_bytes()+b'\n// Cambio posterior\n';file.write_bytes(data);self.assertIn('archivo modificado desde la',self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0],ok=False));self.assertEqual(file.read_bytes(),data)
    def test_dependencia_no_revisada(self):
        self.assertEqual(self.manifest['dependencies'],[]);self.install();file=self.site/'public/javascript/convenios_borradores_v4.js';file.parent.mkdir(parents=True,exist_ok=True);data=b'// Editor personalizado vigente\n';file.write_bytes(data);self.run_tool('--apply');self.assertEqual(file.read_bytes(),data)
    def test_conserva_internacionales_y_modular(self):
        rows={r['path']:r for r in self.manifest['files']};self.assertEqual(len(rows),2)
        controller=v5.unpack(rows['app/controllers/ConvocatoriaController.php']['variants'][0]['target'])
        self.assertIn(b'private function guardarInternacional()',controller);self.assertIn(b'Esta convocatoria se gestiona desde Aliados internacionales.',controller)
        service=v5.unpack(rows['app/services/AliadoRegistroService.php']['target']);self.assertIn(b"$this->origen($tipo,$id,$marketing)",service);self.assertIn(b"$this->autorizar('formularios_aliados.gestionar_enlaces')",service)
        self.assertEqual(self.manifest['base'],'ebe671564cd9a33c268156f2cb1a5e6fdae4170a')
if __name__=='__main__':unittest.main()
