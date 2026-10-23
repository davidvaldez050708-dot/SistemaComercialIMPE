"""Paquete de identidad única: sin SQL automático, conserva modal y modelo internacional."""
import importlib.util
from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('publicacion_base',str(ROOT/'tests/publicacion_convenios_detalle_etapa3_test.py'))
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
base.MANIFEST=ROOT/'tools/bachillerato_unificacion_release.json'
base.PUBLISHER=ROOT/'tools/publicar_bachillerato_unificado.py'
base.FILES={
 'app/helpers/ProgramaEducativo.php','app/controllers/ConvocatoriaController.php','app/controllers/FormularioController.php',
 'app/controllers/FormularioPublicoController.php','app/models/ConvocatoriaModel.php','app/models/FormularioRegistroModel.php',
 'app/services/AliadoRegistroService.php','app/services/FormularioAliadoService.php','app/services/ReporteConvocatoriaDataService.php',
 'app/services/ReporteConvocatoriaPdfService.php','app/views/convocatorias/index.php','app/views/reportes/convocatorias.php',
 'database/migrations/2026_10_21_aliados_registro.sql','tools/migrar_aliados_registro.php',
 'tools/unificar_bachillerato_general.php','tools/lib/UnificarBachillerato.php',
 'public/javascript/convocatorias_aliado_multiprograma_v3.js',
}
unpack=lambda value:base.gzip.decompress(base.base64.b64decode(value)).replace(b'\r\n',b'\n')


