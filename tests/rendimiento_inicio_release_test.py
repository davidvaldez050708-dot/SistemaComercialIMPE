import ast,json,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1];sys.path.insert(0,str(root/'tools'))
import publicar_rendimiento_inicio as pub
from preparar_rendimiento_inicio_release import before
spec=json.loads((root/'tools/rendimiento_inicio_release.json').read_text(encoding='utf-8'))
for row in spec['files']:
 old=before(row['path']);new=pub.candidate(old,row);assert new==(root/row['path']).read_bytes().replace(b'\r\n',b'\n'),row['path'];pub.lint(new,row['path'])
 assert pub.candidate(new,row)==new
 assert pub.candidate(old.replace(b'\n',b'\r\n'),row).replace(b'\r\n',b'\n')==new
 custom=old+b'\n/* configuracion personalizada */\n';assert pub.candidate(custom,row).endswith(b'/* configuracion personalizada */\n')
 if row['path']=='app/models/RolModel.php':
  extra=old.replace(b'        $sql = "INSERT INTO permisos (',b'        $ajusteServidor = true;\n        $sql = "INSERT INTO permisos (',1)
  extra=extra.replace(b'$permisos = $this->obtenerCatalogoInicialPermisos();',b'$permisos = array_merge($this->obtenerCatalogoInicialPermisos(), []);')
  patched=pub.candidate(extra,row);assert b'$ajusteServidor = true;' in patched and b'array_merge($this->obtenerCatalogoInicialPermisos(), [])' in patched
for name in ['publicar_rendimiento_inicio.py','preparar_rendimiento_inicio_release.py']:ast.parse((root/'tools'/name).read_text(encoding='utf-8'),feature_version=(3,6))
with tempfile.TemporaryDirectory(prefix='impe-perf-release-') as folder:
 folder=Path(folder);site=folder/'site';backups=folder/'private';site.mkdir();backups.mkdir();(site/'index.php').write_text('<?php')
 for row in spec['files']:
  target=site/row['path'];target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(before(row['path']))
 command=[sys.executable,str(root/'tools/publicar_rendimiento_inicio.py'),'--site',str(site),'--backup-root',str(backups)]
 def run(args,success=True):
  r=subprocess.run(command+args,stdout=subprocess.PIPE,stderr=subprocess.PIPE);assert (r.returncode==0)==success,(r.stdout+r.stderr).decode(errors='replace')
 run(['--check']);assert not list(backups.iterdir());run(['--apply']);run(['--apply'])
 records=list(backups.glob('rendimiento-inicio-*/manifest.json'));assert len(records)==1;run(['--restore',str(records[0].parent)])
 for row in spec['files']:assert (site/row['path']).read_bytes()==before(row['path'])
 (site/'app/controllers/TelefoniaController.php').write_text('<?php /* version incompatible */');run(['--apply'],False)
 assert (site/'app/models/RolModel.php').read_bytes()==before('app/models/RolModel.php')
print('PASS publicador: sintaxis, personalizaciones del catálogo, LF/CRLF, Python 3.6, comprobación sin escritura, aplicación, repetición, restauración y bloqueo ante conflicto sin cambios parciales.')
