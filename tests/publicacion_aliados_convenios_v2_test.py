"""Pruebas aisladas del publicador V2 sin internet ni acceso a producción."""
import base64
import gzip
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("publicador_aliados_v2",str(ROOT/"tools"/"publicar_aliados_convenios_v2.py"))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class PublicacionV2(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name);self.site=self.root/"public_html";self.site.mkdir()
        (self.site/"index.php").write_text("<?php echo 1;")
        for nombre_css in ("aliados.css", "convocatorias.css", "crear_convocatoria.css"):
            css=self.site/"public"/"css"/nombre_css
            css.parent.mkdir(parents=True,exist_ok=True)
            css.write_text("/* Estilos personalizados de Hostinger, conservar */\n")
        self.old=b".t { color: blue; }\n"+(b"/* bloque conservado */\n"*12)+b"/* descripcion original */\n"
        self.new=self.old.replace(b"color: blue",b"color: navy")
        self.name="public/css/aliados-prueba.css"
        self.file=self.site/self.name;self.file.parent.mkdir(parents=True)
        self.file.write_bytes(self.old)
        pack=lambda t:base64.b64encode(gzip.compress(t)).decode("ascii")
        self.release=json.dumps({"source":mod.OLD,"files":[{"path":self.name,"target":pack(self.old)}]}).encode("utf-8")
    def fake_get(self,rev,path,missing=False):
        if path=="tools/aliados_registro_release.json":return self.release
        if rev==mod.SOURCE and path==self.name:return self.new
        if rev==mod.OLD and path==self.name:return self.old
        raise RuntimeError("Descarga inesperada: "+str((rev,path)))
    def patch_source(self):
        return patch.multiple(mod,get=self.fake_get,SHAS={self.name:mod.blob(self.new)})
    def test_check_sin_escritura_y_bloqueo_por_sha(self):
        with self.patch_source(),tempfile.TemporaryDirectory() as t:
            plan=mod.prepare(self.site,Path(t))
            self.assertEqual(len(plan),1)
            self.assertEqual(plan[0][3],self.new)
            self.assertEqual(self.file.read_bytes(),self.old)
        with patch.multiple(mod,get=self.fake_get,SHAS={self.name:"0"*40}),tempfile.TemporaryDirectory() as t:
            with self.assertRaisesRegex(RuntimeError,"SHA inesperado"):
                mod.prepare(self.site,Path(t))
    def test_personalizacion_no_superpuesta(self):
        mine=self.old.replace(b"descripcion original",b"personalizacion intacta")
        self.file.write_bytes(mine.replace(b"\n",b"\r\n"))
        with self.patch_source(),tempfile.TemporaryDirectory() as t:
            result=mod.prepare(self.site,Path(t))[0][3]
        self.assertIn(b"color: navy",result)
        self.assertIn(b"personalizacion intacta",result)
        self.assertIn(b"\r\n",result)
    def test_conflicto_antes_de_escritura(self):
        self.file.write_bytes(self.old.replace(b"color: blue",b"color: red"))
        with self.patch_source(),tempfile.TemporaryDirectory() as t:
            with self.assertRaisesRegex(RuntimeError,"CONFLICTO"):
                mod.prepare(self.site,Path(t))
        self.assertIn(b"red",self.file.read_bytes())
    def test_restore_exige_integridad(self):
        backup=self.root/"respaldo";backup.mkdir()
        old=(backup/"original"/self.name);old.parent.mkdir(parents=True);old.write_bytes(self.old)
        manifest={"site":str(self.site),"source":mod.SOURCE,
            "files":[{"path":self.name,"old":mod.sha(self.old),"installed":mod.sha(self.new),"mode":0o644}]}
        (backup/"respaldo.json").write_text(json.dumps(manifest))
        self.file.write_bytes(self.new)
        self.file.write_bytes(self.new+b"\nEdicion posterior")
        with self.assertRaisesRegex(RuntimeError,"modificado"):
            mod.restore(self.site,backup,self.root/"stamp.json")
        self.file.write_bytes(self.new)
        mod.restore(self.site,backup,self.root/"stamp.json")
        self.assertEqual(self.file.read_bytes(),self.old)
    def test_check_sin_node_acepta_fuente_exacta_y_avisa_fusion(self):
        with tempfile.TemporaryDirectory() as tmp:
            stage=Path(tmp)
            with patch.object(mod.shutil,"which",side_effect=lambda name: None if name in ("node","nodejs") else "/usr/bin/"+name):
                # Los JS idénticos a la revisión SHA inmóvil no requieren Node en Hostinger.
                mod.validate_php_js("public/javascript/test.js",b"const a = 1;",stage,b"const a = 1;",True)
                # --check permite revisar el diff del JS fusionado, sin instalar.
                mod.validate_php_js("public/javascript/test.js",b"const a = 2;",stage,b"const a = 1;",False)
                # --apply se bloquea si la fusión JS no fue validada externamente.
                with self.assertRaisesRegex(RuntimeError,"VALIDACION JS PENDIENTE"):
                    mod.validate_php_js("public/javascript/test.js",b"const a = 2;",stage,b"const a = 1;",True)

    def test_parche_quirurgico_convocatorias_preserva_personalizaciones(self):
        path="app/views/convocatorias/index.php"
        old=subprocess.check_output(["git","show",mod.OLD+":"+path],cwd=str(ROOT))
        new=subprocess.check_output(["git","show",mod.SOURCE+":"+path],cwd=str(ROOT))
        self.assertEqual(mod.overlay_convocatorias(old,old,new),new)
        # Personalización fuera del modal, preservada sin reescribir el archivo.
        local=old.replace(b"<?php\n",b"<?php\n/* Marca personalizada de produccion */\n",1)
        merged=mod.overlay_convocatorias(local,old,new)
        self.assertIn(b"Marca personalizada de produccion",merged)
        self.assertIn(b"data-aliado-registro-contexto",merged)
        self.assertEqual(merged.replace(b"/* Marca personalizada de produccion */\n",b""),new)

    def test_parche_quirurgico_rechaza_region_modificada(self):
        path="app/views/convocatorias/index.php"
        old=subprocess.check_output(["git","show",mod.OLD+":"+path],cwd=str(ROOT))
        new=subprocess.check_output(["git","show",mod.SOURCE+":"+path],cwd=str(ROOT))
        esperado=b"                                Tipo de convocatoria\n"
        self.assertIn(esperado,old)
        local=old.replace(esperado,b"                                Categoria personalizada\n",1)
        with self.assertRaisesRegex(RuntimeError,"CONFLICTO"):
            mod.overlay_convocatorias(local,old,new)

    def test_css_v2_extiende_sin_sobrescribir_tres_bases(self):
        css_extra=(ROOT/"public/css/aliados_v2_extension.css").read_bytes()
        self.assertIn(b"Complemento visual Aliados V2",css_extra)
        for name in ("aliados.css","convocatorias.css","crear_convocatoria.css"):
            path="public/css/"+name
            base=subprocess.check_output(["git","show",mod.OLD+":"+path],cwd=str(ROOT))
            desired=subprocess.check_output(["git","show",mod.SOURCE+":"+path],cwd=str(ROOT))
            self.assertTrue(desired.startswith(base),path)
            self.assertIn(desired[len(base):].strip(),css_extra,path)
            self.assertNotIn(path,mod.SHAS,"No sobrescribir CSS existente")
        self.assertIn("public/css/aliados_v2_extension.css",mod.SHAS)

    def test_cabecera_personalizada_se_conserva_y_carga_css_js(self):
        path="app/views/layout/dashboard_head.php"
        old=subprocess.check_output(["git","show",mod.OLD+":"+path],cwd=str(ROOT))
        new=subprocess.check_output(["git","show",mod.SOURCE+":"+path],cwd=str(ROOT))
        # Simular personalización existente de otros módulos del CRM.
        inserted=b"// Personalizacion de Telefonia que se debe conservar\n"
        customized=old.replace(b"$cssOpcionalDashboard = array_values",inserted+b"$cssOpcionalDashboard = array_values",1)
        merged=mod.overlay_dashboard_head(customized,old,new)
        self.assertIn(inserted,merged)
        self.assertIn(b"aliados_v2_extension.css",merged)
        self.assertIn(b"convocatorias_aliado_programas.js",merged)
        self.assertIn(b"crear_convocatoria_v2_extension.js",merged)
        self.assertIn(b"aliado_programas_modular.php",merged)
        self.assertEqual(mod.overlay_dashboard_head(merged,old,new),merged)
        # Reconstruir exactamente la personalización anterior al eliminar
        # únicamente los dos bloques marcados V2, sin afectar otros módulos.
        import re
        for part in (b"CSS",b"JS"):
            merged=re.sub(
                b"// IMPE_ALIADOS_V2_"+part+b"_INICIO\\n.*?// IMPE_ALIADOS_V2_"+part+b"_FIN\\n\\n",
                b"",merged,flags=re.DOTALL)
        self.assertEqual(merged,customized)

    def test_cabecera_sin_anclas_unicas_se_rechaza(self):
        path="app/views/layout/dashboard_head.php"
        old=subprocess.check_output(["git","show",mod.OLD+":"+path],cwd=str(ROOT))
        new=subprocess.check_output(["git","show",mod.SOURCE+":"+path],cwd=str(ROOT))
        broken=old.replace(b"$jsOpcionalHead = array_values(array_unique($jsOpcionalHead));",
                           b"$jsOpcionalHead = custom_helper($jsOpcionalHead);")
        with self.assertRaisesRegex(RuntimeError,"punto de inserción único"):
            mod.overlay_dashboard_head(broken,old,new)

    def test_js_convenios_personalizado_se_preserva(self):
        self.assertNotIn("public/javascript/crear_convocatoria.js",mod.SHAS)
        self.assertIn("public/javascript/crear_convocatoria_v2_extension.js",mod.SHAS)
        extension=(ROOT/"public/javascript/crear_convocatoria_v2_extension.js").read_text(encoding="utf-8")
        for required in ("impe:convenio-abierto", "data-convenio-municipio-header",
                         "data-convenio-territorio-card", "aliado_tipo",
                         "aliado_id", "data-convenio-notification"):
            self.assertIn(required,extension)

    def test_fuente_fijada_reproduce_shas_git(self):
        actual=mod.SHAS
        for path,expected in actual.items():
            data=subprocess.check_output(["git","show",mod.SOURCE+":"+path],cwd=str(ROOT))
            self.assertEqual(mod.blob(data),expected,path)
        release=json.loads(subprocess.check_output(["git","show",mod.SOURCE+":tools/aliados_registro_release.json"],cwd=str(ROOT)))
        self.assertEqual(release["source"],mod.OLD)

if __name__=="__main__":unittest.main()
