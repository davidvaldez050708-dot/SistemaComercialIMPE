"""Etapa 3 sobre la variante pública de Hostinger, sin reemplazar sus rutas."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = 'public/javascript/crear_convocatoria.js'
spec = importlib.util.spec_from_file_location('etapa3_base', str(ROOT / 'tests/publicacion_convenios_detalle_etapa3_test.py'))
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)


class ConveniosEtapa3Hostinger(base.ConveniosDetalleSelectivo):
    def install(self, crlf=False, customized=False):
        originals = super().install(crlf, customized)
        row = next(r for r in self.manifest['files'] if r['path'] == SCRIPT)
        data = base.gzip.decompress(base.base64.b64decode(row['variants'][0]['base']))
        if crlf:
            data = data.replace(b'\n', b'\r\n')
        (self.site / SCRIPT).write_bytes(data)
        originals[SCRIPT] = data
        return originals

    def verify(self, crlf=False, customized=False):
        originals = self.install(crlf, customized)
        self.assertIn('VARIANTE REVISADA', self.run_tool('--check'))
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes(), data)
        out = self.run_tool('--apply')
        for row in self.manifest['files']:
            target = row['variants'][0]['target'] if row['path'] == SCRIPT else row['target']
            expected = base.gzip.decompress(base.base64.b64decode(target)).replace(b'\r\n', b'\n')
            actual = (self.site / row['path']).read_bytes()
            self.assertEqual(actual.replace(b'\r\n', b'\n'), expected)
            if crlf:
                self.assertEqual(actual.count(b'\r\n'), actual.count(b'\n'))
        self.assertIn('ya publicada', self.run_tool('--apply'))
        self.run_tool('--restore', out.split('Respaldo: ')[1].splitlines()[0])
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes(), data)

    def test_personalizaciones_y_crlf(self):
        self.verify(crlf=True)

    def test_variante_solo_modifica_apertura(self):
        self.install()
        row = next(r for r in self.manifest['files'] if r['path'] == SCRIPT)
        variant = row['variants'][0]
        original, candidate = [base.gzip.decompress(base.base64.b64decode(variant[key])) for key in ('base', 'target')]
        old = b"        card.addEventListener('click', function () {\n            let data = {};"
        new = b"        // La interaccion pertenece al boton nativo, tambien para Enter y Espacio.\n        card.addEventListener('click', function (event) {\n            if (!event.target.closest('[data-convenio-open]')) return;\n            let data = {};"
        self.assertEqual(candidate, original.replace(old, new, 1))
        self.assertIn(b'aliado_internacional_id', candidate)
        self.assertIn(b"createLink.hidden = data.origen_tipo === 'manual';", candidate)

    def test_variante_desconocida_bloqueada(self):
        originals = self.install()
        changed = originals[SCRIPT].replace(b"card.addEventListener('click', function () {", b"card.addEventListener('click', function (custom) {")
        self.assertNotEqual(changed, originals[SCRIPT])
        (self.site / SCRIPT).write_bytes(changed)
        originals[SCRIPT] = changed
        self.assertIn('CONFLICTO', self.run_tool('--apply', ok=False))
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes(), data)


if __name__ == '__main__':
    unittest.main()
