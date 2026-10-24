"""Ajuste selectivo de un CSS, con revisión, conflicto, backup y restauración."""
import importlib.util
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('base_test',str(ROOT/'tests/publicacion_convenios_detalle_etapa3_test.py'))
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
base.MANIFEST=ROOT/'tools/convenios_tabla_scroll_release.json'
base.FILES={'public/css/convenios_directorio_tabla.css'}
class PublicacionScroll(base.ConveniosDetalleSelectivo):
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='public/css/convenios_directorio_tabla.css'
        data=originals[name].replace(b'overflow-x: auto;',b'overflow-x: scroll;');self.assertNotEqual(data,originals[name]);(self.site/name).write_bytes(data);originals[name]=data
        self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False))
        for name,original in originals.items():self.assertEqual((self.site/name).read_bytes(),original,name)
    def test_paquete_coincide_con_commit(self):
        row=self.manifest['files'][0]
        self.assertEqual(base.gzip.decompress(base.base64.b64decode(row['target'])),subprocess.check_output(['git','show',self.manifest['source']+':'+row['path']],cwd=str(ROOT)))
if __name__=='__main__':unittest.main()
