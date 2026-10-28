"""MariaDB efímero, sólo datos sintéticos. No utiliza la BD/configuración del CRM."""
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('IMPE_TEST_PHP','C:/xampp/php/php.exe')
MYSQL=Path(os.environ.get('IMPE_TEST_MYSQL','C:/xampp/mysql'))
FLAGS=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
(ROOT/'tmp').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='agenda-mariadb-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);area.resolve().relative_to((ROOT/'tmp').resolve())
    data=area/'data';site=area/'site';site.mkdir()
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    env=dict(os.environ,AGENDA_TEST_PORT=str(port))
    subprocess.run([str(MYSQL/'bin/mysql_install_db.exe'),'--datadir='+str(data),'--silent'],check=True,capture_output=True,creationflags=FLAGS)
    for rel in ['app/services/AgendaComentarioRepository.php','app/services/AgendaComentarioService.php',
                'app/helpers/PermissionHelper.php','app/helpers/AdminRolePolicy.php',
                'database/migrations/2026_10_28_agenda_comentarios.sql','tools/configurar_agenda_comentarios.php']:
        p=site/rel;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/rel,p)
    (site/'config').mkdir()
    (site/'config/db_connection.php').write_text('''<?php
date_default_timezone_set('America/Mexico_City');mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
class Database {public function connect($persistente=false){
 $d=new mysqli('127.0.0.1','root','','agenda_test',(int)getenv('AGENDA_TEST_PORT'));
 $d->set_charset('utf8mb4');$d->query("SET time_zone='+00:00'");return $d;}}
''',encoding='utf8')
    (site/'test.php').write_text('''<?php
require __DIR__.'/app/services/AgendaComentarioService.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=(new Database())->connect();
$db->query("CREATE TABLE usuarios(id INT PRIMARY KEY,nombre VARCHAR(100),apellidos VARCHAR(100))");
$db->query("INSERT INTO usuarios VALUES(5,'Analista','Prueba'),(7,'Cuenta','Clave')");
$_SESSION=['usuario_id'=>5,'rol_id'=>4,'permisos'=>['reuniones.ver']];
$repo=new AgendaComentarioRepository($db);$now=new DateTimeImmutable('2026-10-28 10:00:00');
$s=new AgendaComentarioService($repo,$now);
$a=$s->crear(5,['fecha'=>'2026-10-28','hora'=>'','comentario'=>str_repeat('😀',250)]);
check($a['ok']&&$a['comentario']['autor']==='Analista Prueba','Crear, UTF8 y autor reales');
$id=$a['comentario']['id'];
$timed=$s->crear(5,['fecha'=>'2026-10-28','hora'=>'11:00','comentario'=>'Programado']);$scheduled=$timed['comentario']['id'];
$_SESSION=['usuario_id'=>7,'rol_id'=>6,'permisos'=>['reuniones.ver']];
check(count($s->listar(7,'2026-10-01','2026-11-01')['comentarios'])===2,'Listar con JOIN real');
check(count($s->notificaciones(7)['recordatorios'])===2,'Campana consulta SQL real');
check($s->eliminar(7,$id)['codigo_http']===403,'Autor ajeno');
check($s->leer(7,$scheduled)['ok'],'Lectura previa');
$later=new AgendaComentarioService($repo,new DateTimeImmutable('2026-10-28 11:01:00'));
check(count(array_filter($later->notificaciones(7)['recordatorios'],fn($r)=>$r['agenda_comentario_id']===$scheduled&&$r['estado']==='vencida'))===1,'Hora de PHP con SQL UTC');
check($later->leer(7,$scheduled)['ok']&&$later->leer(7,$scheduled)['ok'],'UPSERT posterior repetible');
check(count($later->notificaciones(7)['recordatorios'])===1,'Atención detiene sólo programado');
check($later->leer(7,$id)['ok']&&$later->notificaciones(7)['recordatorios']===[],'Lectura sin hora');
$_SESSION=['usuario_id'=>5,'rol_id'=>4,'permisos'=>['reuniones.ver']];
check(count($later->notificaciones(5)['recordatorios'])===2,'Lectura individual');
check($later->eliminar(5,$id)['ok']&&count($later->notificaciones(5)['recordatorios'])===1,'Borrado lógico');
check((int)$db->query('SELECT COUNT(*) FROM usuarios')->fetch_row()[0]===2,'Usuarios intactos');
check((int)$db->query('SELECT COUNT(*) FROM agenda_comentarios')->fetch_row()[0]===2,'Se conserva trazabilidad');
echo "OK: esquema y SQL reales MariaDB; 250 Unicode, NULL, autor, JOIN, UPSERT, reloj PHP/SQL UTC, lectura por usuario y borrado.\\n";
''',encoding='utf8')
    with (area/'server.log').open('w') as log:
        server=subprocess.Popen([str(MYSQL/'bin/mysqld.exe'),'--no-defaults','--basedir='+str(MYSQL),
            '--datadir='+str(data),'--port='+str(port),'--bind-address=127.0.0.1','--skip-grant-tables','--console'],
            env=env,stdout=log,stderr=log,creationflags=FLAGS)
        try:
            for _ in range(150):
                if server.poll() is not None:raise RuntimeError((area/'server.log').read_text(errors='replace'))
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.1)
            create=f"$d=new mysqli('127.0.0.1','root','','',{port});$d->query('CREATE DATABASE agenda_test CHARACTER SET utf8mb4');"
            subprocess.run([PHP,'-r',create],env=env,check=True,capture_output=True)
            check=subprocess.run([PHP,str(site/'tools/configurar_agenda_comentarios.php'),'--check'],env=env,capture_output=True)
            assert check.returncode!=0
            for _ in range(2):subprocess.run([PHP,str(site/'tools/configurar_agenda_comentarios.php'),'--apply'],env=env,check=True,capture_output=True)
            result=subprocess.run([PHP,str(site/'test.php')],env=env,check=True,capture_output=True,text=True,encoding='utf8')
            print(result.stdout.strip())
            subprocess.run([PHP,str(site/'tools/configurar_agenda_comentarios.php'),'--check'],env=env,check=True,capture_output=True)
        finally:
            server.terminate();server.wait(timeout=20)
