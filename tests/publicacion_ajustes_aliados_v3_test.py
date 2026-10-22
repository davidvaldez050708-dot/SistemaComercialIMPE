#!/usr/bin/env python3
import hashlib
import importlib.util
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("publicador_v3",ROOT/"tools/publicar_ajustes_aliados_v3.py")
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class PublicadorAjustesV3Test(unittest.TestCase):
    def test_manifest_inmutable_reproduce_git(self):
        for path,expected in module.SHA.items():
            content=subprocess.check_output(["git","show",module.SOURCE+":"+path],cwd=str(ROOT))
            self.assertEqual(module.blob(content),expected,path)

    def test_modulo_viejo_con_ancla_unica_inserta_solo_dos_assets(self):
        content=b'<div>Plantilla original</div>\n<script defer src="assets/convocatorias_aliado_modular.js?v=1"></script>\n'
        original=module.MODULE_OLD
        try:
            module.MODULE_OLD=hashlib.sha256(content).hexdigest()
            patched=module.module_patch(content)
            self.assertEqual(patched.count(module.SNIPPET),1)
            self.assertIn(content[:content.index(b'</script>')+len(b'</script>')],patched)
            self.assertEqual(module.module_patch(patched),patched)
        finally:
            module.MODULE_OLD=original

    def test_modulo_desconocido_falla_cerrado(self):
        with self.assertRaisesRegex(RuntimeError,'personalizado'):
            module.module_patch(b'<script src="convocatorias_aliado_modular.js?v=1"></script>')

    def test_conflictos_js_y_php_se_rechazan(self):
        with tempfile.TemporaryDirectory() as folder:
            base=b'HEADER\nlinea\nFOOTER\n'
            desired=b'HEADER\nNUEVA\nFOOTER\n'
            current=b'HEADER\nPRODUCCION\nFOOTER\n'
            with self.assertRaisesRegex(RuntimeError,"CONFLICTO"):
                module.combine(current,base,desired,Path(folder),'abc.php')

    def test_fusion_no_solapada_preserva_personalizaciones(self):
        with tempfile.TemporaryDirectory() as folder:
            old=b'header\nlinea fija\nseccion final\n'
            actual=b'header local\nlinea fija\nseccion final\n'
            target=b'header\nlinea fija\nseccion final v3\n'
            result=module.combine(actual,old,target,Path(folder),'abc.css')
            self.assertIn(b'header local',result)
            self.assertIn(b'seccion final v3',result)

    def test_documentos_fuera_sitio_no_se_reescriben(self):
        with tempfile.TemporaryDirectory() as folder:
            site=Path(folder)/'site'
            site.mkdir()
            outside=Path(folder)/'danger'
            outside.write_bytes(b'original')
            (site/'archivo.php').symlink_to(outside)
            with self.assertRaisesRegex(RuntimeError,'Ruta insegura'):
                module.located(site,'archivo.php')
            self.assertEqual(outside.read_bytes(),b'original')

if __name__=='__main__':
    unittest.main()