class PublicacionUnificacion(base.ConveniosDetalleSelectivo):
    optional=False

    def install(self,crlf=False,customized=False):
        originals=super().install(crlf,customized)
        if not self.optional:return originals
        for row in self.manifest['files']:
            if not row.get('merge_bases'):continue
            data=unpack(row['merge_bases'][0]['base'])
            if customized:
                data=data.replace(b'<?php',b'<?php\n// Personalizacion Hostinger preservada\n',1) if row['path'].endswith('.php') else b'/* Personalizacion Hostinger preservada */\n'+data
            if crlf:data=data.replace(b'\n',b'\r\n')
            (self.site/row['path']).write_bytes(data);originals[row['path']]=data
        return originals

    def verify_originals(self,files):
        for name,data in files.items():self.assertEqual((self.site/name).read_bytes() if (self.site/name).exists() else None,data,name)

    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='app/services/AliadoRegistroService.php'
        old=b"'bachillerato-286'=>['bachillerato|bachillerato-286']"
        changed=originals[name].replace(old,b"'bachillerato-286'=>['titulacion|ejecutivas']",1)
        self.assertNotEqual(changed,originals[name]);(self.site/name).write_bytes(changed);originals[name]=changed
        self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.verify_originals(originals)

    def test_modelo_solo_tres_lineas_con_codigo_hostinger(self):
        spec=importlib.util.spec_from_file_location('union_overlay',str(ROOT/'tools/publicar_bachillerato_unificado.py'))
        union=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(union)
        core=union.load_core()
        row=next(r for r in self.manifest['files'] if r['path']=='app/models/ConvocatoriaModel.php')
        for source in (unpack(row['base']),unpack(row['variants'][0]['base'])):
            # Simulates real customization near BOTH injection points.
            self.assertEqual(source.count(b'        $esInscripcionesAbiertas ='),2)
            current=source.replace(
                b'        $esInscripcionesAbiertas =',
                b'        // Personalizacion productiva: conservar callback y lote.\n        $esInscripcionesAbiertas =')
            for crlf in (False,True):
                with self.subTest(source=len(source),crlf=crlf):
                    original=current.replace(b'\n',b'\r\n') if crlf else current
                    candidate=union.overlay_modelo_con_verificacion(row,original,core)
                    expected=union.overlay_modelo_convocatoria(original)
                    self.assertEqual(candidate,expected)
                    self.assertEqual(candidate.count(b'ProgramaEducativo::entrada($datos)'),2)
                    self.assertIn(b'Personalizacion productiva',candidate)
                    self.assertEqual(union.overlay_modelo_convocatoria(candidate),candidate)
                    # Removing the 3 exact hooks must recover the original bytes.
                    reduced=candidate.replace(
                        b"require_once __DIR__ . '/../helpers/ProgramaEducativo.php';"+(b'\r\n' if crlf else b'\n'),b'')
                    reduced=re.sub(br'(?m)^[ \t]*\$datos = ProgramaEducativo::entrada\(\$datos\);[ \t]*\r?\n',b'',reduced)
                    self.assertEqual(reduced,original)

    def test_modelo_personalizado_aplica_y_restaura_aislado(self):
        spec=importlib.util.spec_from_file_location('union_overlay',str(ROOT/'tools/publicar_bachillerato_unificado.py'))
        union=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(union)
        originals=self.install()
        name='app/models/ConvocatoriaModel.php'
        current=originals[name]
        self.assertEqual(current.count(b'        $esInscripcionesAbiertas ='),2)
        current=current.replace(
            b'        $esInscripcionesAbiertas =',
            b'        // HOSTINGER: conservar reglas particulares.\n        $esInscripcionesAbiertas =')
        (self.site/name).write_bytes(current)
        originals[name]=current
        self.run_tool('--check')
        self.verify_originals(originals)
        output=self.run_tool('--apply')
        self.assertEqual((self.site/name).read_bytes(),union.overlay_modelo_convocatoria(current))
        # Repeated checks must never duplicate identity hooks.
        self.run_tool('--check')
        self.run_tool('--restore',output.split('Respaldo: ')[1].splitlines()[0])
        self.verify_originals(originals)

    def test_modelo_overlay_rechaza_estructura_incierta(self):
        spec=importlib.util.spec_from_file_location('union_overlay',str(ROOT/'tools/publicar_bachillerato_unificado.py'))
        union=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(union)
        row=next(r for r in self.manifest['files'] if r['path']=='app/models/ConvocatoriaModel.php')
        original=unpack(row['base'])
        for changed in (
            original.replace(b'public function actualizar(',b'public function editarNoRevisado(',1),
            original.replace(b'public function crear(',b'public function crear('+
                             b'$datos, $estadosIds) {}\n    public function crear(',1),
            original.replace(b"require_once __DIR__ . '/../../config/db_connection.php';",b'/* conexion personalizada no revisada */',1),
        ):
            with self.assertRaises(RuntimeError):
                union.overlay_modelo_convocatoria(changed)

    def test_dependencia_primero(self):
        self.assertEqual(self.manifest['files'][0]['path'],'app/helpers/ProgramaEducativo.php')

    def test_parche_general_previo_opcional(self):
        self.optional=True
        self.verify(crlf=True,customized=True)

    def test_modelo_internacional_y_crlf(self):
        originals=self.install(crlf=True);name='app/models/ConvocatoriaModel.php'
        row=next(r for r in self.manifest['files'] if r['path']==name);variant=row['variants'][0]
        originals[name]=unpack(variant['base']).replace(b'\n',b'\r\n');(self.site/name).write_bytes(originals[name])
        self.assertIn('VARIANTE REVISADA',self.run_tool('--check'));self.verify_originals(originals)
        out=self.run_tool('--apply');self.assertEqual((self.site/name).read_bytes(),unpack(variant['target']).replace(b'\n',b'\r\n'))
        self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.verify_originals(originals)

    def test_variante_modelo_solo_normaliza_entradas(self):
        row=next(r for r in self.manifest['files'] if r['path']=='app/models/ConvocatoriaModel.php');variant=row['variants'][0]
        original,candidate=unpack(variant['base']),unpack(variant['target'])
        self.assertEqual(candidate.count(b'$datos = ProgramaEducativo::entrada($datos);'),2)
        stripped=candidate.replace(b"require_once __DIR__ . '/../helpers/ProgramaEducativo.php';\n",b'').replace(b'\n        $datos = ProgramaEducativo::entrada($datos);',b'')
        self.assertEqual(stripped,original,'conexión, callback, lote y demás métodos deben conservarse byte a byte')

    def test_formularios_internacionales(self):
        for crlf in (False,True):
            with self.subTest(crlf=crlf):
                originals=self.install(crlf=crlf)
                variants={}
                for row in self.manifest['files']:
                    if not row['path'].startswith(('app/controllers/FormularioPublico','app/models/FormularioRegistro','app/services/FormularioAliado')):continue
                    variant=row['variants'][0];name=row['path'];variants[name]=variant
                    data=unpack(variant['base']);data=data.replace(b'\n',b'\r\n') if crlf else data
                    (self.site/name).write_bytes(data);originals[name]=data
                self.assertIn('VARIANTE REVISADA',self.run_tool('--check'));self.verify_originals(originals)
                out=self.run_tool('--apply')
                for name,variant in variants.items():
                    actual=(self.site/name).read_bytes();expected=unpack(variant['target'])
                    self.assertEqual(actual,expected.replace(b'\n',b'\r\n') if crlf else expected)
                    self.assertNotIn(b'Bachillerato 286',actual)
                self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.verify_originals(originals)

    def test_variante_formulario_preserva_pais_y_convocatoria(self):
        name='app/services/FormularioAliadoService.php';row=next(r for r in self.manifest['files'] if r['path']==name)
        original,candidate=[unpack(row['variants'][0][key]) for key in ('base','target')]
        for marker in [b"$aliado['internacional'] =",b"$datos = FormularioUbicacionService::validar",b"$aliado = isset($datos['convocatoria_ref'])",b"$id = (int)$insertar($datos);",b"UPDATE formulario_aliado_procedencias SET convocatoria_id=?"]:
            self.assertEqual([line for line in original.splitlines() if marker in line],[line for line in candidate.splitlines() if marker in line])
        self.assertIn(b"isset($aliado['convocatoria_id']) ? (int)$aliado['convocatoria_id'] : null",candidate)
        self.assertIn(b"$p['codigo']===ProgramaEducativo::codigo($fila['programa_codigo'])",candidate)

    def test_index_modular_conservado(self):
        originals=self.install();name='app/views/convocatorias/index.php'
        def modularize(data):
            data=data.replace(b'\r\n',b'\n');start=data.rfind(b'<div',0,data.index(b'    id="modalCrearConvocatoria"'));end=data.rfind(b'<div',0,data.index(b'    id="modalConvocatoriaQr"'))
            return data[:start]+b"<?php require __DIR__ . '/partials/crear_modal.php'; ?>\n<?php require __DIR__ . '/partials/aliado_programas_modular.php'; ?>\n"+data[end:]
        originals[name]=modularize(originals[name]);(self.site/name).write_bytes(originals[name])
        protected='app/views/convocatorias/partials/aliado_programas_modular.php';file=self.site/protected;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(b'Modal institucional personalizado con SHA256 intocable');originals[protected]=file.read_bytes()
        self.run_tool('--check');self.verify_originals(originals);out=self.run_tool('--apply')
        target=next(r['target'] for r in self.manifest['files'] if r['path']==name)
        self.assertEqual((self.site/name).read_bytes().replace(b'\r\n',b'\n'),modularize(unpack(target)))
        self.assertEqual(file.read_bytes(),originals[protected])
        self.run_tool('--restore',out.split('Respaldo: ')[1].splitlines()[0]);self.verify_originals(originals)

    def test_index_modular_con_parche_general_previo(self):
        self.optional=True
        self.test_index_modular_conservado()


if __name__=='__main__':unittest.main()
