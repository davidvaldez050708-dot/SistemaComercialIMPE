"""Confirmación HTTP real sobre MariaDB efímero, con reuniones sintéticas."""
import http.client
import json
import os
import re
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import sys

ROOT=Path(__file__).resolve().parents[1];PHP='C:/xampp/php/php.exe';MYSQL=Path('C:/xampp/mysql')
FLAGS=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
HOSTINGER_SQL='--hostinger-sql' in sys.argv
def free_port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
with tempfile.TemporaryDirectory(prefix='agenda-confirm-sql-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp);site=area/'site';site.mkdir();(site/'index.php').write_text('<?php',encoding='utf8');data=area/'data';port=free_port();webport=free_port()
    subprocess.run([str(MYSQL/'bin/mysql_install_db.exe'),'--datadir='+str(data),'--silent'],check=True,capture_output=True,creationflags=FLAGS)
    for file in ['app/controllers/AgendaReunionController.php','app/services/AgendaReunionService.php','app/services/AgendaReunionRepository.php',
                 'app/services/ReprogramacionReunionService.php','app/services/EcardReunionService.php',
                 'app/helpers/PermissionHelper.php','app/helpers/AdminRolePolicy.php','tools/diagnosticar_confirmacion_reunion.php']:
        p=site/file;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/file,p)
    (site/'app/services/SeguimientoExpedienteService.php').write_text('<?php class SeguimientoExpedienteService {public function __construct(){throw new RuntimeException("Dependencia no requerida");}}',encoding='utf8')
    if HOSTINGER_SQL:
        file=site/'app/services/AgendaReunionRepository.php'
        pattern=r'    public function confirmar\([^\n]*\)\n    \{[\s\S]*?\n    \}\n'
        method=re.search(pattern,(ROOT/'tests/fixtures/agenda_confirmar_hostinger.php').read_text(encoding='utf8')).group()
        content=re.sub(pattern,lambda m:method,file.read_text(encoding='utf8'),count=1)
        file.write_text(content.replace('<?php',"<?php\nrequire_once __DIR__.'/scope_fixture.php';",1),encoding='utf8')
        shutil.copyfile(ROOT/'tests/fixtures/agenda_scope_hostinger.txt',site/'app/services/scope_fixture.txt')
        (site/'app/services/scope_fixture.php').write_text("<?php class SeguimientoTerritorialScope {public static function reunionSupervisor($alias){if($alias!=='reuniones_vinculacion')throw new RuntimeException('Alias inesperado');return trim(file_get_contents(__DIR__.'/scope_fixture.txt'));}}",encoding='utf8')
    (site/'config').mkdir();(site/'config/db_connection.php').write_text('''<?php
date_default_timezone_set('America/Mexico_City');mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
class Database {public function connect($persistent=true){$d=new mysqli('127.0.0.1','root','','agenda_confirm',(int)getenv('AGENDA_TEST_PORT'));$d->set_charset('utf8mb4');$d->query("SET time_zone='+00:00'");return $d;}}
''',encoding='utf8')
    (site/'router.php').write_text('''<?php
ini_set('display_errors','0');$_SESSION=['usuario_id'=>7,'rol_id'=>6,'permisos'=>['reuniones.ver','reuniones.gestionar']];
require __DIR__.'/app/controllers/AgendaReunionController.php';$c=new AgendaReunionController();$action=$_GET['action']??'confirmar';$c->$action();
''',encoding='utf8')
    (site/'init.php').write_text('''<?php
require __DIR__.'/config/db_connection.php';$d=(new Database())->connect();
$queries=[
 "CREATE TABLE usuarios(id INT PRIMARY KEY,nombre TEXT,apellidos TEXT,correo TEXT)",
 "INSERT INTO usuarios VALUES(5,'Analista','Prueba','a@example.test'),(7,'Cuenta','Clave','k@example.test')",
 "CREATE TABLE estados(id INT PRIMARY KEY,nombre TEXT)","CREATE TABLE municipios(id INT PRIMARY KEY,nombre TEXT)",
 "CREATE TABLE seguimientos_vinculacion(id INT PRIMARY KEY,nombre_entidad TEXT,contacto_nombre TEXT,contacto_cargo TEXT,estado_id INT,municipio_id INT,correo_verificado TEXT,correo_fuente TEXT)",
 "INSERT INTO seguimientos_vinculacion VALUES(12,'Institución sintética','Prueba','Prueba',NULL,NULL,'test@example.test',NULL)",
 "CREATE TABLE reuniones_vinculacion(id INT PRIMARY KEY,seguimiento_id INT,analista_id INT,cuenta_clave_id INT,fecha_propuesta DATETIME,duracion_minutos INT,modalidad VARCHAR(20),estado VARCHAR(30),zoom_url TEXT,ubicacion TEXT,notas_kam TEXT,confirmada_at DATETIME,confirmada_por INT,notificado_analista_at DATETIME,es_reprogramacion INT DEFAULT 0)",
 "CREATE TABLE interacciones_vinculacion(id INT AUTO_INCREMENT PRIMARY KEY,seguimiento_id INT,usuario_id INT,canal ENUM('SISTEMA','NOTA'),fecha_inicio DATETIME,resultado ENUM('OTRO'),notas TEXT)",
 "CREATE TABLE reuniones_vinculacion_reprogramaciones(id INT AUTO_INCREMENT PRIMARY KEY,reunion_id INT,estado VARCHAR(30),zoom_nuevo TEXT,ubicacion_nueva TEXT,confirmada_at DATETIME,confirmada_por INT)",
 "INSERT INTO reuniones_vinculacion_reprogramaciones(reunion_id,estado) VALUES(9,'PENDIENTE_KAM')",
 "CREATE TRIGGER fallo_confirmar BEFORE UPDATE ON reuniones_vinculacion FOR EACH ROW BEGIN IF OLD.id=10 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='FALLO SINTÉTICO'; END IF; END"
];foreach($queries as$q)$d->query($q);
if(getenv('AGENDA_TEST_HOSTINGER_SQL')){
 foreach([
  "ALTER TABLE usuarios ADD COLUMN estado INT DEFAULT 1, ADD COLUMN rol_id INT",
  "UPDATE usuarios SET rol_id=IF(id=7,6,4)","INSERT INTO usuarios VALUES(99,'Otra','Cuenta','otro@example.test',1,6)",
  "CREATE TABLE roles(id INT PRIMARY KEY,estado INT)","INSERT INTO roles VALUES(4,1),(6,1)",
  "ALTER TABLE estados ADD COLUMN estado INT DEFAULT 1","INSERT INTO estados VALUES(11,'Estado de prueba',1)",
  "ALTER TABLE seguimientos_vinculacion ADD COLUMN activo INT DEFAULT 1","UPDATE seguimientos_vinculacion SET estado_id=11",
  "CREATE TABLE asignaciones_territorio(id INT PRIMARY KEY,usuario_id INT,estado_id INT,tipo_asignacion VARCHAR(30),activo INT,fecha_inicio DATE,fecha_fin DATE,cuenta_clave_asignacion_id INT)",
  "INSERT INTO asignaciones_territorio VALUES(1,7,11,'CUENTA_CLAVE',1,NULL,NULL,NULL),(2,5,11,'ANALISTA_DATOS',1,NULL,NULL,1)"
 ]as$q)$d->query($q);
}
foreach([8,9,10]as$id){$f=date('Y-m-d H:i:s',time()+($id-7)*86400);$s=$d->prepare("INSERT INTO reuniones_vinculacion(id,seguimiento_id,analista_id,cuenta_clave_id,fecha_propuesta,duracion_minutos,modalidad,estado,es_reprogramacion) VALUES(?,12,5,7,?,60,'VIRTUAL','SOLICITADA',?)");$reprogramada=(int)($id===9);$s->bind_param('isi',$id,$f,$reprogramada);$s->execute();}
if(getenv('AGENDA_TEST_HOSTINGER_SQL')){$f=date('Y-m-d H:i:s',time()+4*86400);$s=$d->prepare("INSERT INTO reuniones_vinculacion(id,seguimiento_id,analista_id,cuenta_clave_id,fecha_propuesta,duracion_minutos,modalidad,estado) VALUES(11,12,5,99,?,60,'VIRTUAL','SOLICITADA')");$s->bind_param('s',$f);$s->execute();}
''',encoding='utf8')
    env=dict(os.environ,AGENDA_TEST_PORT=str(port),IMPE_CORREOS_PUBLIC=str(site),AGENDA_TEST_HOSTINGER_SQL='1' if HOSTINGER_SQL else '')
    with (area/'db.log').open('w') as log:
        dbserver=subprocess.Popen([str(MYSQL/'bin/mysqld.exe'),'--no-defaults','--basedir='+str(MYSQL),'--datadir='+str(data),'--port='+str(port),'--bind-address=127.0.0.1','--skip-grant-tables','--console'],env=env,stdout=log,stderr=log,creationflags=FLAGS)
        try:
            for _ in range(150):
                if dbserver.poll() is not None:raise RuntimeError((area/'db.log').read_text(errors='replace'))
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.1)
            subprocess.run([PHP,'-r',f"$d=new mysqli('127.0.0.1','root','','',{port});$d->query('CREATE DATABASE agenda_confirm CHARACTER SET utf8mb4');"],env=env,check=True,capture_output=True)
            subprocess.run([PHP,str(site/'init.php')],env=env,check=True,capture_output=True)
            if HOSTINGER_SQL:
                broken=subprocess.run([PHP,str(site/'tools/diagnosticar_confirmacion_reunion.php'),'--check'],env=env,capture_output=True,text=True,encoding='utf8')
                assert broken.returncode==1 and 'preparar_confirmacion=ERROR CLASE=mysqli_sql_exception CODIGO=1064' in broken.stdout,broken.stdout+broken.stderr
                deploy_env=dict(env,IMPE_CORREOS_BACKUPS=str(area/'backups'),PATH='C:/xampp/php'+os.pathsep+env.get('PATH',''))
                for mode in ['--check','--apply']:
                    deployed=subprocess.run(['C:/Program Files/Git/bin/bash.exe',str(ROOT/'tools/publicar_confirmacion_sql_hostinger.sh'),mode],env=deploy_env,capture_output=True,text=True,encoding='utf8')
                    assert deployed.returncode==0,deployed.stdout+deployed.stderr
                guard="require $argv[1].'/app/services/AgendaReunionRepository.php';$r=new AgendaReunionRepository();if($r->confirmar(11,99,'https://zoom.us/j/no','',''))exit(1);"
                subprocess.run([PHP,'-r',guard,str(site)],env=env,check=True,capture_output=True)
            probe=subprocess.run([PHP,str(site/'tools/diagnosticar_confirmacion_reunion.php'),'--check'],env=env,check=True,capture_output=True,text=True,encoding='utf8')
            assert 'preparar_confirmacion=OK' in probe.stdout and 'preparar_interaccion=OK' in probe.stdout
            with (area/'http.log').open('w') as http_log:
                web=subprocess.Popen([PHP,'-S',f'127.0.0.1:{webport}',str(site/'router.php')],cwd=site,env=env,stdout=http_log,stderr=http_log,creationflags=FLAGS)
                try:
                    for _ in range(80):
                        try:
                            with socket.create_connection(('127.0.0.1',webport),timeout=.1):break
                        except OSError:time.sleep(.05)
                    def request(action,id):
                        c=http.client.HTTPConnection('127.0.0.1',webport);c.request('POST','/?action='+action,f'reunion_id={id}&zoom_url=https%3A%2F%2Fzoom.us%2Fj%2Fprueba&notas_kam=Nota',{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'})
                        r=c.getresponse();raw=r.read();payload=json.loads(raw);c.close();return r.status,payload
                    for action,id in [('confirmar',8),('confirmarReprogramacion',9)]:
                        status,payload=request(action,id);assert status==200 and payload['ok'] is True,(status,payload)
                        status,payload=request(action,id);assert status==409 and payload['ok'] is False
                    status,payload=request('confirmar',10);assert status==500 and payload['ok'] is False and payload['referencia']
                finally:web.terminate();web.wait(timeout=10)
            check="require $argv[1].'/config/db_connection.php';$d=(new Database())->connect();$rows=$d->query('SELECT id,estado,confirmada_por FROM reuniones_vinculacion ORDER BY id')->fetch_all(MYSQLI_ASSOC);if($rows[0]['estado']!=='CONFIRMADA'||$rows[1]['estado']!=='CONFIRMADA'||$rows[2]['estado']!=='SOLICITADA'||(int)$rows[0]['confirmada_por']!==7)exit(1);if((int)$d->query('SELECT COUNT(*) FROM interacciones_vinculacion')->fetch_row()[0]!==2)exit(2);if($d->query('SELECT estado FROM reuniones_vinculacion_reprogramaciones')->fetch_row()[0]!=='CONFIRMADA')exit(3);"
            subprocess.run([PHP,'-r',check,str(site)],env=env,check=True,capture_output=True)
            print('OK: HTTP + MariaDB reales: confirmar, confirmar reprogramación e historial, no duplicar, excepción SQL en JSON y diagnóstico sin escrituras. Datos sintéticos.')
            if HOSTINGER_SQL:print('OK: fallo Hostinger 1064 reproducido, parche real de una línea aplicado, EXISTS territorial exacto conservado y Cuenta Clave sin asignación rechazada.')
        finally:dbserver.terminate();dbserver.wait(timeout=20)
