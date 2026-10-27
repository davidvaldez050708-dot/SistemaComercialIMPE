"""Prospectos con datos sintéticos y una conexión local aislada."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='impe-prospectos-hora-') as folder:
 project=pathlib.Path(folder);shutil.copytree(root/'app',project/'app');(project/'config').mkdir();(project/'index.php').write_text('<?php',encoding='utf8')
 (project/'config/db_connection.php').write_text("<?php class Database { public function connect($persistent=true) { if(isset($GLOBALS['prospectos_hora_db'])) return $GLOBALS['prospectos_hora_db']; $n=getenv('PROSPECTOS_HORA_DATABASE'); if(!preg_match('/^impe_prospectos_hora_[a-f0-9]+$/D',$n)) throw new RuntimeException('Base local obligatoria'); return new mysqli('127.0.0.1','root','',$n,33319); } }",encoding='utf8')
 env=dict(os.environ,PROSPECTOS_HORA_PROJECT=str(project),PROSPECTOS_HORA_SOURCE=str(root))
 subprocess.run([os.environ.get('PHP_BINARY','php'),str(root/'tests/prospectos_hora_mexico_mysql.php')],env=env,check=True)
