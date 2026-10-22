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
        self.old=b".t { color: blue; }\n"+(b"/* bloque conservado */\n"*12)+b"/* descripcion original */\n"
        self.new=b".t { color: navy; }\n/* descripcion original */\n"
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
        self.file.write_bytes(self.new+b"\nEdición posterior")
        with self.assertRaisesRegex(RuntimeError,"modificado"):
            mod.restore(self.site,backup,self.root/"stamp.json")
        self.file.write_bytes(self.new)
        mod.restore(self.site,backup,self.root/"stamp.json")
        self.assertEqual(self.file.read_bytes(),self.old)
    def test_fuente_fijada_reproduce_shas_git(self):
        actual=mod.SHAS
        for path,expected in actual.items():
            data=subprocess.check_output(["git","show",mod.SOURCE+":"+path],cwd=str(ROOT))
            self.assertEqual(mod.blob(data),expected,path)
        release=json.loads(subprocess.check_output(["git","show",mod.SOURCE+":tools/aliados_registro_release.json"],cwd=str(ROOT)))
        self.assertEqual(release["source"],mod.OLD)

if __name__=="__main__":unittest.main()
