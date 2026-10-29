"""Endpoints reales en servidor PHP local con sesiones y SQLite de prueba."""
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import sys
sys.dont_write_bytecode = True
import urllib.error
import urllib.parse
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
HOSTINGER='--hostinger' in sys.argv
DESTINATARIOS='--destinatarios' in sys.argv
VISTOS='--vistos' in sys.argv or DESTINATARIOS
PRIVADOS='--privados' in sys.argv or DESTINATARIOS
sys.path.insert(0,str(ROOT/'tools'))
from agenda_comentarios_compat import reminder_hostinger
PHP=os.environ.get('IMPE_TEST_PHP',r'C:\xampp\php\php.exe' if os.name=='nt' else 'php')
(ROOT/'tmp').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='agenda-comments-http-',dir=ROOT/'tmp') as tmp:
    work=Path(tmp)
    for rel in ['app/controllers/AgendaComentarioController.php','app/controllers/ReminderController.php','app/services/AgendaComentarioService.php',
                'app/services/AgendaComentarioRepository.php','app/helpers/PermissionHelper.php','app/helpers/AdminRolePolicy.php']:
        p=work/rel;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/rel,p)
    if HOSTINGER:
        base=subprocess.check_output(['git','show','5116b62c284de2f9e27440f77982964e876c7402:app/controllers/ReminderController.php'],cwd=ROOT).decode('utf8')
        _,variant=reminder_hostinger(base,(ROOT/'app/controllers/ReminderController.php').read_text(encoding='utf8'))
        (work/'app/controllers/ReminderController.php').write_text(variant,encoding='utf8')
    # Aislar los proveedores ajenos: se ejecuta el controlador real de campana
    # con perfiles de sólo consulta, para los que esos proveedores no se llaman.
    (work/'app/helpers/ReminderHelper.php').write_text('<?php function puedeCrearConvocatoriaDesdeConvenio(){return false;}',encoding='utf8')
    for service in ['AgendaReunionService','ReminderAgendaFilterService','ReminderReunionFollowupService',
                    'ReminderDirectLinkService','ReminderObservacionService','ReminderMeetingConfirmationService',
                    'SeguimientoCambioDatosService','AliadoSeguimientoReminderService','AliadoNuevoNotificationService']:
        (work/f'app/services/{service}.php').write_text('<?php class '+service+' {}',encoding='utf8')
    (work/'app/services/AdminNotificationService.php').write_text('''<?php class AdminNotificationService {
public function obtener(){return ['ok'=>true,'total'=>1,'recordatorios'=>[['id'=>90,'accion'=>'Resumen previo','estado'=>'normal']], 'avisos'=>[]];}}
''',encoding='utf8')
    (work/'app/services/AliadoConvocatoriaPendienteService.php').write_text('''<?php class AliadoConvocatoriaPendienteService {
public function obtener(...$args){return array_map(static fn($id)=>['id'=>$id,'tipo'=>'ALIADO_CONVOCATORIA_PENDIENTE','etiqueta'=>'Configurar programas','estado'=>'normal','prioridad'=>1],range(100,129));}}
''',encoding='utf8')
    shutil.copyfile(ROOT/'tests/fixtures/agenda_comentarios_db.php',work/'adapter.php')
    (work/'config').mkdir()
    (work/'config/db_connection.php').write_text('''<?php
date_default_timezone_set('America/Mexico_City');
require_once __DIR__.'/../adapter.php';
class Database { public function connect(){return new ComentarioDbPrueba(getenv('AGENDA_QA_DB'),false);} }
''',encoding='utf8')
    init=work/'init.php';init.write_text("<?php require __DIR__.'/adapter.php';new ComentarioDbPrueba($argv[1]);",encoding='utf8')
    db=work/'test.sqlite'
    subprocess.run([PHP,str(init),str(db)],check=True,capture_output=True)
    if VISTOS:
        sql="CREATE TABLE agenda_comentarios_vistos_avisos(comentario_id INTEGER,lector_id INTEGER,leido_at TEXT,PRIMARY KEY(comentario_id,lector_id))"
        subprocess.run([PHP,'-r',"$d=new PDO('sqlite:'.$argv[1]);$d->exec($argv[2]);",str(db),sql],check=True,capture_output=True)
    token=secrets.token_hex(24)
    if PRIVADOS:
        subprocess.run([PHP,'-r',"$d=new PDO('sqlite:'.$argv[1]);$d->exec('ALTER TABLE agenda_comentarios ADD COLUMN privado INTEGER NOT NULL DEFAULT 0');",str(db)],check=True,capture_output=True)
    if DESTINATARIOS:
        sql="""ALTER TABLE agenda_comentarios ADD COLUMN destinatario_id INTEGER DEFAULT NULL;
ALTER TABLE usuarios ADD COLUMN rol_id INTEGER DEFAULT 4; ALTER TABLE usuarios ADD COLUMN estado INTEGER DEFAULT 1;
CREATE TABLE roles(id INTEGER PRIMARY KEY,nombre TEXT,estado INTEGER);
CREATE TABLE permisos(id INTEGER PRIMARY KEY,codigo TEXT,estado INTEGER);
CREATE TABLE rol_permisos(rol_id INTEGER,permiso_id INTEGER);
INSERT INTO roles VALUES(1,'Administrador',1),(4,'Analista',1),(6,'Cuenta Clave',1);
INSERT INTO permisos VALUES(26,'reuniones.ver',1);
INSERT INTO rol_permisos VALUES(1,26),(4,26),(6,26);
UPDATE usuarios SET rol_id=6 WHERE id=7;
INSERT INTO usuarios VALUES(15,'Admin','Prueba',1,1);"""
        subprocess.run([PHP,'-r',"$d=new PDO('sqlite:'.$argv[1]);$d->exec($argv[2]);",str(db),sql],check=True,capture_output=True)
    (work/'router.php').write_text('''<?php
session_start();header('Content-Type: application/json');
if($_SERVER['REQUEST_URI']==='/login'){
 if(!hash_equals(getenv('AGENDA_QA_TOKEN'),$_POST['token']??'')){http_response_code(403);exit;}
 $_SESSION=['usuario_id'=>(int)$_POST['id'],'rol_id'=>(int)($_POST['rol']??4),
 'permisos'=>($_POST['agenda']??'1')==='1'?['reuniones.ver']:[],'csrf_token'=>'qa-csrf'];
 if(($_POST['tareas']??'0')==='1')$_SESSION['permisos']=array_merge($_SESSION['permisos'],['aliados.ver','aliados.gestionar_programas']);
 echo '{"ok":true}';exit;
}
if(($_GET['action']??'')==='pendientes'){
 require __DIR__.'/app/controllers/ReminderController.php';(new ReminderController())->pendientes();exit;
}
require __DIR__.'/app/controllers/AgendaComentarioController.php';
$action=$_GET['action']??'listar';
if(!in_array($action,['listar','crear','eliminar','leer','leerVisto'],true)){http_response_code(404);exit;}
(new AgendaComentarioController())->$action();
''',encoding='utf8')
    (work/'sessions').mkdir()
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    env=dict(os.environ,AGENDA_QA_DB=str(db),AGENDA_QA_TOKEN=token)
    with (work/'http.log').open('w') as log:
        process=subprocess.Popen([PHP,'-d','session.save_path='+str(work/'sessions'),'-S',f'127.0.0.1:{port}',str(work/'router.php')],
            cwd=work,env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
        try:
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.1)
            def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            def request(c,action,data=None,csrf='qa-csrf',query=''):
                url=f'http://127.0.0.1:{port}/?action={action}'+query
                req=urllib.request.Request(url,data=urllib.parse.urlencode(data).encode() if data is not None else None,
                    headers={'X-CSRF-Token':csrf})
                try:r=c.open(req)
                except urllib.error.HTTPError as e:r=e
                with r:return r.status,json.loads(r.read())
            def login(c,id,agenda=1,rol=4,tareas=0):
                c.open(f'http://127.0.0.1:{port}/login',urllib.parse.urlencode(dict(token=token,id=id,agenda=agenda,rol=rol,tareas=tareas)).encode()).close()
            a=client();b=client();without=client()
            assert request(a,'listar',query='&desde=2026-10-01&hasta=2026-11-01')[0]==403
            login(a,5);login(b,7,rol=6);login(without,9,agenda=0)
            assert request(without,'crear',dict(fecha='2026-10-28',comentario='no',hora=''))[0]==403
            assert request(a,'crear')[0]==405
            assert request(a,'crear',dict(fecha='2026-10-28',comentario='no',hora=''),csrf='incorrecto')[0]==419
            assert request(a,'crear',dict(fecha='2026-02-30',comentario='no',hora=''))[0]==422
            code,result=request(a,'crear',dict(fecha='2026-10-28',comentario='<script>literal</script>',hora='',creado_por=7))
            assert code==200 and result['comentario']['autor']=='Analista de Prueba'
            id=result['comentario']['id']
            code,result=request(b,'listar',query='&desde=2026-10-01&hasta=2026-11-01')
            assert code==200 and result['comentarios'][0]['comentario']=='<script>literal</script>'
            assert not result['comentarios'][0]['puede_eliminar'] and result['comentarios'][0]['hora'] is None
            assert request(b,'eliminar',dict(id=id))[0]==403
            assert request(b,'leer',dict(id=id))[0]==200
            assert request(b,'listar',query='&desde=2026-10-01&hasta=2026-11-01')[1]['comentarios'][0]['leido']
            assert not request(a,'listar',query='&desde=2026-10-01&hasta=2026-11-01')[1]['comentarios'][0]['leido']
            assert request(a,'eliminar',dict(id=id))[0]==200
            assert request(b,'listar',query='&desde=2026-10-01&hasta=2026-11-01')[1]['comentarios']==[]
            assert request(b,'leer',dict(id=id))[0]==404
            assert request(a,'listar',query='&desde=2026-01-01&hasta=2027-01-01')[0]==422
            login(b,8,rol=2)
            assert request(b,'crear',dict(fecha='2026-10-28',comentario='Desde perfil con Agenda',hora='18:30'))[0]==200
            code,bell=request(b,'pendientes');assert code==200 and bell['ok'] and len(bell['recordatorios'])==1 and len(bell['avisos'])==1
            assert request(b,'pendientes')[1]['avisos']==[], 'Novedad no repetida en cada consulta'
            assert request(without,'pendientes')[0]==403
            admin=client();login(admin,15,rol=1)
            code,bell=request(admin,'pendientes');assert code==200 and bell['ok'] and bell['total']==2
            assert len(bell['avisos'])==1 and bell['recordatorios'][0]['id']==90
            login(admin,15,agenda=0,rol=1)
            bell=request(admin,'pendientes')[1];assert bell['total']==1 and bell['avisos']==[]
            if HOSTINGER:
                kam=client();login(kam,7,rol=6,tareas=1)
                bell=request(kam,'pendientes')[1]
                assert bell['ok'] and bell['total']==31
                assert len([r for r in bell['recordatorios'] if r.get('tipo')=='ALIADO_CONVOCATORIA_PENDIENTE'])==30
                note=next(r for r in bell['recordatorios'] if r.get('agenda_comentario_id'))
                assert request(kam,'leer',dict(id=note['agenda_comentario_id']))[0]==200
                assert request(kam,'pendientes')[1]['total']==30, 'Leer comentario no resuelve tareas de Aliados'
                login(kam,7,agenda=0,rol=6,tareas=1)
                bell=request(kam,'pendientes')[1];assert bell['total']==30 and not any(r.get('agenda_comentario_id') for r in bell['recordatorios'])
            if VISTOS:
                login(a,5);login(b,7,rol=6)
                note=request(a,'crear',dict(fecha='2026-10-28',comentario='Lectura entre perfiles',hora=''))[1]['comentario']['id']
                assert request(b,'leer',dict(id=note,nombre='Falso',lector_id=99))[0]==200
                assert request(b,'leer',dict(id=note))[0]==200
                def notices(c):return [r for r in request(c,'pendientes')[1]['recordatorios'] if r.get('agenda_comentario_id')==note and r.get('agenda_comentario_visto_por')]
                receipts=notices(a);assert len(receipts)==1 and receipts[0]['agenda_comentario_visto_por']==7
                assert 'Cuenta Clave marcó como leído' in receipts[0]['mensaje'] and notices(b)==[]
                third=client();login(third,8)
                assert request(third,'leerVisto',dict(id=note,lector_id=7))[0]==403
                assert request(a,'leerVisto',dict(id=note,lector_id=7),csrf='incorrecto')[0]==419
                assert request(a,'leerVisto',dict(id=note,lector_id=7))[0]==200
                assert request(a,'leerVisto',dict(id=note,lector_id=7))[0]==200 and notices(a)==[]
                listed=request(a,'listar',query='&desde=2026-10-01&hasta=2026-11-01')[1]['comentarios']
                assert next(c for c in listed if c['id']==note)['leido'] is False,'Abrir aviso de visto no lee el comentario original'
                assert request(without,'leerVisto',dict(id=note,lector_id=7))[0]==403
                print('OK: HTTP de vistos: lector de sesión, sólo autor, sin duplicado, CSRF, ack idempotente y comentario original intacto.')
            if PRIVADOS:
                login(a,5);login(b,7,rol=6);login(admin,15,rol=1)
                code,result=request(a,'crear',dict(fecha='2026-10-28',comentario='Privado HTTP',hora='00:00',privado='1',creado_por=7))
                assert code==200 and result['comentario']['privado'] is True and result['comentario']['hora']=='00:00'
                private_id=result['comentario']['id']
                for other in [b,admin]:
                    listed=request(other,'listar',query='&desde=2026-10-01&hasta=2026-11-01')[1]['comentarios']
                    assert not any(c['id']==private_id for c in listed)
                    assert 'Privado HTTP' not in json.dumps(request(other,'pendientes')[1])
                    for action in ['leer','eliminar']:
                        assert request(other,action,dict(id=private_id))[0]==404
                    assert request(other,'leerVisto',dict(id=private_id,lector_id=5))[0]==404
                assert any(r.get('agenda_comentario_id')==private_id for r in request(a,'pendientes')[1]['recordatorios'])
                assert request(a,'leer',dict(id=private_id))[0]==200
                assert request(a,'eliminar',dict(id=private_id))[0]==200
                assert request(a,'crear',dict(fecha='2026-10-28',comentario='X',privado='yes'))[0]==422
                print('OK: privados HTTP: calendario/campana/IDs directos, lector/admin ajenos excluidos y autor con hora opcional.')
            if DESTINATARIOS:
                login(a,5);login(b,7,rol=6);login(admin,15,rol=1)
                third=client();login(third,8)
                code,result=request(a,'crear',dict(fecha='2026-10-28',comentario='Sólo para destinatario HTTP',destinatario_id=7))
                assert code==200 and result['comentario']['destinatario_id']==7
                direct=result['comentario']['id']
                for other in [third,admin]:
                    listed=request(other,'listar',query='&desde=2026-10-01&hasta=2026-11-01')[1]
                    assert 'Sólo para destinatario HTTP' not in json.dumps(listed,ensure_ascii=False)
                    assert 'Sólo para destinatario HTTP' not in json.dumps(request(other,'pendientes')[1],ensure_ascii=False)
                    for action in ['leer','eliminar']:
                        assert request(other,action,dict(id=direct))[0]==404
                    assert request(other,'leerVisto',dict(id=direct,lector_id=7))[0]==404
                for participant in [a,b]:
                    listed=request(participant,'listar',query='&desde=2026-10-01&hasta=2026-11-01')[1]['comentarios']
                    assert any(c['id']==direct for c in listed)
                assert any(r.get('agenda_comentario_id')==direct for r in request(b,'pendientes')[1]['recordatorios'])
                assert not any(r.get('agenda_comentario_id')==direct for r in request(a,'pendientes')[1]['recordatorios'])
                assert request(b,'eliminar',dict(id=direct))[0]==403
                assert request(b,'leer',dict(id=direct))[0]==200
                assert any(r.get('agenda_comentario_id')==direct and r.get('agenda_comentario_visto_por')==7 for r in request(a,'pendientes')[1]['recordatorios'])
                assert request(a,'eliminar',dict(id=direct))[0]==200
                print('OK: dirigidos HTTP: sólo autor/destinatario; terceros y administrador sin contenido en calendario/campana ni acceso por ID; aviso de lectura y borrado conservados.')
            print('OK: HTTP real ('+('Hostinger: conserva las 30 tareas de Aliados; ' if HOSTINGER else 'base; ')+
                  'CSRF, autor/borrado, consulta/administrador y exclusión sin Agenda). BD aislada.')
        finally:process.terminate();process.wait(timeout=10)
