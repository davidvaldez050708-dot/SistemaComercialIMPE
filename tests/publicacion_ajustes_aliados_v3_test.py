#!/usr/bin/env python3
import hashlib
import ast
import contextlib
import io
import importlib.util
import json
import subprocess
import tempfile
import unittest
from unittest import mock
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("publicador_v3",ROOT/"tools/publicar_ajustes_aliados_v3.py")
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
PR114='20ec4fb64f211b06cde114da76d6c84e65a96db5'

def historical(ref,path):
    return subprocess.check_output(['git','show',ref+':'+path],cwd=str(ROOT))

class PublicadorAjustesV3Test(unittest.TestCase):
    def test_pr114_real_llave_dispatch_idempotencia_sintaxis_y_personalizaciones(self):
        name='app/models/ConvocatoriaModel.php'
        old,desired,real=[historical(r,name) for r in (module.BASE,module.SOURCE,PR114)]
        for crlf in (False,True):
            with self.subTest(crlf=crlf),tempfile.TemporaryDirectory() as folder:
                live=module.lf(real).replace(b'    public function buscarPorId(',b'    // HOSTINGER: metodo propio conservado\n    public function buscarPorId(',1)
                live=live.replace(b'            $convocatoriaId = (int)',b'            // HOSTINGER: validacion adicional conservada\n            $convocatoriaId = (int)',1)
                if crlf:live=live.replace(b'\n',b'\r\n')
                result=module.overlay_convocatoria_model(live,old,desired)
                normal=module.lf(result)
                self.assertIn(b'public function crear($datos, $estadosIds, $relacionar = null)\n    {\n        // Compatibilidad:',normal)
                for symbol in (b'public function crear(',b'public function iniciarLote(',b'public function confirmarLote(',b'public function cancelarLote(',b'if ($relacionar) { $relacionar($this->connection, $convocatoriaId); }'):
                    self.assertEqual(normal.count(symbol),1,symbol)
                self.assertEqual(result.count(b'HOSTINGER:'),2)
                module.validate(name,result,Path(folder),desired)
                self.assertEqual(module.overlay_convocatoria_model(result,old,desired),result)
                # La forma que producía el publicador anterior reproduce el Parse error.
                malformed=normal.replace(b'$relacionar = null)\n    {\n        // Compatibilidad:',b'$relacionar = null)\n        // Compatibilidad:',1)
                with self.assertRaisesRegex(RuntimeError,'ERROR de sintaxis'):
                    module.validate(name,malformed,Path(folder),desired)
                with self.assertRaises(RuntimeError):module.overlay_convocatoria_model(malformed,old,desired)

    def test_pr114_real_con_vinculo_manual_v2_no_duplica_asociaciones(self):
        name='app/models/ConvocatoriaModel.php'
        old,desired,real=[module.lf(historical(r,name)) for r in (module.BASE,module.SOURCE,PR114)]
        start=old.index('            // En la misma transacción:'.encode('utf-8'))
        end=old.index(b'            $this->connection->commit();',start)
        anchor=b'            if ($relacionar) { $relacionar($this->connection, $convocatoriaId); }\n'
        live=real.replace(anchor,old[start:end]+anchor,1)
        with tempfile.TemporaryDirectory() as folder:
            result=module.overlay_convocatoria_model(live,old,desired)
            self.assertEqual(result.count(b'marketing_aliado_manual_convocatorias'),1)
            self.assertEqual(result.count(b'marketing_aliado_convocatorias_origen'),1)
            module.validate(name,result,Path(folder),desired)
            self.assertEqual(module.overlay_convocatoria_model(result,old,desired),result)

    def test_diagnostico_sintaxis_conserva_ambas_salidas_codigo_y_linea(self):
        with tempfile.TemporaryDirectory() as folder:
            response=subprocess.CompletedProcess([],255,b'Errors parsing validar.php\n',b'PHP Parse error: unexpected variable on line 403\n')
            with mock.patch.object(module.subprocess,'run',return_value=response):
                with self.assertRaises(RuntimeError) as captured:
                    module.validate('app/models/ConvocatoriaModel.php',b'<?php invalid',Path(folder),None)
            for part in ('código de salida: 255','stdout:','Errors parsing','stderr:','line 403','app/models/ConvocatoriaModel.php'):
                self.assertIn(part,str(captured.exception))

    def test_python36(self):
        ast.parse((ROOT/'tools/publicar_ajustes_aliados_v3.py').read_text(encoding='utf-8'),feature_version=(3,6))

    def test_check_apply_restore_representativo_pr114_y_bloqueo_total(self):
        blobs={}
        def fetch(ref,name):
            key=(ref,name)
            if key not in blobs:blobs[key]=historical(ref,name)
            return blobs[key]
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);site=root/'public_html';site.mkdir();private=root/'respaldos'
            (site/'index.php').write_bytes(b'<?php // fixture aislada\n')
            for name in module.SHA:
                if name in module.NEW:continue
                file=site/name;file.parent.mkdir(parents=True,exist_ok=True)
                ref=PR114 if name in ('app/models/ConvocatoriaModel.php','app/controllers/ConvocatoriaController.php') else module.BASE
                file.write_bytes(fetch(ref,name))
            partial=site/module.MODULE;partial.parent.mkdir(parents=True,exist_ok=True)
            partial.write_bytes(b'<script src="convocatorias_aliado_modular.js?v=1"></script>\n')
            protected=['app/views/convocatorias/crear_modal.php','public/javascript/convocatoria_crear_compartido.js']
            for name in protected:
                file=site/name;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(b'PERSONALIZADO: CONSERVAR\n')
            snapshot=lambda:{p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
            before=snapshot()
            def run(mode,*extra):
                argv=['publicador','--site',str(site),'--backup-root',str(private),mode]+list(extra)
                with mock.patch.object(module,'fetch',side_effect=fetch),mock.patch.object(module,'MODULE_OLD',module.digest(before[module.MODULE])),mock.patch.object(module.sys,'argv',argv),contextlib.redirect_stdout(io.StringIO()) as output:
                    module.main()
                return output.getvalue()
            output=run('--check');self.assertEqual(before,snapshot());self.assertIn('CONCILIACIÓN VALIDADA: app/models/ConvocatoriaModel.php',output)
            run('--apply');installed=snapshot();run('--apply');self.assertEqual(installed,snapshot())
            self.assertEqual(len(list(private.glob('aliados-v3-*/respaldo.json'))),1)
            for name in protected:self.assertEqual(installed[name],before[name])
            backup=next(private.glob('aliados-v3-*/respaldo.json')).parent
            saved=backup/'originales/app/controllers/ConvocatoriaController.php'
            saved_bytes=saved.read_bytes();saved.write_bytes(b'RESPALDO ALTERADO')
            with self.assertRaisesRegex(RuntimeError,'Respaldo alterado'):run('--restore',str(backup))
            self.assertEqual(installed,snapshot())
            saved.write_bytes(saved_bytes)
            run('--restore',str(backup));self.assertEqual(before,snapshot())
            atomic=module.atomic;writes=[]
            def fail_second(path,data,mode):
                writes.append(path)
                if len(writes)==2:raise OSError('fallo simulado de escritura')
                return atomic(path,data,mode)
            with mock.patch.object(module,'atomic',side_effect=fail_second):
                with self.assertRaisesRegex(OSError,'fallo simulado'):run('--apply')
            self.assertEqual(before,snapshot())
            path=site/'app/models/ConvocatoriaModel.php'
            path.write_bytes(path.read_bytes().replace(b'?callable $relacionar',b'array $relacionar',1))
            altered=snapshot()
            with self.assertRaisesRegex(RuntimeError,'SITIO INTACTO'):run('--apply')
            self.assertEqual(altered,snapshot())
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
        at=old.index(b'    public function crear(')
        broken=old[:at]+old[at:].replace(
            b"        $this->connection->begin_transaction();",
            b"        if (!$transaccionExterna) $this->connection->begin_transaction();",1)
        with self.assertRaisesRegex(RuntimeError,"cambio crítico V3"):
            module.overlay_convocatoria_model(broken,old,desired)
        broken2=old.replace(
            b"            // En la misma transacci",
            b"            // PERSONALIZADO \n            // En la misma transacci",1)
        upgraded=module.overlay_convocatoria_model(broken2,old,desired)
        self.assertIn(b"// PERSONALIZADO \n",upgraded)

    @staticmethod
    def modelo_callback_pr114(con_vinculo_manual=False, crlf=False):
        file="app/models/ConvocatoriaModel.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        desired=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        header=b"    public function crear($datos, $estadosIds)\n"
        signature=b"    public function crear($datos, $estadosIds, ?callable $relacionar = null)\n"
        callback=b"            if ($relacionar) { $relacionar($this->connection, $convocatoriaId); }\n\n"
        comment="            // En la misma transacción: imagen/clasificación y origen manual indivisibles.\n".encode("utf-8")
        start=old.index(header)
        end=old.index(b"    public function actualizar(",start)
        section=old[start:end]
        section=section.replace(header,signature,1)
        if con_vinculo_manual:
            section=section.replace(comment,callback+comment,1)
        else:
            from_pos=section.index(comment)
            to_pos=section.index(b"            $this->connection->commit();",from_pos)
            section=section[:from_pos]+callback+section[to_pos:]
        live=old[:start]+section+old[end:]
        if crlf:
            live=live.replace(b"\n",b"\r\n")
        return old,desired,live

    def test_modelo_internacional_pr114_preserva_callback_y_lote(self):
        old,desired,live=self.modelo_callback_pr114()
        result=module.overlay_convocatoria_model(live,old,desired)
        self.assertIn(b"if (is_bool($relacionar)) $relacionar = null;",result)
        self.assertEqual(result.count(b"public function crear("),1)
        self.assertEqual(result.count(b"public function iniciarLote("),1)
        self.assertEqual(result.count(b"marketing_aliado_convocatorias_origen"),1)
        self.assertEqual(result.count(b"marketing_aliado_manual_convocatorias"),1)
        self.assertEqual(result.count(b"if ($relacionar) { $relacionar($this->connection, $convocatoriaId); }"),1)
        self.assertEqual(module.overlay_convocatoria_model(result,old,desired),result)
        with tempfile.TemporaryDirectory() as dirname:
            file_path=Path(dirname)/"modelo.php"
            file_path.write_bytes(result)
            sintaxis=subprocess.run(["php","-l",str(file_path)],
                stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            self.assertEqual(sintaxis.returncode,0,sintaxis.stderr.decode("utf-8","replace"))

    def test_modelo_internacional_pr114_y_v2_manual_se_conservan(self):
        old,desired,live=self.modelo_callback_pr114(con_vinculo_manual=True,crlf=True)
        marker=b"            $convocatoriaId = (int)"
        at=live.index(b'    public function crear(')
        self.assertEqual(live[at:live.index(b'    public function actualizar(',at)].count(marker),1)
        live=live[:at]+live[at:].replace(marker,b"            // PERSONALIZACION HOSTINGER\r\n"+marker,1)
        result=module.overlay_convocatoria_model(live,old,desired)
        self.assertEqual(result.count(b"PERSONALIZACION HOSTINGER"),1)
        self.assertEqual(result.count(b"marketing_aliado_manual_convocatorias"),1)
        self.assertEqual(result.count(b"marketing_aliado_convocatorias_origen"),1)
        self.assertEqual(result.count(b"public function crear("),1)
        self.assertNotIn(b"\n",result.replace(b"\r\n",b""))
        self.assertEqual(module.overlay_convocatoria_model(result,old,desired),result)

    def test_modelo_internacional_pr114_sin_callback_bloquea(self):
        old,desired,live=self.modelo_callback_pr114()
        marker=b"            if ($relacionar) { $relacionar($this->connection, $convocatoriaId); }\n"
        broken=live.replace(marker,b"            // CALLBACK ALTERADO\n",1)
        with self.assertRaisesRegex(RuntimeError,"callback internacional distinto"):
            module.overlay_convocatoria_model(broken,old,desired)

    def test_modelo_crear_personalizado_nunca_se_sobrescribe(self):
        file="app/models/ConvocatoriaModel.php"
        old=subprocess.check_output(["git","show",module.BASE+":"+file],cwd=str(ROOT))
        desired=subprocess.check_output(["git","show",module.SOURCE+":"+file],cwd=str(ROOT))
        local=old.replace(b"public function crear($datos, $estadosIds)",
            b"public function crear($datos, $estadosIds) // personalizado",1)
        with self.assertRaisesRegex(RuntimeError,"firma de crear\\(\\) no reconocida"):
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
