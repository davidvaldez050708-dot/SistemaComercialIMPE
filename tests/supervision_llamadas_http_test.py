"""Controlador real con SQL efímero y proveedor inyectado únicamente en el router de prueba."""
import json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.parse,urllib.error,uuid
R=pathlib.Path(__file__).resolve().parents[1]
env=os.environ.copy();assert env.get('IMPE_TEST_MYSQL_PORT'),'Indica servicio SQL aislado'
env['IMPE_VENTAS_TEST_DB']='impe_ventas_reportes_test_'+uuid.uuid4().hex[:8]
php=env.get('PHP_BINARY','php');server=None
def run(file,*args):
    p=subprocess.run([php,str(R/file),*args],env=env,capture_output=True)
    assert p.returncode==0,p.stdout.decode(errors='replace')+p.stderr.decode(errors='replace')
    return p.stdout
try:
    print(run('tests/supervision_llamadas_db.php').decode().strip())
    with tempfile.TemporaryDirectory() as folder:
        root=pathlib.Path(folder)/'site';root.mkdir();shutil.copytree(R/'app',root/'app');shutil.copytree(R/'public/css',root/'public/css');shutil.copytree(R/'public/javascript',root/'public/javascript');(root/'index.php').write_text('<?php')
        (root/'config').mkdir()
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        url=f'http://127.0.0.1:{port}/';env['IMPE_TEST_ROOT_URL']=url
        (root/'config/db_connection.php').write_text('''<?php
        defined('ROOT_PATH')||define('ROOT_PATH',dirname(__DIR__));defined('BASE_URL')||define('BASE_URL',getenv('IMPE_TEST_ROOT_URL'));
        date_default_timezone_set('America/Mexico_City');
        class Database {public function connect(bool $persistent=true){$db=new mysqli('127.0.0.1','root','',getenv('IMPE_VENTAS_TEST_DB'),(int)getenv('IMPE_TEST_MYSQL_PORT'));$db->set_charset('utf8mb4');return $db;}}
        ''',encoding='utf8')
        for name in ['dashboard_head.php','sidebar.php','topbar.php','dashboard_footer.php']:(root/'app/views/layout'/name).write_text('')
        (root/'router.php').write_text('''<?php
        require __DIR__.'/config/db_connection.php';require __DIR__.'/app/controllers/SupervisionLlamadasController.php';
        $_SESSION=['usuario_id'=>(int)($_GET['uid']??20),'rol_id'=>2,'permisos'=>['llamadas.supervision.ver','llamadas.supervision.audio']];
        class FixtureController extends SupervisionLlamadasController {
          protected function proveedor(): object {return new class {
            public function obtenerGrabacionPorCallId(string $id):array{return ['link'=>'https://audio.invalid/'.$id];}
            public function estadisticasPbx(string $a,string $b):array{return [];}
            public function descargarGrabacion(string $url,string $range):array{return ['body'=>'0123456789','content_type'=>'audio/mpeg'];}
          };}
        }
        if(isset($_GET['delegate'])){require __DIR__.'/app/controllers/ReporteController.php';$action=['historial'=>'supervisionLlamadas','detalle'=>'supervisionDetalle','audio'=>'supervisionAudio'][$_GET['mode']??'historial'];(new ReporteController())->$action();}
        else (new FixtureController())->ejecutar($_GET['mode']??'historial');
        ''',encoding='utf8')
        def sql(statement):
            p=subprocess.run([php,'-r',"require $argv[1];(new Database())->connect(false)->query($argv[2]);",str(root/'config/db_connection.php'),statement],env=env,capture_output=True)
            assert p.returncode==0,p.stderr
        log=open(root/'server.log','wb');server=subprocess.Popen([php,'-d','opcache.enable_cli=0','-S',f'127.0.0.1:{port}',str(root/'router.php')],cwd=root,env=env,stdout=log,stderr=log)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2):break
            except OSError:time.sleep(.05)
        def request(headers=None,**params):
            req=urllib.request.Request(url+'?'+urllib.parse.urlencode(params),headers=headers or {})
            try:
                with urllib.request.urlopen(req,timeout=20) as r:return r.status,dict(r.headers),r.read()
            except urllib.error.HTTPError as e:return e.code,dict(e.headers),e.read()
        today=time.strftime('%Y-%m-%d')
        # Dates from fixtures use Mexico City; avoid depending on CI midnight.
        from datetime import datetime
        from zoneinfo import ZoneInfo
        today=datetime.now(ZoneInfo('America/Mexico_City')).strftime('%Y-%m-%d')
        for uid in [10,20,601]:
            for area in ['asesores','analistas']:
                status,h,b=request(uid=uid,area=area,desde=today,hasta=today)
                assert status==200,b.decode(errors='replace');assert b'data-supervision-calls' in b
                assert b'Warning:' not in b and b'Fatal error' not in b
                assert (b'Analista Ajeno' in b)==(area=='analistas' and uid in [10,20])
        for uid in [0,301,401,700]:assert request(uid=uid)[0]==(401 if uid==0 else 403)
        assert request(uid=20,delegate=1,area='analistas',desde=today,hasta=today)[0]==200
        assert request(uid=601,delegate=1,mode='detalle',llamada='interaccion:1')[0]==200
        assert request(uid=601,delegate=1,mode='audio',llamada='interaccion:2')[0]==403
        for mode in ['detalle','audio']:
            assert request(uid=601,mode=mode,llamada='interaccion:2',verificar=1)[0]==403
            assert request(uid=601,mode=mode,llamada='interaccion:3',verificar=1)[0]==403
            assert request(uid=601,mode=mode,llamada='../etc/passwd')[0]==422
        status,h,b=request(uid=601,mode='detalle',llamada='interaccion:1');assert status==200 and json.loads(b)['llamada']['observacion']=='<script>hostil</script>'
        status,h,b=request(uid=601,mode='audio',llamada='entrante:2',verificar=1);payload=json.loads(b)
        assert status==200 and payload['segmentos']==2 and b'audio.invalid' not in b and b'records' not in b and b'pbx_call_id' not in b
        status,h,b=request(uid=601,mode='audio',llamada='entrante:2',segmento=1,headers={'Range':'bytes=2-4'})
        assert status==206 and b==b'234' and h['Content-Range']=='bytes 2-4/10' and h['Cache-Control'].startswith('private')
        assert request(uid=601,mode='audio',llamada='entrante:2',segmento=9)[0]==404
        assert request(uid=601,mode='audio',llamada='entrante:2',headers={'Range':'bytes=99-'})[0]==416
        assert request(uid=601,mode='audio',llamada='entrante:2',headers={'Range':'bytes=0-2,4-8'})[0]==416
        sql("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=6 AND p.codigo='llamadas.supervision.audio'")
        assert request(uid=601,mode='audio',llamada='entrante:2',headers={'Range':'bytes=0-2'})[0]==403
        assert request(uid=601,mode='detalle',llamada='entrante:2')[0]==200
        sql("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=6 AND p.codigo='llamadas.supervision.ver'")
        assert request(uid=601)[0]==403 and request(uid=601,mode='detalle',llamada='entrante:2')[0]==403
        # The session intentionally retains its old permissions: the DB decides.
        backup=pathlib.Path(folder)/'backups';cli=[php,str(R/'tools/instalar_supervision_llamadas_permisos.php'),'--site',str(root),'--backup-root',str(backup)]
        for mode in ['--check','--apply']:
            p=subprocess.run(cli+[mode],env=env,capture_output=True);assert p.returncode==0,p.stdout+p.stderr
        assert not backup.exists() and request(uid=601)[0]==403
        sql('UPDATE usuarios SET estado=0 WHERE id=20');assert request(uid=20)[0]==403
        server.terminate();server.wait(timeout=10);server=None;log.close()
        logs=(root/'server.log').read_text(errors='replace');assert 'PHP Warning' not in logs and 'PHP Fatal' not in logs,logs
        print('HTTP real: alcance, detalle, dos tramos, Range 206/416, permisos revocados con sesión antigua y CLI idempotente OK')
finally:
    if server:server.terminate();server.wait(timeout=10)
    run('tests/ventas_actividad_db.php','--cleanup')
