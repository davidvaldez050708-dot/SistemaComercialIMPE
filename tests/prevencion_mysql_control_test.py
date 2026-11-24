import json,os,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
PHP='C:/xampp/php/php.exe' if Path('C:/xampp/php/php.exe').exists() else 'php'
with tempfile.TemporaryDirectory() as tmp:
    site=Path(tmp)/'public_html';helpers=site/'app/helpers';helpers.mkdir(parents=True)
    for name in ['ControlCargaMysql.php','CatalogoPermisosInicializador.php']:
        shutil.copyfile(ROOT/'app/helpers'/name,helpers/name)
    unit=site/'unit.php'
    unit.write_text('''<?php
require __DIR__.'/app/helpers/CatalogoPermisosInicializador.php';
function ok($v,$m){if(!$v)throw new RuntimeException($m);}
$dir=__DIR__.'/cache';$source=__DIR__.'/catalogo.php';file_put_contents($source,'version1');
$n=0;$now=1000;$install=function()use(&$n){$n++;};
$run=function()use($install,$source,$dir,&$now){ImpeCatalogoPermisosInicializador::ejecutar($install,[$source],$dir,fn()=>$now);};
$run();$run();ok($n===1,'Una inicialización por versión');
$now=1001;file_put_contents($source,'version2');$run();ok($n===2,'Despliegue invalida el catálogo');
$now=23000;$run();ok($n===3,'Caducidad controlada');
$now=50000;$file=glob($dir.'/*.json.lock')[0];$lock=fopen($file,'c');flock($lock,LOCK_EX);
$blocked=false;try{$run();}catch(RuntimeException $e){$blocked=$e->getCode()===503;}
ok($blocked&&$n===3,'No duplicar inicialización concurrente');flock($lock,LOCK_UN);fclose($lock);$run();ok($n===4,'Reanudación tras bloqueo');
$failed=false;try{ImpeCatalogoPermisosInicializador::ejecutar(function(){throw new RuntimeException('sintético');},[$source],__DIR__.'/fallo',fn()=>1000);}catch(RuntimeException $e){$failed=true;}
ok($failed&&!glob(__DIR__.'/fallo/*.json'),'No guardar éxito si falla inicialización');
class MysqlSinRed extends mysqli{public function __construct(){}}
class Database{public static int $aperturas=0;public static function compartida(){self::$aperturas++;return new MysqlSinRed();}}
$a=ImpeControlCargaMysql::conexion();$b=ImpeControlCargaMysql::conexion();ok($a===$b&&Database::$aperturas===1,'Una conexión por petición');
echo "PASS: catálogo por versión, caducidad, bloqueo concurrente, fallos sin caché y conexión única.\\n";
''',encoding='utf-8')
    r=subprocess.run([PHP,str(unit)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert r.returncode==0,(r.stdout,r.stderr)
    print(r.stdout.decode().strip())
    (site/'fallar.php').write_text("<?php require __DIR__.'/app/helpers/ControlCargaMysql.php';ImpeControlCargaMysql::registrarFallo(new RuntimeException('sintético',(int)($_GET['code']??0)));echo 'OK';",encoding='utf-8')
    (site/'doble.php').write_text("<?php require __DIR__.'/app/helpers/ControlCargaMysql.php';$causa=new RuntimeException('sintético',2002);ImpeControlCargaMysql::registrarFallo($causa);ImpeControlCargaMysql::registrarFallo(new RuntimeException('envoltura',0,$causa));ImpeControlCargaMysql::registrarFallo($causa);echo 'OK';",encoding='utf-8')
    (site/'estado.php').write_text("<?php require __DIR__.'/app/helpers/ControlCargaMysql.php';ImpeControlCargaMysql::comprobar();echo 'OK';",encoding='utf-8')
    (site/'cli.php').write_text("<?php require __DIR__.'/app/helpers/ControlCargaMysql.php';try{ImpeControlCargaMysql::comprobar(false);}catch(ImpeMysqlCircuitoAbierto $e){ImpeControlCargaMysql::registrarFallo($e);echo 'NO_INTENTADO';}",encoding='utf-8')
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    server=subprocess.Popen([PHP,'-S','127.0.0.1:'+str(port),'-t',str(site)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    try:
        for _ in range(30):
            try:c=socket.create_connection(('127.0.0.1',port),.1);c.close();break
            except OSError:time.sleep(.05)
        def request(path):
            try:r=urllib.request.urlopen('http://127.0.0.1:'+str(port)+path,timeout=3)
            except urllib.error.HTTPError as e:r=e
            return r.status,r.headers,r.read()
        circuit=Path(tmp)/'impe-private/mysql-circuito.json'
        for wait in [1,2,4]:
            assert request('/fallar.php?code=2002')[0]==200
            data=json.loads(circuit.read_text());assert data['hasta']-data['ultimo']==wait
        request('/fallar.php?code=1142');data=json.loads(circuit.read_text());assert data['hasta']-data['ultimo']==120
        status,headers,body=request('/estado.php');assert status==503 and int(headers['Retry-After'])>0 and json.loads(body)['ok'] is False
        before=circuit.read_bytes();request('/fallar.php?code=0');assert circuit.read_bytes()==before
        result=subprocess.run([PHP,str(site/'cli.php')],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        assert result.returncode==0 and result.stdout==b'NO_INTENTADO' and circuit.read_bytes()==before
        data['hasta']=1;circuit.write_text(json.dumps(data));assert request('/estado.php')[2]==b'OK'
        circuit.write_text('{}');assert request('/doble.php')[0]==200
        data=json.loads(circuit.read_text());assert data['fallos']==1 and data['hasta']-data['ultimo']==1
        # Mantener la ventana activa durante las comprobaciones, sin esperar ni consultar MySQL.
        data['hasta']=int(time.time())+60;circuit.write_text(json.dumps(data));before=circuit.read_bytes()
        for route in ['login&action=mostrarLogin','home&action=index','telefonia&action=shell','telefonia&action=detalleVenta','seguimientoVinculacion&action=registrarInteraccionTrabajo']:
            assert request('/estado.php?controller='+route)[0]==200,route
        assert request('/estado.php?controller=reminder&action=pendientes')[0]==503
        req=urllib.request.Request('http://127.0.0.1:'+str(port)+'/estado.php?controller=telefonia&action=guardarResultadoVenta',data=b'prueba=1')
        with urllib.request.urlopen(req,timeout=3) as respuesta:assert respuesta.status==200 and respuesta.read()==b'OK'
        assert circuit.read_bytes()==before
        # El rechazo de privilegios conserva la protección general y una pantalla HTML.
        request('/fallar.php?code=1142');before=circuit.read_bytes()
        req=urllib.request.Request('http://127.0.0.1:'+str(port)+'/estado.php',headers={'Accept':'text/html'})
        try:r=urllib.request.urlopen(req,timeout=3)
        except urllib.error.HTTPError as e:r=e
        assert r.status==503 and 'text/html' in r.headers['Content-Type'] and b'<main>' in r.read()
        assert circuit.read_bytes()==before
    finally:server.terminate();server.wait(timeout=5)
print('PASS: una causa se cuenta una vez; 2002 frena avisos sin bloquear operacion humana; 1142 mantiene proteccion general; HTML/JSON y Retry-After. Sin MySQL real.')
