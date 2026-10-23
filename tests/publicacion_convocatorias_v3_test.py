"""Entrega de un único JS: sin sustituir el modal ni recursos personalizados."""
import importlib.util
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parent.parent
SCRIPT='public/javascript/convocatorias_aliado_multiprograma_v3.js'
spec=importlib.util.spec_from_file_location('publicacion_base',str(ROOT/'tests/publicacion_convenios_detalle_etapa3_test.py'))
base=importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
base.MANIFEST=ROOT/'tools/convocatorias_v3_release.json'
base.FILES={SCRIPT}


class PublicacionConvocatoriasV3(base.ConveniosDetalleSelectivo):
    def install(self,crlf=False,customized=False):
        originals=super().install(crlf,customized)
        for name in ['app/views/convocatorias/crear_modal.php','app/views/convocatorias/index.php','app/views/convocatorias/partials/aliado_programas_modular.php','public/javascript/convocatoria_crear_compartido.js','public/javascript/convocatorias_aliado_modular.js','public/css/convocatorias_aliado_multiprograma_v3.css','app/controllers/ConvocatoriaController.php','app/services/AliadoRegistroService.php']:
            p=self.site/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'Personalizacion Hostinger intocable\r\n');originals[name]=p.read_bytes()
        return originals

    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install()
        changed=originals[SCRIPT].replace(b'if (!byCode.size || byCode.size > 30) return;',b'if (!byCode.size || byCode.size > 15) return;')
        self.assertNotEqual(changed,originals[SCRIPT]);(self.site/SCRIPT).write_bytes(changed);originals[SCRIPT]=changed
        self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False))
        for name,data in originals.items():self.assertEqual((self.site/name).read_bytes(),data)


if __name__=='__main__':
    unittest.main()
