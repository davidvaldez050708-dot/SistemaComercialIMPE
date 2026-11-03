"""Pruebas del almacén y asociaciones en un esquema desechable recién creado."""
import os,pathlib,subprocess,uuid
root=pathlib.Path(__file__).resolve().parents[1]
env=dict(os.environ,APP_ENV='testing',IMPE_DB_HOST=os.environ.get('IMPE_TEST_MYSQL_HOST','127.0.0.1:33319'),IMPE_DB_USER='root',IMPE_DB_NAME='impe_test_prospectos_campanias_'+uuid.uuid4().hex[:10],IMPE_CENTRAL_KEEP_FIXTURE='1')
php=env.get('PHP_BINARY','php')
try:
    subprocess.run([php,str(root/'tests/prospectos_central_db.php')],cwd=root,env=env,check=True)
    subprocess.run([php,str(root/'tests/prospectos_campanias_db.php')],cwd=root,env=env,check=True)
finally:
    subprocess.run([php,str(root/'tests/prospectos_central_fixture.php'),'--drop'],cwd=root,env=env,check=True)
