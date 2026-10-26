"""Inicio real con SQL efímero y configuración aislada; sin proveedor telefónico."""
import json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.parse,urllib.error,uuid
R=pathlib.Path(__file__).resolve().parents[1]
env=os.environ.copy();assert env.get('IMPE_TEST_MYSQL_PORT'),'Indica un servicio SQL de pruebas'
env['IMPE_VENTAS_TEST_DB']='impe_ventas_reportes_test_'+uuid.uuid4().hex[:8]
php=env.get('PHP_BINARY','php');server=None
with tempfile.TemporaryDirectory() as folder:
    root=pathlib.Path(folder)/'site';root.mkdir()
    for sub in ['app','tests','public/css']:shutil.copytree(R/sub,root/sub)
    (root/'config').mkdir();(root/'index.php').write_text('<?php')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env['IMPE_TEST_ROOT_URL']=f'http://127.0.0.1:{port}/'
    (root/'config/db_connection.php').write_text('''<?php
    defined('ROOT_PATH')||define('ROOT_PATH',dirname(__DIR__));defined('BASE_URL')||define('BASE_URL',getenv('IMPE_TEST_ROOT_URL'));
    date_default_timezone_set('America/Mexico_City');
    class Database {public function connect(bool $persistent=true){$db=new mysqli('127.0.0.1','root','',getenv('IMPE_VENTAS_TEST_DB'),(int)getenv('IMPE_TEST_MYSQL_PORT'));$db->set_charset('utf8mb4');$db->query("SET time_zone='-06:00'");return $db;}}
    ''',encoding='utf8')
    def cli(file,*args):
        p=subprocess.run([php,str(root/file),*args],env=env,capture_output=True)
        assert p.returncode==0,p.stdout.decode(errors='replace')+p.stderr.decode(errors='replace')
        return p.stdout
    try:
        print(cli('tests/director_inicio_db.php').decode().strip())
        for name in ['dashboard_head.php','sidebar.php','topbar.php','dashboard_footer.php']:(root/'app/views/layout'/name).write_text('')
        (root/'router.php').write_text('''<?php
        require __DIR__.'/config/db_connection.php';
        $_SESSION=['usuario_id'=>(int)($_GET['uid']??20),'rol_id'=>2,'rol'=>'Director de Operaciones','nombre'=>'Directora <img src=x onerror=alert(1)>','permisos'=>['usuarios.ver','territorios.ver','reportes.ver','reportes.ventas','reportes.ventas.global']];
        if(isset($_GET['datos'])){require __DIR__.'/app/services/DirectorOperacionesDashboardService.php';header('Content-Type: application/json');echo json_encode((new DirectorOperacionesDashboardService())->obtener(20));}
        else{require __DIR__.'/app/controllers/HomeController.php';(new HomeController())->index();}
        ''',encoding='utf8')
        log=open(root/'server.log','wb');server=subprocess.Popen([php,'-d','opcache.enable_cli=0','-d','opcache.jit_buffer_size=0','-S',f'127.0.0.1:{port}',str(root/'router.php')],cwd=root,env=env,stdout=log,stderr=log)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2):break
            except OSError:time.sleep(.05)
        def request(**params):
            try:
                with urllib.request.urlopen(env['IMPE_TEST_ROOT_URL']+'?'+urllib.parse.urlencode(params),timeout=30) as r:return r.status,r.read()
            except urllib.error.HTTPError as e:return e.code,e.read()
        def sql(statement):
            p=subprocess.run([php,'-r','require $argv[1];(new Database())->connect(false)->query($argv[2]);',str(root/'config/db_connection.php'),statement],env=env,capture_output=True)
            assert p.returncode==0,p.stderr
        code,body=request();text=body.decode()
        assert code==200,text
        assert 'data-director-dashboard' in text and 'Agenda nacional' in text and 'Supervisión de llamadas' in text
        assert 'Ver los 32 estados' in text and 'Analista Ajeno' in text
        assert '<script>hostil</script>' not in text and '&lt;script&gt;hostil&lt;/script&gt;' in text
        assert '<img src=x onerror=alert(1)>' not in text
        assert 'reunion_id=1' in text and 'mes=' in text
        assert 'Warning:' not in text and 'Fatal error' not in text
        assert json.loads(request(datos=1,estado_id=999,persona_id=302)[1])['resumen']['territorios']==32
        for uid in [0,10,301,401,601]:assert request(uid=uid)[0]==403
        sql("UPDATE permisos SET estado=0 WHERE codigo='territorios.ver'")
        assert 'Territorios y equipo asignado' not in request()[1].decode()
        sql('DELETE FROM rol_permisos WHERE rol_id=2')
        text=request()[1].decode();assert 'Aún no tienes módulos habilitados' in text
        assert 'Institución 1' not in text and 'Actividad telefónica del equipo' not in text and 'Estado 32' not in text
        sql('UPDATE usuarios SET estado=0 WHERE id=20');assert request()[0]==403
        server.terminate();server.wait(timeout=10);server=None;log.close()
        logs=(root/'server.log').read_text(errors='replace');assert 'PHP Warning' not in logs and 'PHP Fatal' not in logs,logs
        print('Inicio por HTTP: HomeController real, datos nacionales, permisos revocados con sesión antigua, enlaces y texto seguro OK')
    finally:
        if server:server.terminate();server.wait(timeout=10)
        cli('tests/ventas_actividad_db.php','--cleanup')
