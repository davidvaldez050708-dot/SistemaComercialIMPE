"""CLI y archivos privados reales; SQL efímero, sin leer configuración privada del CRM."""
import gzip,json,os,pathlib,shutil,stat,subprocess,tempfile,uuid
R=pathlib.Path(__file__).resolve().parents[1]
env=os.environ.copy();assert env.get('IMPE_TEST_MYSQL_PORT'),'Indicar sólo el puerto del SQL de pruebas'
env['IMPE_LIMPIEZA_TEST_DB']='impe_limpieza_test_'+uuid.uuid4().hex[:10]
php=env.get('PHP_BINARY','php')
def fixture(mode):
    p=subprocess.run([php,str(R/'tests/limpieza_llamadas_db.php'),mode],env=env,capture_output=True)
    assert p.returncode==0,p.stdout+p.stderr
try:
    fixture('--setup')
    with tempfile.TemporaryDirectory() as tmp:
        folder=pathlib.Path(tmp);site=folder/'sitio';site.mkdir();(site/'index.php').write_text('<?php')
        shutil.copytree(R/'app',site/'app');(site/'config').mkdir()
        (site/'config/db_connection.php').write_text('''<?php
        date_default_timezone_set('America/Mexico_City');
        class Database {public function connect(bool $persistent=true){
          $db=new mysqli('127.0.0.1','root','',getenv('IMPE_LIMPIEZA_TEST_DB'),(int)getenv('IMPE_TEST_MYSQL_PORT'));
          $db->set_charset('utf8mb4');$db->query("SET time_zone='-06:00'");return $db;
        }}''',encoding='utf8')
        private=folder/'privado';command=[php,str(R/'tools/limpiar_llamadas_prueba.php'),'--site',str(site),'--backup-root',str(private)]
        def run(*args,ok=True):
            p=subprocess.run(command+list(args),env=env,capture_output=True)
            assert (p.returncode==0)==ok,p.stdout+p.stderr
            assert b'Warning:' not in p.stdout+p.stderr,p.stdout+p.stderr
            return p.stdout.decode('utf8')
        original={p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
        preview=json.loads(run('--check'));backup=pathlib.Path(preview['plan_privado']);data=gzip.decompress(backup.read_bytes());saved=json.loads(data)
        assert 'CHECK OK' in preview['estado'] and preview['registros']['telefonia_ventas_llamadas']['filas']==1
        assert '5550000000' not in json.dumps(preview),'El resumen no contiene teléfonos'
        assert original=={p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()},'Check no escribe en el sitio'
        if os.name!='nt':assert stat.S_IMODE(private.stat().st_mode)==0o700 and stat.S_IMODE(backup.stat().st_mode)==0o600
        altered=site/'app/services/TelefoniaVentasLlamadasService.php';before=altered.read_bytes();altered.write_bytes(before+b'\n// Edicion posterior')
        run('--apply',str(backup),ok=False);altered.write_bytes(before)
        assert 'APLICADO' in run('--apply',str(backup));assert 'APLICADO' in run('--apply',str(backup))
        assert 'RESTAURADO' in run('--restore',str(backup))
        bad=folder/'alterado.json.gz';bad.write_bytes(backup.read_bytes());run('--restore',str(bad),ok=False)
        inside=site/'no-debe-crearse'
        p=subprocess.run([php,str(R/'tools/limpiar_llamadas_prueba.php'),'--site',str(site),'--backup-root',str(inside),'--check'],env=env,capture_output=True)
        assert p.returncode!=0 and not inside.exists(),'Rechaza respaldo público antes de crear carpeta'
        assert backup.read_bytes() and gzip.decompress(backup.read_bytes())==data,'Respaldo original intacto'
        print('CLI: check sin escrituras en CRM, resumen privado, protección, backup, aplicación, repetición, restauración y rutas OK')
finally:fixture('--cleanup')
