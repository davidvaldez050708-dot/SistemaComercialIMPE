"""Prepublicación de audio CDR: simulación en sitio aislado, sin SQL."""
import contextlib
import importlib.util
import io
import pathlib
import subprocess
import sys
import tempfile
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location(
 "pub_audio",ROOT/"tools/publicar_ventas_audio_cdr_hostinger.py")
pub=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(pub)
assert len(pub.FILES)==5 and len(pub.CUSTOM)==1 and len(pub.DEPS)==2

view_rel="app/views/telefonia/ventas_llamadas.php"
fake_view=b"""<?php
  $audioTexto=$transferida?'Consultar grabaciones':'Consultar grabaci\xc3\xb3n';
?>
<?php if(TelefoniaVentasLlamadasService::audioConsultable($call)): ?>
<button data-sales-audio="<?= $esc($ref) ?>" data-extension="<?= $esc($call['extension']) ?>"></button>
<?php endif; ?>
"""
assert fake_view.count(pub.CUSTOM[view_rel]["edits"][0][0].encode("utf-8"))==1

def old_from_git(relative):
 return subprocess.check_output([
  "git","show","ad792caf6359525f1987439ea05176fbef64ec37:"+relative
 ],cwd=str(ROOT))

def execute(site,backup,*args):
 argv=["pub","--site",str(site),"--backup-root",str(backup)]+list(args)
 with patch.object(sys,"argv",argv),contextlib.redirect_stdout(io.StringIO()) as out:
  pub.main()
 return out.getvalue()

with tempfile.TemporaryDirectory(prefix="impe-ventas-audio-cdr-") as directory:
 base=pathlib.Path(directory);site=base/"site";site.mkdir()
 (site/"index.php").write_bytes(b"<?php")
 backup=base/"privado"
 for dep in pub.DEPS:
  p=site/dep;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b"<?php // personalizado protegido")
 pub.DEPS={dep:pub.digest((site/dep).read_bytes()) for dep in pub.DEPS}
 originals={}
 for rel,(oldpin,newblob) in list(pub.FILES.items()):
  p=site/rel;p.parent.mkdir(parents=True,exist_ok=True)
  if rel==view_rel:
   raw=fake_view
   pub.CUSTOM[rel]["base"]="sha256:"+pub.digest(raw)
   after,status=pub.reconcile_custom(rel,raw)
   assert status=="CONCILIADO PERSONALIZADO"
   pub.FILES[rel]=("sha256:"+pub.digest(raw),pub.blob(after))
  elif oldpin is None:
   originals[rel]=None
   assert pub.blob((ROOT/rel).read_bytes())==newblob
   continue
  else:
   raw=old_from_git(rel)
   assert pub.same_version(raw,oldpin),rel
   assert pub.blob((ROOT/rel).read_bytes())==newblob
  p.write_bytes(raw);originals[rel]=raw
 pub.fetch=lambda rel,sha:(ROOT/rel).read_bytes()
 original={p:p.read_bytes() for p in site.rglob("*") if p.is_file()}
 result=execute(site,backup,"--check")
 assert "CONCILIADO PERSONALIZADO" in result,result
 assert "PREPUBLICACIÓN COMPATIBLE: 5 archivos" in result,result
 assert not backup.exists()
 assert all(p.read_bytes()==data for p,data in original.items())
 result=execute(site,backup,"--apply")
 assert "APLICADO" in result,result
 assert (site/view_rel).read_bytes()!=fake_view
 assert pub.reconcile_custom(view_rel,(site/view_rel).read_bytes())[1]=="YA CONCILIADO PERSONALIZADO"
 assert all((site/rel).read_bytes()==(ROOT/rel).read_bytes() for rel in pub.FILES if rel!=view_rel)
 assert "YA INSTALADO" in execute(site,backup,"--apply")
 folder=next(backup.iterdir())
 modified=site/"public/javascript/telefonia_ventas_resultados.js"
 saved=modified.read_bytes()
 modified.write_bytes(saved+b"\n// extra")
 try:
  execute(site,backup,"--restore",str(folder))
  raise AssertionError("Rollback did not reject modified file")
 except RuntimeError as error:
  assert "VERSIÓN DIFERENTE" in str(error),str(error)
 modified.write_bytes(saved)
 assert "RESTAURADO" in execute(site,backup,"--restore",str(folder))
 for rel,raw in originals.items():
  p=site/rel
  assert p.read_bytes()==raw if raw is not None else not p.exists(),rel
 for dep in pub.DEPS:
  assert (site/dep).read_bytes()==original[site/dep]
 view=site/view_rel
 view.write_bytes(view.read_bytes()+b"\n<!-- personalization -->")
 try:
  execute(site,backup,"--check")
  raise AssertionError("Unknown personalized view accepted")
 except RuntimeError as error:
  assert "VARIANTE DESCONOCIDA" in str(error),str(error)
print("PASS: 5 archivos, 2 dependencias protegidas, vista Hostinger conciliada, control de hashes y rollback sin SQL.")
