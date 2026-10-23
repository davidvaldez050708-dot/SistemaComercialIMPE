"""QR recuperable: entrega de tres archivos sobre la corrección UI instalada."""
import importlib.util,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('publicacion_v5',ROOT/'tests/publicacion_convocatorias_gestion_v5_test.py');v5=importlib.util.module_from_spec(spec);spec.loader.exec_module(v5)
v5.base.MANIFEST=ROOT/'tools/convocatorias_gestion_v5_qr_release.json'
v5.base.FILES={'app/views/convocatorias/index.php','public/javascript/convocatorias_gestion_v5.js','public/css/convocatorias_gestion_v5.css'}
class QrV5(v5.PublicacionV5):
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='public/css/convocatorias_gestion_v5.css';old=b'font-size: 16px; color: #172642;';self.assertIn(old,originals[name]);originals[name]=originals[name].replace(old,b'font-size: 18px; color: #172642;');(self.site/name).write_bytes(originals[name]);self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_conserva_internacionales_y_modular(self):
        rows={r['path']:r for r in self.manifest['files']}
        self.assertEqual(len(rows),3)
        index=v5.unpack(rows['app/views/convocatorias/index.php']['variants'][0]['target'])
        self.assertIn(b"'/partials/crear_modal.php'",index);self.assertIn(b"'/partials/aliado_programas_modular.php'",index)
        self.assertEqual(index.count(b'<script src="<?= BASE_URL ?>public/vendor/qrcode-generator/qrcode.js"></script>'),1)
        self.assertNotIn('app/controllers/ConvocatoriaController.php',rows)
        self.assertEqual(self.manifest['base'],'a7b49dfdf95ef3bc684624a561bd3643411e4f5c')
if __name__=='__main__':unittest.main()
