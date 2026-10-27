"""Ejecuta las pruebas de cartera en una copia aislada; nunca carga config real."""
import os,pathlib,tempfile,shutil,subprocess,json
root=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BINARY','php')
with tempfile.TemporaryDirectory(prefix='impe-cartera-test-') as folder:
 project=pathlib.Path(folder)
 shutil.copytree(root/'app',project/'app')
 (project/'index.php').write_text('<?php',encoding='utf8')
 (project/'config').mkdir()
 (project/'config/api_keys.php').write_text('<?php',encoding='utf8')
 (project/'config/config.php').write_text('<?php',encoding='utf8')
 (project/'config/private_config.php').write_text('<?php function impeLeerConfigPrivada($name) { throw new RuntimeException("Proveedor externo bloqueado en prueba"); }',encoding='utf8')
 (project/'vendor').mkdir()
 (project/'vendor/autoload.php').write_text("<?php require '"+(root/'vendor/autoload.php').as_posix()+"';",encoding='utf8')
 (project/'config/db_connection.php').write_text('''<?php
class Database {
 public function connect($persistent=true) {
  if (isset($GLOBALS['cartera_test_db'])) return $GLOBALS['cartera_test_db'];
  $database=getenv('CARTERA_TEST_DATABASE');
  if (!preg_match('/^impe_cartera_test_[a-f0-9]+$/D',$database)) throw new RuntimeException('Base de prueba obligatoria');
  $db=new mysqli('127.0.0.1','root','',$database,33319);$db->set_charset('utf8mb4');return $db;
 }
}
''',encoding='utf8')
 env=dict(os.environ,CARTERA_TEST_PROJECT=str(project),CARTERA_TEST_SOURCE=str(root))
 subprocess.run([php,str(root/'tests/cartera_territorial_mysql.php')],env=env,check=True)
