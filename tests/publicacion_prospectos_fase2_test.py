"""Publicación incremental de Fase 2; las lecturas SQL reales se prueban en CI."""
import importlib.util,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('fase1',ROOT/'tests/publicacion_prospectos_fase1_test.py');fase1=importlib.util.module_from_spec(spec);spec.loader.exec_module(fase1)
spec=importlib.util.spec_from_file_location('publisher2',ROOT/'tools/publicar_prospectos_fase2.py');fase1.publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(fase1.publisher)
fase1.base.MANIFEST=ROOT/'tools/prospectos_fase2_release.json';fase1.base.PUBLISHER=ROOT/'tools/publicar_prospectos_fase2.py'
class Publicacion(fase1.Publicacion):
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='app/models/ProspectoModel.php'
        changed=originals[name].replace(b'SELECT COUNT(*) total FROM formulario_registros r',b'SELECT MAX(r.id) total FROM formulario_registros r')
        self.assertNotEqual(changed,originals[name]);(self.site/name).write_bytes(changed);originals[name]=changed
        self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
if __name__=='__main__':unittest.main()
