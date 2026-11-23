import ast,json,os,subprocess,sys,tempfile,time
from pathlib import Path
root=Path(__file__).resolve().parents[1];sys.path.insert(0,str(root/'tools'))
import publicar_perfil_peticiones as pub
spec=json.loads((root/'tools/perfil_peticiones_release.json').read_text(encoding='utf-8'))
with tempfile.TemporaryDirectory(prefix='impe-request-profile-') as folder:
 folder=Path(folder);site=folder/'public_html';private=folder/'impe-private';site.mkdir();private.mkdir();(site/'app/helpers').mkdir(parents=True)
 helper=site/'app/helpers/PerfilPeticion.php';helper.write_bytes((root/'app/helpers/PerfilPeticion.php').read_bytes())
 flag=private/'perfil-peticiones-activo.json';log=private/'perfil-peticiones.jsonl'
 fixture=folder/'fixture.php'
 fixture.write_text('''<?php
$_GET=['controller'=>'territorio','action'=>'catalogoEstados','id'=>'SENSITIVE-ID'];
$_POST=['password'=>'SENSITIVE-PASSWORD'];$_COOKIE=['PHPSESSID'=>'SENSITIVE-COOKIE'];
require $argv[1].'/app/helpers/PerfilPeticion.php';$p=ImpePerfilPeticion::iniciar($argv[1]);
if(!$p){echo 'INACTIVO';exit;}
usleep(90000);$p->marca('sesion');usleep(20000);$p->marca('config');$p->marca('autorizacion');$p->marca('controlador');$p->marca('despacho');usleep(70000);
echo 'RESPUESTA_NORMAL';
''',encoding='utf-8')
 def run(args):
  p=subprocess.run(['php']+args,stdout=subprocess.PIPE,stderr=subprocess.PIPE);assert p.returncode==0 and not p.stderr,(p.stdout+p.stderr).decode(errors='replace');return p.stdout.decode(errors='replace')
 assert run([str(fixture),str(site)])=='INACTIVO';assert not log.exists()
 flag.write_text(json.dumps(dict(version=1,hasta=int(time.time())+3600)))
 assert run([str(fixture),str(site)])=='RESPUESTA_NORMAL'
 row=json.loads(log.read_text().splitlines()[-1]);assert row['ruta']=='territorio.catalogoEstados' and row['fases_ms']['sesion']>=70 and row['fases_ms']['accion']>=50 and row['total_ms']>=160
 assert 'SENSITIVE' not in log.read_text();assert 'SQL=' in run([str(helper)])
 # Atribuir una espera real de sesión, sin confundirla con ejecución del módulo.
 sessions=folder/'sessions';sessions.mkdir();ready=folder/'locked'
 owner=folder/'owner.php';owner.write_text("<?php session_save_path($argv[1]);session_id('perfil-fixture-safe');session_start();file_put_contents($argv[2],'1');usleep(1100000);session_write_close();")
 waiting=folder/'waiting.php';waiting.write_text("<?php $_GET=['controller'=>'convocatoria','action'=>'index'];require $argv[1].'/app/helpers/PerfilPeticion.php';$p=ImpePerfilPeticion::iniciar($argv[1]);session_save_path($argv[2]);session_id('perfil-fixture-safe');session_start();$p?->marca('sesion');session_write_close();$p?->marca('config');$p?->marca('autorizacion');$p?->marca('controlador');$p?->marca('despacho');echo 'OK';")
 process=subprocess.Popen(['php',str(owner),str(sessions),str(ready)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 until=time.monotonic()+5
 while not ready.exists() and time.monotonic()<until:time.sleep(.01)
 assert ready.exists();assert run([str(waiting),str(site),str(sessions)])=='OK';out,err=process.communicate(timeout=5);assert process.returncode==0 and not err
 measured=json.loads(log.read_text().splitlines()[-1]);assert measured['fases_ms']['sesion']>700 and measured['fases_ms']['accion']<50
 assert 'perfil-fixture-safe' not in log.read_text()
 flag.write_text(json.dumps(dict(version=1,hasta=int(time.time())-1)));assert run([str(fixture),str(site)])=='INACTIVO'
 flag.write_text('"corrupto"');assert run([str(fixture),str(site)])=='INACTIVO'
 # El límite de archivo evita crecimiento ilimitado.
 flag.write_text(json.dumps(dict(version=1,hasta=int(time.time())+3600)));log.write_bytes(b'X'*1049000);run([str(fixture),str(site)]);assert log.stat().st_size<2000
 print('PASS medición: fases, respuesta intacta, privacidad, informe CLI, expiración, bandera corrupta y límite de registro.')
 for row in spec['files']:pub.lint(pub.candidate(None if row['new_file'] else subprocess.check_output(['git','show','c60d99a:index.php'],cwd=str(root)),row),row['path'])
 # Publicación real en copia desechable, sin cambios de módulos ni credenciales.
 helper.unlink();original=subprocess.check_output(['git','show','c60d99a:index.php'],cwd=str(root));(site/'index.php').write_bytes(original)
 command=[sys.executable,str(root/'tools/publicar_perfil_peticiones.py'),'--site',str(site),'--backup-root',str(private)]
 def publish(mode):
  p=subprocess.run(command+mode,stdout=subprocess.PIPE,stderr=subprocess.PIPE);assert p.returncode==0,(p.stdout+p.stderr).decode(errors='replace')
 flag.unlink();publish(['--check']);assert not helper.exists() and not flag.exists();publish(['--apply']);assert helper.exists() and flag.exists();publish(['--apply'])
 records=list(private.glob('perfil-peticiones-*/manifest.json'));assert len(records)==1
 publish(['--restore',str(records[0].parent)]);assert not flag.exists() and not helper.exists() and (site/'index.php').read_bytes()==original
 (site/'index.php').write_text('<?php /* índice distinto */');failed=subprocess.run(command+['--apply'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 assert failed.returncode!=0 and not helper.exists() and not flag.exists()
 # El índice más reciente conserva la autorización central y sus personalizaciones.
 source=(root/'../impe-mysql-auditoria/index.php').read_bytes()
 modified=pub.candidate(source,spec['files'][1]);assert b'Database::compartida()' in modified and pub.candidate(modified,spec['files'][1])==modified
 assert pub.candidate(source.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n'),spec['files'][1]).replace(b'\r\n',b'\n')==modified.replace(b'\r\n',b'\n')
 for name in ['publicar_perfil_peticiones.py','preparar_perfil_peticiones_release.py']:ast.parse((root/'tools'/name).read_text(encoding='utf-8'),feature_version=(3,6))
 print('PASS instalación: sintaxis, comprobación, dos variantes de índice, LF/CRLF, Python 3.6, activación, repetición y restauración.')
