"""Correcciones de presentación sobre la V5 real instalada en Hostinger."""
import importlib.util,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('publicacion_v5',ROOT/'tests/publicacion_convocatorias_gestion_v5_test.py');v5=importlib.util.module_from_spec(spec);spec.loader.exec_module(v5)
v5.base.MANIFEST=ROOT/'tools/convocatorias_gestion_v5_ui_release.json'
v5.base.FILES={'app/views/convocatorias/index.php','public/javascript/convocatorias_gestion_v5.js','public/javascript/crear_convocatoria_v2_extension.js','public/css/convocatorias_gestion_v5.css','public/css/convocatorias_aliado_multiprograma_v3.css'}
class CorreccionesV5(v5.PublicacionV5):
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='app/views/convocatorias/index.php';old=b"detalleContenido.insertAdjacentHTML('beforeend'";self.assertIn(old,originals[name]);originals[name]=originals[name].replace(old,b"detalleContenido.insertAdjacentHTML('afterbegin'");(self.site/name).write_bytes(originals[name]);self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_conserva_internacionales_y_modular(self):
        rows={r['path']:r for r in self.manifest['files']}
        self.assertNotIn('app/controllers/ConvocatoriaController.php',rows)
        index=v5.unpack(rows['app/views/convocatorias/index.php']['variants'][0]['target']);self.assertIn(b"'/partials/crear_modal.php'",index);self.assertIn(b"'/partials/aliado_programas_modular.php'",index)
        css=rows['public/css/convocatorias_aliado_multiprograma_v3.css'];self.assertEqual(v5.unpack(css['target']).replace(b':is(#modalCrearConvocatoria, #modalEditarConvocatoria)',b'#modalCrearConvocatoria'),v5.unpack(css['base']))
if __name__=='__main__':unittest.main()
