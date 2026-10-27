"""HTTP y SQL reales sobre una base efímera; sin configurar ni iniciar MySQL local."""
import json, os, pathlib, re, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request, uuid

R=pathlib.Path(__file__).resolve().parents[1]
env=os.environ.copy();port_db=env.get('IMPE_TEST_MYSQL_PORT')
assert port_db,'Usar únicamente el servicio MySQL de CI con IMPE_TEST_MYSQL_PORT'
env['IMPE_VENTAS_TEST_DB']='impe_ventas_reportes_test_'+uuid.uuid4().hex[:8]
php=env.get('PHP_BINARY','php')
def fixture(mode):
    p=subprocess.run([php,str(R/'tests/ventas_actividad_db.php'),mode],env=env,capture_output=True)
    assert p.returncode==0,p.stdout.decode(errors='replace')+p.stderr.decode(errors='replace')
    print(p.stdout.decode().strip())
server=None
try:
    fixture('--setup')
    with tempfile.TemporaryDirectory() as folder:
        root=pathlib.Path(folder)/'sitio';root.mkdir()
        (root/'index.php').write_text('<?php',encoding='utf8')
        shutil.copytree(R/'app',root/'app')
        shutil.copytree(R/'vendor',root/'vendor')
        for sub in ['css','javascript','img/brand']:
            shutil.copytree(R/'public'/sub,root/'public'/sub)
        (root/'config').mkdir()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        url=f'http://127.0.0.1:{port}/'
        (root/'config/db_connection.php').write_text('''<?php
        defined('ROOT_PATH') || define('ROOT_PATH',dirname(__DIR__));
        defined('BASE_URL') || define('BASE_URL',getenv('IMPE_TEST_ROOT_URL'));
        date_default_timezone_set('America/Mexico_City');
        class Database {
            public function connect(bool $persistente=true) {
                $db=new mysqli('127.0.0.1','root','',getenv('IMPE_VENTAS_TEST_DB'),(int)getenv('IMPE_TEST_MYSQL_PORT'));
                $db->set_charset('utf8mb4');$db->query("SET time_zone='-06:00'");return $db;
            }
        }
        ''',encoding='utf8')
        env['IMPE_TEST_ROOT_URL']=url
        for name in ['dashboard_head.php','sidebar.php','topbar.php']:
            (root/'app/views/layout'/name).write_text('<!doctype html><meta charset="utf-8">' if name=='dashboard_head.php' else '',encoding='utf8')
        (root/'app/views/layout/dashboard_footer.php').write_text('',encoding='utf8')
        (root/'router.php').write_text('''<?php
        require __DIR__.'/config/db_connection.php';
        $db=(new Database())->connect();
        $role=(int)($_GET['role']??3);$uid=[1=>10,2=>20,3=>301,6=>601][$role]??0;
        $perms=[];$rs=$db->query('SELECT p.codigo FROM rol_permisos r JOIN permisos p ON p.id=r.permiso_id WHERE r.rol_id='.$role.' AND p.estado=1');
        while($p=$rs->fetch_assoc())$perms[]=$p['codigo'];
        $_SESSION=['usuario_id'=>$uid,'rol_id'=>$role,'rol'=>'Prueba','nombre'=>'Cuenta','apellidos'=>'Prueba','permisos'=>$perms];
        if(($_GET['test']??'')==='revoke') {
            $db->query("DELETE r FROM rol_permisos r JOIN permisos p ON p.id=r.permiso_id WHERE r.rol_id=3 AND p.codigo='reportes.ventas.propio'");echo 'Revocado';return;
        }
        $controller=$_GET['controller']??'reporte';$action=$_GET['action']??'ventas';
        if($controller==='reporte' && in_array($action,['index','ventas','ventasPdf'],true)) {require __DIR__.'/app/controllers/ReporteController.php';(new ReporteController())->$action();}
        elseif($controller==='desempeno' && in_array($action,['index','exportarPdf'],true)) {require __DIR__.'/app/controllers/DesempenoController.php';(new DesempenoController())->$action();}
        else {http_response_code(404);}
        ''',encoding='utf8')
        log=open(root/'server.log','wb')
        server=subprocess.Popen([php,'-d','opcache.enable_cli=0','-d','opcache.jit_buffer_size=0','-S',f'127.0.0.1:{port}',str(root/'router.php')],cwd=root,env=env,stdout=log,stderr=log)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2): break
            except OSError:time.sleep(.05)
        def request(**params):
            defaults=dict(controller='reporte',action='ventas',periodo='hoy',generar=1,role=3)
            defaults.update(params)
            try:
                with urllib.request.urlopen(url+'?'+urllib.parse.urlencode(defaults),timeout=60) as r:return r.status,dict(r.headers),r.read()
            except urllib.error.HTTPError as e:return e.code,dict(e.headers),e.read()
        code,headers,body=request()
        assert code==200,body.decode(errors='replace')
        text=body.decode();assert 'performance-view-tabs' not in text
        assert 'Asesor Propio' in text and 'Asesor Ajeno' not in text
        assert '00:00:25' in text and '13:00:00' in text and 'data-sales-report-pdf' in text
        assert 'Warning:' not in text and 'Fatal error' not in text
        assert request(action='index')[0]==200
        for role in [1,2,6]:
            code,_,body=request(role=role)
            assert code==200,body.decode(errors='replace')
            assert ('Asesor Ajeno' in body.decode())==(role in [1,2])
        for endpoint in ['ventas','ventasPdf']:
            assert request(action=endpoint,persona_id=302)[0]==403
            assert request(action=endpoint,persona_id=302,role=6)[0]==403
        assert request(periodo='personalizado',fecha_desde='2026-02-30',fecha_hasta='2026-03-01')[0]==400
        assert request(periodo='personalizado',fecha_desde='2024-01-01',fecha_hasta='2024-01-31')[0]==200
        assert request(resultado='INTERESADO')[0]==200
        assert request(direccion='desconocida')[0]==400
        for role in [1,2,3,6]:
            code,_,body=request(controller='desempeno',action='index',area='asesores',role=role)
            text=body.decode();assert code==200,text
            assert 'data-performance-filter-form' in text and 'data-sales-activity-form' not in text
            assert 'Asesor Ajeno' not in text or role in [1,2]
            assert ('performance-index' in text)==(role!=3)
            assert request(controller='desempeno',action='index',area='asesores',role=role,persona_id=302)[0]==(200 if role in [1,2] else 403)
        assert request(controller='desempeno',action='index',area='asesores',role=2,estado_id=2)[0]==200
        assert request(controller='desempeno',action='index',area='asesores',role=6,estado_id=2)[0]==200
        assert request(controller='desempeno',action='index',area='asesores',role=3,estado_id=999)[0]==200
        baseline=request(controller='desempeno',action='index',area='asesores',role=2)[2]
        legacy=request(controller='desempeno',action='index',area='asesores',role=2,estado_id=999)[2]
        assert baseline.split(b'<div class="performance-main-grid',1)[1]==legacy.split(b'<div class="performance-main-grid',1)[1],'El estado de una URL antigua no filtra el desempeño'
        assert b'name="estado_id"' not in legacy
        assert request(controller='desempeno',action='exportarPdf',area='asesores',role=6,estado_id=999,persona_id=302)[0]==403
        for controller,action in [('reporte','ventasPdf'),('desempeno','exportarPdf')]:
            code,headers,pdf=request(controller=controller,action=action,area='asesores')
            assert code==200 and pdf.startswith(b'%PDF-'),(pdf[:1200],(root/'server.log').read_text(errors='replace'))
            assert 'application/pdf' in headers.get('Content-Type','')
            assert 'private' in headers.get('Cache-Control','')
        request(test='revoke')
        assert request()[0]==403 and request(action='ventasPdf')[0]==403
        permission_backup=pathlib.Path(folder)/'respaldos-permisos'
        cli=[php,str(R/'tools/instalar_reportes_asesores_permisos.php'),'--site',str(root),'--backup-root',str(permission_backup)]
        for mode in ['--check','--apply']:
            p=subprocess.run(cli+[mode],env=env,capture_output=True)
            assert p.returncode==0 and b'Warning:' not in p.stdout+p.stderr,p.stdout+p.stderr
        assert not permission_backup.exists(),'Reinstalar no crea un respaldo vacío ni vuelve a conceder permisos'
        assert request()[0]==403,'CLI real conserva permisos revocados'
        server.terminate();server.wait(timeout=10);server=None;log.close()
        logs=(root/'server.log').read_text(errors='replace')
        assert 'PHP Warning' not in logs and 'PHP Fatal' not in logs,logs
        print('HTTP real: cuatro roles, filtros, catálogo, PDF personal y Desempeño, acceso ajeno y revocación entre solicitudes OK')
finally:
    if server:server.terminate();server.wait(timeout=10)
    fixture('--cleanup')
