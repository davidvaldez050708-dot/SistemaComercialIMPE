"""Compatibilidad exacta con el diff de sidebar comunicado desde Hostinger."""
import base64
import gzip
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parent.parent
SIDEBAR = 'app/views/layout/sidebar.php'
spec = importlib.util.spec_from_file_location('publicacion_base', str(ROOT / 'tests/publicacion_convenios_detalle_etapa3_test.py'))
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
base.MANIFEST = ROOT / 'tools/convenios_acceso_release.json'
base.FILES = {SIDEBAR, 'app/helpers/PermissionHelper.php', 'app/controllers/CrearConvocatoriaController.php'}


def personalizar_sidebar(raw):
    """Reproduce los cinco cambios proporcionados, sin inferir otras variantes."""
    text = raw.replace(b'\r\n', b'\n').decode('utf-8')

    def reemplazar(old, new):
        nonlocal text
        if text.count(old) != 1:
            raise ValueError('Ancla incompatible: ' + old)
        text = text.replace(old, new, 1)

    anchor = "require_once __DIR__ . '/../../helpers/AvatarHelper.php';"
    reemplazar(anchor, anchor + "\nrequire_once __DIR__ . '/../../services/AliadoInternacionalService.php';")
    reemplazar("$mostrarAliados = tienePermiso('aliados.ver');", "$mostrarAliados = tienePermiso('aliados.ver') || AliadoInternacionalService::puedeConsultar();")
    reemplazar("$claseProspectos =\n    $opcionActiva === 'prospectos' ? 'active' : '';", "$claseProspectos = $opcionActiva === 'prospectos' ? 'active' : '';")
    for width in (20, 24):
        indent = ' ' * width
        href = 'href="<?= BASE_URL ?>index.php?controller=prospecto&action=index"'
        style = 'class="sidebar-link <?= $claseProspectos ?>">'
        old = indent + '<a\n' + indent + '    ' + href + '\n' + indent + '    ' + style + '\n' + indent + '    <i class="bi bi-people"></i>\n' + indent + '    Prospectos'
        new = indent + '<a ' + href + ' ' + style + '\n' + indent + '    <i class="bi bi-people"></i> Prospectos'
        reemplazar(old, new)
    return text.encode('utf-8')


def unpack(value):
    return gzip.decompress(base64.b64decode(value))


class PublicacionConveniosAcceso(base.ConveniosDetalleSelectivo):
    def setUp(self):
        super().setUp()
        # Dependencias existentes: sólo fixture local, nunca parte de la entrega.
        for name in ['app/helpers/AvatarHelper.php', 'app/helpers/AdminRolePolicy.php']:
            file = self.site / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes((ROOT / name).read_bytes())
        stub = self.site / 'app/services/AliadoInternacionalService.php'
        stub.parent.mkdir(parents=True, exist_ok=True)
        stub.write_text("<?php class AliadoInternacionalService { public static function puedeConsultar() { return in_array('internacional_prueba', $_SESSION['permisos'] ?? [], true); } }", encoding='utf-8')

    def install(self, crlf=False, customized=False):
        originals = super().install(crlf, customized)
        data = personalizar_sidebar(originals[SIDEBAR])
        if crlf:
            data = data.replace(b'\n', b'\r\n')
        (self.site / SIDEBAR).write_bytes(data)
        originals[SIDEBAR] = data
        return originals

    def verify(self, crlf=False, customized=False):
        originals = self.install(crlf, customized)
        self.assertIn('VARIANTE REVISADA', self.run_tool('--check'))
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes(), data)
        out = self.run_tool('--apply')
        for row in self.manifest['files']:
            installed = (self.site / row['path']).read_bytes()
            actual = installed.replace(b'\r\n', b'\n')
            desired = unpack(row['target']).replace(b'\r\n', b'\n')
            if row['path'] == SIDEBAR:
                desired = personalizar_sidebar(desired)
            actual = actual.replace(b'\n// Personalizacion Hostinger preservada\n', b'')
            self.assertEqual(actual, desired)
            if crlf:
                self.assertEqual(installed.count(b'\r\n'), installed.count(b'\n'))
        self.assertIn('ya publicada', self.run_tool('--apply'))
        self.run_tool('--restore', out.split('Respaldo: ')[1].splitlines()[0])
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes(), data)

    def test_personalizaciones_y_crlf(self):
        # La variante exacta admite LF/CRLF. Una personalización extra desconocida
        # no se considera la variante revisada: continúa por el merge conservador.
        self.verify(crlf=True)

    def test_conflicto_no_escribe_ningun_archivo(self):
        originals = self.install()
        changed = originals[SIDEBAR].replace(b'AliadoInternacionalService::puedeConsultar();', b'AliadoInternacionalService::puedeConsultar() || true;')
        self.assertNotEqual(changed, originals[SIDEBAR])
        (self.site / SIDEBAR).write_bytes(changed)
        originals[SIDEBAR] = changed
        self.assertIn('CONFLICTO', self.run_tool('--apply', ok=False))
        for name, data in originals.items():
            self.assertEqual((self.site / name).read_bytes(), data)

    def test_manifiesto_coincide_con_diff_revisado(self):
        for row in self.manifest['files']:
            if row['path'] != SIDEBAR:
                self.assertFalse(row.get('variants'))
                continue
            self.assertEqual(len(row['variants']), 1)
            variant = row['variants'][0]
            self.assertEqual(unpack(variant['base']), personalizar_sidebar(unpack(row['base'])))
            self.assertEqual(unpack(variant['target']), personalizar_sidebar(unpack(row['target'])))

    def test_menu_preserva_internacionales_y_permisos_convenios(self):
        self.install()
        self.run_tool('--apply')
        cases = [(['convenios.ver', 'internacional_prueba'], True, True), (['convenios.gestionar'], True, False), (['internacional_prueba'], False, True), ([], False, False), (['aliados.ver'], False, True)]
        for permissions, convenios, aliados in cases:
            with self.subTest(permissions=permissions):
                payload = base64.b64encode(json.dumps(permissions).encode()).decode()
                script = "define('BASE_URL','/');define('ROOT_PATH',getcwd());$_SESSION=['usuario_id'=>2,'rol_id'=>6,'rol'=>'Cuenta Clave','permisos'=>json_decode(base64_decode('" + payload + "'),true)];$nombreCompleto='Prueba';require 'app/views/layout/sidebar.php';"
                run = subprocess.run(['php', '-r', script], cwd=str(self.site), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                self.assertEqual(run.returncode, 0)
                self.assertEqual(run.stderr, b'')
                self.assertEqual(run.stdout.count(b'controller=crearConvocatoria&action=index'), 2 if convenios else 0)
                self.assertEqual(run.stdout.count(b'controller=aliado&action=index'), 2 if aliados else 0)


if __name__ == '__main__':
    unittest.main()
