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

    def test_convocatoria_controller_personalizado_añade_solo_metodo_v3(self):
        file="app/controllers/ConvocatoriaController.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        target=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        addition=("    // IMPE_HOSTINGER_PRUEBA_PRESERVAR: teléfono y convocatorias existentes\n").encode("utf-8")
        anchor=b"    public function listadoFiltrado()"
        self.assertEqual(old.count(anchor),1)
        live=old.replace(anchor,addition+anchor)
        merged=module.overlay_convocatoria_controller(live,old,target)
        self.assertIn(addition,merged)
        self.assertEqual(merged.count(b"public function guardarProgramasAliado("),1)
        self.assertEqual(module.overlay_convocatoria_controller(merged,old,target),merged)
        first=merged.index(("    /**\n     * Crea una publicación real por programa autorizado").encode("utf-8"))
        last=merged.index(b"    public function actualizar()",first)
        self.assertEqual(merged[:first]+merged[last:],live)
        with tempfile.TemporaryDirectory() as dirname:
            merged_file=Path(dirname)/"controller.php"
            merged_file.write_bytes(merged)
            check=subprocess.run(["php","-l",str(merged_file)],
                stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            self.assertEqual(check.returncode,0,check.stderr.decode("utf-8","replace"))

    def test_convocatoria_controller_crlf_personalizado_sin_perder_bytes(self):
        file="app/controllers/ConvocatoriaController.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        target=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        live=old.replace(b"\n",b"\r\n")
        merged=module.overlay_convocatoria_controller(live,old,target)
        self.assertEqual(module.overlay_convocatoria_controller(merged,old,target),merged)
        self.assertIn(b"public function guardarProgramasAliado(",merged)
        self.assertNotIn(b"\n",merged.replace(b"\r\n",b""))

    def test_convocatoria_controller_metodo_preexistente_diferente_se_rechaza(self):
        file="app/controllers/ConvocatoriaController.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        target=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        custom=old.replace(b"    public function actualizar()",
            b"    public function guardarProgramasAliado() { return false; }\n"+
            b"    public function actualizar()",1)
        with self.assertRaisesRegex(RuntimeError,"ya existe guardarProgramasAliado"):
            module.overlay_convocatoria_controller(custom,old,target)

    def test_convocatoria_controller_revisión_origen_no_es_inserción_rechazada(self):
        file="app/controllers/ConvocatoriaController.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        target=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        modified=target.replace(b"convocatorias.crear",b"convocatorias.ver",1)
        # Cambiar código preexistente además de añadir el método invalida el contrato.
        modified=modified.replace(b"require_once __DIR__",b"require_once __DIRX__",1)
        with self.assertRaisesRegex(RuntimeError,"inserción pura"):
            module.overlay_convocatoria_controller(old,old,modified)

    def test_modelo_convocatoria_personalizado_preserva_otros_metodos(self):
        file="app/models/ConvocatoriaModel.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        desired=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        original=b"    public function buscarPorId($id)"
        self.assertEqual(old.count(original),1)
        changed=old.replace(original,
            b"    // Personalizado en Hostinger: no editar\n"+original,1)
        patched=module.overlay_convocatoria_model(changed,old,desired)
        self.assertIn(b"Personalizado en Hostinger",patched)
        self.assertIn(b"public function iniciarLote()",patched)
        self.assertIn(b"public function crear($datos, $estadosIds, $transaccionExterna = false)",patched)
        self.assertEqual(module.overlay_convocatoria_model(patched,old,desired),patched)
        with tempfile.TemporaryDirectory() as folder:
            file_path=Path(folder)/"modelo.php"
            file_path.write_bytes(patched)
            result=subprocess.run(["php","-l",str(file_path)],
                stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            self.assertEqual(result.returncode,0,result.stderr.decode("utf-8","replace"))

    def test_modelo_v3_reproduce_source_exactamente(self):
        file="app/models/ConvocatoriaModel.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        desired=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        self.assertEqual(module.overlay_convocatoria_model(old,old,desired),desired)
        self.assertEqual(module.overlay_convocatoria_model(desired,old,desired),desired)

    def test_modelo_crear_personalizado_fuera_de_seis_cambios_se_conserva(self):
        file="app/models/ConvocatoriaModel.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        desired=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        anchor=b"            $convocatoriaId = (int)$this->connection->insert_id;"
        self.assertEqual(old.count(anchor),1)
        custom=b"            // Integracion Hostinger personal: preservar este punto\n"
        live=old.replace(anchor,custom+anchor,1)
        patched=module.overlay_convocatoria_model(live,old,desired)
        self.assertIn(custom,patched)
        self.assertEqual(module.overlay_convocatoria_model(patched,old,desired),patched)
        self.assertIn(b"if (!$transaccionExterna) $this->connection->commit();",patched)
        self.assertEqual(patched.count(b"marketing_aliado_convocatorias_origen"),1)
        self.assertEqual(patched.count(b"public function iniciarLote()"),1)
        with tempfile.TemporaryDirectory() as folder:
            dest=Path(folder)/"convocatoria.php"
            dest.write_bytes(patched)
            result=subprocess.run(["php","-l",str(dest)],
                stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            self.assertEqual(result.returncode,0,result.stderr.decode("utf-8","replace"))

    def test_modelo_crear_personalizado_crlf_reversible(self):
        file="app/models/ConvocatoriaModel.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        desired=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        live=old.replace(b"\n",b"\r\n")
        customized=live.replace(b"            $convocatoriaId = (int)",
            b"            // NOTA PRODUCTIVA\r\n            $convocatoriaId = (int)",1)
        upgraded=module.overlay_convocatoria_model(customized,old,desired)
        self.assertIn(b"// NOTA PRODUCTIVA\r\n",upgraded)
        self.assertNotIn(b"\n",upgraded.replace(b"\r\n",b""))
        self.assertEqual(module.overlay_convocatoria_model(upgraded,old,desired),upgraded)

    def test_modelo_crear_v3_parcial_nunca_reparar_ciegamente(self):
        file="app/models/ConvocatoriaModel.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        desired=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        broken=old.replace(
            b"        $this->connection->begin_transaction();",
            b"        if (!$transaccionExterna) $this->connection->begin_transaction();",1)
        with self.assertRaisesRegex(RuntimeError,"cambio crítico V3"):
            module.overlay_convocatoria_model(broken,old,desired)
        broken2=old.replace(
            b"            // En la misma transacci",
            b"            // PERSONALIZADO \n            // En la misma transacci",1)
        upgraded=module.overlay_convocatoria_model(broken2,old,desired)
        self.assertIn(b"// PERSONALIZADO \n",upgraded)

    def test_modelo_crear_personalizado_nunca_se_sobrescribe(self):
        file="app/models/ConvocatoriaModel.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        desired=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        local=old.replace(b"public function crear($datos, $estadosIds)",
            b"public function crear($datos, $estadosIds) // personalizado",1)
        with self.assertRaisesRegex(RuntimeError,"firma de crear\\(\\) ausente o duplicada"):
            module.overlay_convocatoria_model(local,old,desired)

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
