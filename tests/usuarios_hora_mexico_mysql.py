"""Prueba de Usuarios en una copia efímera con configuración local, sin datos reales."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='impe-usuarios-hora-') as folder:
 project=pathlib.Path(folder);shutil.copytree(root/'app',project/'app');(project/'config').mkdir();(project/'index.php').write_text('<?php',encoding='utf8')
 (project/'config/db_connection.php').write_text("<?php date_default_timezone_set('America/Mexico_City'); class Database { public function connect($persistent=true) { if (isset($GLOBALS['horarios_test_db'])) return $GLOBALS['horarios_test_db']; $n=getenv('HORARIOS_TEST_DATABASE'); if (!preg_match('/^impe_horarios_test_[a-f0-9]+$/D',$n)) throw new RuntimeException('Base local obligatoria'); $db=new mysqli('127.0.0.1','root','',$n,33319); $db->set_charset('utf8mb4');return $db; } }",encoding='utf8')
 env=dict(os.environ,HORARIOS_TEST_PROJECT=str(project),HORARIOS_TEST_SOURCE=str(root))
 subprocess.run([os.environ.get('PHP_BINARY','php'),str(root/'tests/usuarios_hora_mexico_mysql.php')],env=env,check=True)
