"""Publicación de Inscritos en réplica Hostinger. Sin credenciales ni SQL."""
import subprocess,sys,tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
PUBLISHER=ROOT/'tools/publicar_inscritos_hostinger.py'
BASE='fb446b08a80dfbab1ec2c970cec749d2e1512a35'
PUBLISHED='21368e3a8596f7714c9c970e388c7ec2702fa85b'
def blob(file):return subprocess.check_output(['git','show',PUBLISHED+':'+file],cwd=str(ROOT))
FILES=[
 'index.php','app/models/RolModel.php','app/views/layout/sidebar.php',
 'app/controllers/InscritosController.php','app/models/InscritosModel.php',
 'app/helpers/InscritosPermisos.php','app/services/InscritosExcelService.php',
 'app/views/inscritos/index.php','public/css/inscritos.css','public/javascript/inscritos.js'
]
DEPS=[
 'app/services/FormularioInscripcionRepository.php',
 'app/services/FormularioInscripcionProgramas.php',
 'app/helpers/FormularioPermisos.php',
 'app/services/FormularioInscripcionPantalla.php'
]
with tempfile.TemporaryDirectory(prefix='impe-inscritos-release-') as tmp:
 root=Path(tmp)
 site,base,target,backup=(root/'public_html',root/'base',root/'target',root/'backups')
 site.mkdir()
 originals={}
 for file in FILES:
  live=site/file;live.parent.mkdir(parents=True,exist_ok=True)
  after=target/file;after.parent.mkdir(parents=True,exist_ok=True)
  after.write_bytes(blob(file))
  if file in FILES[:3]:
   p=subprocess.run(['git','show',BASE+':'+file],cwd=str(ROOT),
                    stdout=subprocess.PIPE,stderr=subprocess.PIPE)
   assert p.returncode==0,p.stderr.decode('utf-8','replace')
   old=p.stdout
   basefile=base/file;basefile.parent.mkdir(parents=True,exist_ok=True)
   basefile.write_bytes(old)
   live.write_bytes(old)
   originals[file]=old
 for file in DEPS:
  dest=site/file;dest.parent.mkdir(parents=True,exist_ok=True)
  dest.write_bytes(blob(file))
 def cmd(*args,success=True):
  p=subprocess.run([sys.executable,str(PUBLISHER),'--site',str(site),
      '--base-dir',str(base),'--target-dir',str(target),
      '--backup-root',str(backup)]+list(args),
      stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  output=(p.stdout+p.stderr).decode('utf-8','replace')
  assert (p.returncode==0)==success,output
  return output

 def snapshot():
  return {str(p.relative_to(site)):p.read_bytes() for p in site.rglob('*') if p.is_file()}

 initial=snapshot()
 assert 'CHECK OK' in cmd('--check')
 assert snapshot()==initial and not backup.exists(),'CHECK is read-only'
 assert 'APLICADO' in cmd('--apply')
 for file in FILES:
  assert (site/file).read_bytes()==blob(file),file
 assert 'YA INSTALADO' in cmd('--check')
 assert 'YA INSTALADO' in cmd('--apply')
 folders=list(backup.iterdir())
 assert len(folders)==1
 assert 'RESTAURADO' in cmd('--restore',str(folders[0]))
 assert snapshot()==initial

 # Conservar personalizaciones ajenas de producción.
 personalized=originals['index.php'].replace(b'<?php',b'<?php\n// ESTILO LOCAL AJENO\n',1)
 # Otro equipo agregó una ruta propia justo antes de Formularios: el parche
 # antiguo con seis líneas de contexto rechazaba esta personalización.
 additional_route=(b"    case 'institucionalExtra':\n\n"
                   b"        // RUTA DE OTRA AREA, NO TOCAR\n"
                   b"        break;\n\n\n")
 personalized=personalized.replace(b"    case 'formulario':",
                                  additional_route+b"    case 'formulario':",1)
 assert additional_route in personalized
 (site/'index.php').write_bytes(personalized)
 assert 'CHECK OK' in cmd('--check')
 assert 'APLICADO' in cmd('--apply')
 assert b'ESTILO LOCAL AJENO' in (site/'index.php').read_bytes()
 assert additional_route in (site/'index.php').read_bytes()
 assert 'YA INSTALADO' in cmd('--apply')
 another=[p for p in backup.iterdir() if p != folders[0]]
 assert len(another)==1
 assert 'RESTAURADO' in cmd('--restore',str(another[0]))
 assert (site/'index.php').read_bytes()==personalized
 for file in FILES[3:]:
  assert not (site/file).exists()

 # Si una personalización coincide con el fragmento a cambiar, no sobrescribir.
 (site/'index.php').write_bytes(originals['index.php'].replace(
  b"case 'formulario':",b"case 'formularioPersonalizado':",1))
 assert 'CONFLICTO' in cmd('--check',success=False)
 assert 'CONFLICTO' in cmd('--apply',success=False)
 assert not (site/'app/controllers/InscritosController.php').exists()

 print('PASS Inscritos: 10 archivos, conciliación, CHECK sin escritura, APPLY, RESTORE e idempotencia')
