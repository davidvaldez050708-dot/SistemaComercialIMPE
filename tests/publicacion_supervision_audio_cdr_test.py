"""Prueba completa del publicador selectivo sobre blobs reales de Hostinger."""
import contextlib
import importlib.util
import io
import pathlib
import sys
import tempfile
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location(
 "pub_supervision",ROOT/"tools/publicar_supervision_audio_cdr_hostinger.py")
pub=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)
assert len(pub.FILES)==3 and len(pub.DEPS)==4 and len(pub.CUSTOM)==2

def invoke(site,backups,*args):
 with contextlib.redirect_stdout(io.StringIO()) as output,patch.object(sys,'argv',[
  "publisher","--site",str(site),"--backup-root",str(backups)]+list(args)):
  pub.main()
 return output.getvalue()

with tempfile.TemporaryDirectory(prefix="impe-supervision-audio-cdr-") as folder:
 root=pathlib.Path(folder)
 site=root/"public_html"
 site.mkdir()
 (site/"index.php").write_text("<?php")
 backup=root/"backups"
 originals={}
 for rel,(before,afterblob) in pub.FILES.items():
  location=site/rel
  location.parent.mkdir(parents=True,exist_ok=True)
  final=(ROOT/rel).read_bytes()
  assert pub.blob(final)==afterblob,("integridad Git",rel)
  if before is None:
   originals[rel]=None
   continue
  pre=final
  for old,new in reversed(pub.CUSTOM[rel]['edits']):
   a,b=old.encode("utf-8"),new.encode("utf-8")
   assert pre.count(b)==1,("reversión exacta",rel)
   pre=pre.replace(b,a,1)
  assert pub.same_version(pre,before),("blob Hostinger original",rel)
  originals[rel]=pre
  location.write_bytes(pre)
 for rel in pub.DEPS:
  path=site/rel
  path.parent.mkdir(parents=True,exist_ok=True)
  path.write_bytes(b"<?php /* Dependencia Hostinger intacta */")
 pub.DEPS={rel:"blob:"+pub.blob((site/rel).read_bytes()) for rel in pub.DEPS}
 pub.fetch=lambda rel,sha:(ROOT/rel).read_bytes()
 snap={p:p.read_bytes() for p in site.rglob("*") if p.is_file()}
 output=invoke(site,backup,"--check")
 assert output.count("CONCILIADO PERSONALIZADO:")==2,output
 assert "NUEVO: app/services/SupervisionVentasAudioCdrPolicy.php" in output,output
 assert "PREPUBLICACIÓN COMPATIBLE: 3 archivos" in output
 assert "SITIO INTACTO" in output
 assert not backup.exists()
 assert all(p.read_bytes()==raw for p,raw in snap.items())
 output=invoke(site,backup,"--apply")
 assert "APLICADO:" in output
 for rel in pub.FILES:
  assert (site/rel).read_bytes()==(ROOT/rel).read_bytes(),rel
 assert "YA INSTALADO" in invoke(site,backup,"--check")
 assert "YA INSTALADO" in invoke(site,backup,"--apply")
 assert len(list(backup.iterdir()))==1
 folder=next(backup.iterdir())
 modify=site/"app/services/SupervisionLlamadasRepository.php"
 good=modify.read_bytes()
 modify.write_bytes(good+b"\n// otro despliegue")
 try:
  invoke(site,backup,"--restore",str(folder))
  raise AssertionError("Restauró sobre un cambio ajeno")
 except RuntimeError as error:
  assert "VERSIÓN DIFERENTE" in str(error),str(error)
 modify.write_bytes(good)
 assert "RESTAURADO" in invoke(site,backup,"--restore",str(folder))
 for rel,raw in originals.items():
  file=site/rel
  assert (file.read_bytes()==raw if raw is not None else not file.exists()),rel
 for p,raw in snap.items():
  assert p.read_bytes()==raw
 # No forzar un servicio de Supervisor con personalizaciones extra.
 target=site/"app/services/SupervisionLlamadasAudioService.php"
 target.write_bytes(target.read_bytes()+b"\n// version no reconocida")
 try:
  invoke(site,backup,"--check")
  raise AssertionError("Publicador aceptó servicio distinto")
 except RuntimeError as error:
  assert "VARIANTE DESCONOCIDA" in str(error),str(error)
print("PASS: 3 archivos, 4 dependencias, dos parches Hostinger exactos, sin SQL, no escritura en check, restauración y conflictos.")
