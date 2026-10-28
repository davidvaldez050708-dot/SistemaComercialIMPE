"""Amplía los escenarios PBX existentes en una copia con conexión local obligatoria."""
import io,os,pathlib,shutil,subprocess,tarfile,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='impe-ventas-whatsapp-') as folder:
 project=pathlib.Path(folder);(project/'config').mkdir();(project/'tests').mkdir()
 # Snapshot publicado sin configuración ni datos: no usa credenciales del CRM.
 with tarfile.open(fileobj=io.BytesIO(subprocess.check_output(['git','archive','08451cb7','app'],cwd=root))) as snapshot:
  snapshot.extractall(project,filter='data')
 for name in ['TelefoniaResultadoVentasService.php','TelefoniaDevolucionVentasService.php','TelefoniaVentasLlamadasService.php']:
  shutil.copyfile(root/'app/services'/name,project/'app/services'/name)
 for path in ['app/helpers/VentasPendientesPolicy.php','app/services/VentasActividadMetricas.php']:
  (project/path).write_bytes(subprocess.check_output(['git','show','77d57ada:'+path],cwd=root))
 for path in ['app/services/TelefoniaLimpiezaGuard.php','app/services/TelefoniaMarcacionesVentasService.php']:
  (project/path).write_bytes(subprocess.check_output(['git','show','6ba06aa6:'+path],cwd=root))
 (project/'config/db_connection.php').write_text("<?php class Database { public function connect($persistent=true) { throw new RuntimeException('Usar exclusivamente la conexión local explícita'); } }",encoding='utf8')
 fixture=(root/'tests/telefonia_ventas_db.php').read_text(encoding='utf8')
 needle=' // Reasignación: el tramo congelado conserva su propietario histórico.'
 assert fixture.count(needle)==1
 fixture=fixture.replace(needle," require __DIR__.'/ventas_whatsapp_casos.php';\n require __DIR__.'/ventas_todos_cierres_casos.php';\n"+needle)
 fixture=fixture.replace("SET time_zone='-06:00'","SET time_zone='+00:00'")
 (project/'tests/telefonia_ventas_db.php').write_text(fixture,encoding='utf8')
 shutil.copyfile(root/'tests/ventas_whatsapp_casos.php',project/'tests/ventas_whatsapp_casos.php')
 shutil.copyfile(root/'tests/ventas_todos_cierres_casos.php',project/'tests/ventas_todos_cierres_casos.php')
 env=dict(os.environ,IMPE_TEST_MYSQL_PORT='33319',IMPE_TEST_MYSQL_USER='root',IMPE_TEST_MYSQL_PASSWORD='')
 subprocess.run([os.environ.get('PHP_BINARY','php'),str(project/'tests/telefonia_ventas_db.php')],env=env,check=True)
