"""Paquete de Prospectos, conservando la variante internacional ya revisada."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('compat_convenios', str(ROOT/'tests/publicacion_convenios_acceso_test.py'))
compat = importlib.util.module_from_spec(spec)
spec.loader.exec_module(compat)
compat.base.MANIFEST = ROOT/'tools/prospectos_acceso_release.json'
compat.base.FILES = {compat.SIDEBAR, 'app/controllers/ProspectoController.php', 'app/models/RolModel.php'}


class PublicacionProspectosAcceso(compat.PublicacionConveniosAcceso):
    def setUp(self):
        super().setUp()
        # Dependencia del paquete anterior de Convenios; no se publica de nuevo.
        (self.site/'app/helpers/PermissionHelper.php').write_bytes((ROOT/'app/helpers/PermissionHelper.php').read_bytes())

    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install()
        changed=originals[compat.SIDEBAR].replace(b'$mostrarConvocatorias || $mostrarConvenios', b'$mostrarConvocatorias || false')
        self.assertNotEqual(changed, originals[compat.SIDEBAR])
        (self.site/compat.SIDEBAR).write_bytes(changed)
        originals[compat.SIDEBAR]=changed
        self.assertIn('CONFLICTO', self.run_tool('--apply', ok=False))
        for name, data in originals.items():
            self.assertEqual((self.site/name).read_bytes(), data)

    def test_base_github(self):
        originals=compat.base.ConveniosDetalleSelectivo.install(self)
        self.assertNotIn('VARIANTE REVISADA', self.run_tool('--check'))
        out=self.run_tool('--apply')
        for row in self.manifest['files']:
            self.assertEqual((self.site/row['path']).read_bytes().replace(b'\r\n',b'\n'), compat.unpack(row['target']))
        self.run_tool('--restore', out.split('Respaldo: ')[1].splitlines()[0])
        for name, data in originals.items():
            self.assertEqual((self.site/name).read_bytes(), data)


if __name__ == '__main__':
    unittest.main()
