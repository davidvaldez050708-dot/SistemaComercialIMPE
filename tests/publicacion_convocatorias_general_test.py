"""Bachillerato General: preservar el index modular y detener conflictos reales."""
import importlib.util
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('publicacion_base',str(ROOT/'tests/publicacion_convenios_detalle_etapa3_test.py'))
base=importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
base.MANIFEST=ROOT/'tools/convocatorias_general_release.json'
base.FILES={'app/services/AliadoRegistroService.php','app/controllers/ConvocatoriaController.php','app/views/convocatorias/index.php','app/services/ReporteConvocatoriaDataService.php','public/javascript/convocatorias_aliado_multiprograma_v3.js'}


def modularizar(data):
    text=data.replace(b'\r\n',b'\n')
    start=text.rfind(b'<div',0,text.index(b'    id="modalCrearConvocatoria"'))
    end=text.rfind(b'<div',0,text.index(b'    id="modalConvocatoriaQr"'))
    return text[:start]+b"<?php require __DIR__ . '/partials/crear_modal.php'; ?>\n<?php require __DIR__ . '/partials/aliado_programas_modular.php'; ?>\n\n"+text[end:]


class PublicacionGeneral(base.ConveniosDetalleSelectivo):
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install()
        name='app/services/AliadoRegistroService.php'
        old=b"            'bachillerato-286'=>"
        changed=originals[name].replace(old,b"            'bachillerato-general'=>['titulacion|experiencia-laboral'],\n"+old,1)
        self.assertNotEqual(changed,originals[name])
        (self.site/name).write_bytes(changed);originals[name]=changed
        self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False))
        for name,data in originals.items():self.assertEqual((self.site/name).read_bytes(),data)

    def test_conserva_modal_modular_personalizado(self):
        originals=self.install()
        name='app/views/convocatorias/index.php'
        originals[name]=modularizar(originals[name]);(self.site/name).write_bytes(originals[name])
        protected='app/views/convocatorias/partials/aliado_programas_modular.php'
        file=self.site/protected;file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes(b'Componente personalizado con versiones SHA256 intocable\r\n');originals[protected]=file.read_bytes()
        self.run_tool('--check')
        for name,data in originals.items():self.assertEqual((self.site/name).read_bytes(),data)
        out=self.run_tool('--apply')
        for row in self.manifest['files']:
            desired=base.gzip.decompress(base.base64.b64decode(row['target'])).replace(b'\r\n',b'\n')
            if row['path']=='app/views/convocatorias/index.php':desired=modularizar(desired)
            self.assertEqual((self.site/row['path']).read_bytes().replace(b'\r\n',b'\n'),desired)
        self.assertEqual(file.read_bytes(),originals[protected])
        self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0])
        for name,data in originals.items():self.assertEqual((self.site/name).read_bytes(),data)


if __name__=='__main__':unittest.main()
