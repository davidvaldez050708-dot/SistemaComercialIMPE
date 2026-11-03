"""Ejecuta sólo fixtures ficticios en una BD aislada. Requiere MariaDB local."""
import os,pathlib,subprocess,uuid
root=pathlib.Path(__file__).resolve().parents[1]
env=os.environ.copy()
env.update(IMPE_DB_HOST=env.get('IMPE_TEST_MYSQL_HOST','127.0.0.1:33319'),IMPE_DB_USER=env.get('IMPE_TEST_MYSQL_USER','root'),IMPE_DB_PASSWORD=env.get('IMPE_TEST_MYSQL_PASSWORD',''),IMPE_DB_NAME='impe_test_prospectos_'+uuid.uuid4().hex[:12])
php=env.get('PHP_BINARY','C:/xampp/php/php.exe' if os.name=='nt' else 'php')
raise SystemExit(subprocess.call([php,str(root/'tests/prospectos_central_db.php')],env=env,cwd=root))
