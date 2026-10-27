"""API real y SQL efímero: no conecta a Hostinger ni al proveedor telefónico."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.parse, urllib.error, uuid
R = pathlib.Path(__file__).resolve().parents[1]
env = os.environ.copy()
assert env.get('IMPE_TEST_MYSQL_PORT'), 'Indica servicio SQL aislado'
env['IMPE_VENTAS_TEST_DB'] = 'impe_ventas_reportes_test_' + uuid.uuid4().hex[:8]
php = env.get('PHP_BINARY', 'php')
server = None

def run(file, *args):
    p = subprocess.run([php, str(R / file), *args], env=env, capture_output=True)
    assert p.returncode == 0, p.stdout.decode(errors='replace') + p.stderr.decode(errors='replace')
    return p.stdout

try:
    print(run('tests/ventas_observaciones_db.php').decode().strip())
    with tempfile.TemporaryDirectory() as folder:
        root = pathlib.Path(folder)
        shutil.copytree(R / 'app', root / 'app')
        (root / 'index.php').write_text('<?php')
        (root / 'config').mkdir()
        (root / 'config/db_connection.php').write_text('''<?php
        defined('ROOT_PATH')||define('ROOT_PATH',dirname(__DIR__));defined('BASE_URL')||define('BASE_URL','http://localhost/');
        date_default_timezone_set('America/Mexico_City');
        class Database {public function connect(bool $persistent=true){$db=new mysqli('127.0.0.1','root','',getenv('IMPE_VENTAS_TEST_DB'),(int)getenv('IMPE_TEST_MYSQL_PORT'));$db->set_charset('utf8mb4');return $db;}}
        ''', encoding='utf8')
        (root / 'router.php').write_text('''<?php
        require __DIR__.'/config/db_connection.php';require __DIR__.'/app/controllers/TelefoniaController.php';
        $db=(new Database())->connect(false);$uid=(int)($_GET['uid']??301);
        $user=$db->query('SELECT rol_id FROM usuarios WHERE id='.$uid)->fetch_assoc();$role=(int)($user['rol_id']??0);
        $perms=array_column($db->query('SELECT p.codigo FROM permisos p JOIN rol_permisos rp ON rp.permiso_id=p.id WHERE p.estado=1 AND rp.rol_id='.$role)->fetch_all(MYSQLI_ASSOC),'codigo');
        $_SESSION=['usuario_id'=>$uid,'rol_id'=>$role,'permisos'=>$perms,'csrf_token'=>'fixture-observaciones-token'];
        class NotesFixtureController extends TelefoniaController {public function __construct(){}}
        $c=new NotesFixtureController();
        if(($_GET['action']??'observacionesVenta')==='agregarObservacionVenta')$c->agregarObservacionVenta();else $c->observacionesVenta();
        ''', encoding='utf8')

        def sql(statement):
            p = subprocess.run([php, '-r', 'require $argv[1];(new Database())->connect(false)->query($argv[2]);', str(root / 'config/db_connection.php'), statement], env=env, capture_output=True)
            assert p.returncode == 0, p.stderr

        # Instalador privado: comprobar/crear en otro esquema vacío y repetir
        # sobre las notas reales de fixture sin perder ni modificar registros.
        private = root / 'private'; private.mkdir()
        shutil.copy(R / 'tools/instalar_observaciones_ventas.php', private)
        shutil.copy(R / 'database/migrations/2026_10_26_ventas_observaciones_adicionales.sql', private / 'ventas_observaciones.sql')
        install = [php, str(private / 'instalar_observaciones_ventas.php'), '--site', str(root)]
        def installer(option, other_env=env, ok=True):
            p = subprocess.run(install + [option], env=other_env, capture_output=True)
            assert (p.returncode == 0) == ok, p.stdout + p.stderr
            return p.stdout.decode()
        assert 'ACTUALIZADO' in installer('--check')
        assert 'ACTUALIZADO' in installer('--apply')
        clone = 'impe_ventas_reportes_test_' + uuid.uuid4().hex[:8]
        clone_env = dict(env, IMPE_VENTAS_TEST_DB=clone)
        sql(f'CREATE DATABASE `{clone}`')
        try:
            for table in ['usuarios', 'telefonia_ventas_resultados', 'telefonia_ventas_llamadas']:
                sql(f'CREATE TABLE `{clone}`.`{table}` LIKE `{table}`')
            assert 'sin escrituras' in installer('--check', clone_env)
            assert 'APLICADO' in installer('--apply', clone_env)
            assert 'ACTUALIZADO' in installer('--apply', clone_env)
            sql(f'ALTER TABLE `{clone}`.telefonia_ventas_observaciones ADD otra_columna INT')
            installer('--apply', clone_env, ok=False)
        finally:
            sql(f'DROP DATABASE `{clone}`')
        original_sql = (private / 'ventas_observaciones.sql').read_bytes()
        (private / 'ventas_observaciones.sql').write_bytes(original_sql + b'\n-- edicion')
        installer('--apply', ok=False)
        (private / 'ventas_observaciones.sql').write_bytes(original_sql)

        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        log = open(root / 'server.log', 'wb')
        server = subprocess.Popen([php, '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', str(root / 'router.php')], cwd=root, env=env, stdout=log, stderr=log)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.2): break
            except OSError: time.sleep(.05)

        def request(uid=301, action='observacionesVenta', method='GET', body=None, **query):
            url = f'http://127.0.0.1:{port}/?' + urllib.parse.urlencode(dict(uid=uid, action=action, **query))
            req = urllib.request.Request(url, method=method, data=urllib.parse.urlencode(body).encode() if body is not None else None)
            try:
                with urllib.request.urlopen(req, timeout=15) as r: return r.status, json.load(r)
            except urllib.error.HTTPError as e: return e.code, json.load(e)

        identity = dict(pbx_call_id='report-call-direct', extension='150')
        payload = dict(identity, csrf_token='fixture-observaciones-token', nota='Nota adicional por HTTP', token='d' * 32)
        assert request(**identity)[0] == 200
        assert request(uid=302, **identity)[0] == 403
        assert request(uid=0, **identity)[0] == 401
        assert request(action='agregarObservacionVenta', **identity)[0] == 405
        assert request(action='agregarObservacionVenta', method='POST', body=dict(payload, csrf_token='otro'))[0] == 403
        assert request(uid=20, action='agregarObservacionVenta', method='POST', body=payload)[0] == 403
        # Un POST manipulado no puede alterar los campos del resultado ni su dueño.
        status, body = request(action='agregarObservacionVenta', method='POST', body=dict(payload, resultado='BUZON_VOZ', observacion='CAMBIO', usuario_id=302))
        assert status == 200, body
        saved = body['observacion']
        assert request(action='agregarObservacionVenta', method='POST', body=payload)[1]['observacion'] == saved
        own = request(**identity)[1]['observaciones']
        assert len(own) == 2 and own[-1] == saved
        for uid in [10, 20, 601]:
            status, body = request(uid=uid, llamada='venta:1'); assert status == 200 and body['observaciones'] == own, body
        assert request(uid=602, llamada='venta:1')[0] == 403
        sql("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=6 AND p.codigo='llamadas.supervision.ver'")
        assert request(uid=601, llamada='venta:1')[0] == 403
        sql("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=3 AND p.codigo='telefonia.usar'")
        assert request(**identity)[0] == 403
        assert request(action='agregarObservacionVenta', method='POST', body=dict(payload, token='e' * 32))[0] == 403
        sql("INSERT INTO rol_permisos SELECT 3,id FROM permisos WHERE codigo='telefonia.usar'")
        assert request(**identity)[0] == 200
        # Se verifica el resultado original después del intento de manipulación.
        check = subprocess.run([php, '-r', 'require $argv[1];$r=(new Database())->connect(false)->query("SELECT resultado,observacion FROM telefonia_ventas_resultados WHERE pbx_call_id=\'report-call-direct\'")->fetch_assoc();echo json_encode($r);', str(root / 'config/db_connection.php')], env=env, capture_output=True)
        result = json.loads(check.stdout)
        assert result['resultado'] != 'BUZON_VOZ' and result['observacion'] != 'CAMBIO', result
        print('Observaciones HTTP: sesión, POST/CSRF, autor, lectura propia/equipo/nacional, revocación, reintentos y resultado intacto OK')
        server.terminate(); server.wait(timeout=10); server = None; log.close()
finally:
    if server: server.terminate(); server.wait(timeout=10)
    run('tests/ventas_actividad_db.php', '--cleanup')
