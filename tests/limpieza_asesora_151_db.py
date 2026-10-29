"""CLI real y MariaDB desechable: limpia sólo cuenta 15/extensión 151."""
import os,pathlib,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
runner=(R/'tests/ventas_todos_cierres_db.py').read_text(encoding='utf8')
runner=runner.replace('project=pathlib.Path(folder);',"project=pathlib.Path(folder)/'site';project.mkdir();")
anchor=" fixture=fixture.replace(\"SET time_zone='-06:00'\",\"SET time_zone='+00:00'\")"
extra="""
 for path,rev in [('app/services/TelefoniaVentasLlamadasService.php','2b65829a')]:
  (project/path).write_bytes(subprocess.check_output(['git','show',rev+':'+path],cwd=root))
 (project/'index.php').write_text('<?php',encoding='utf8');(project/'tools').mkdir()
 shutil.copyfile(root/'tools/limpiar_llamadas_asesora_151.php',project/'tools/limpiar_llamadas_asesora_151.php')
 fixture=fixture.replace(needle," require __DIR__.'/limpieza_asesora_151_casos.php';\\n"+needle)
 shutil.copyfile(root/'tests/limpieza_asesora_151_casos.php',project/'tests/limpieza_asesora_151_casos.php')
 (project/'config/db_connection.php').write_text("<?php date_default_timezone_set('America/Mexico_City');class Database {public function connect($persistente=true){$name=getenv('IMPE_TEST_DATABASE');if(!str_starts_with($name,'impe_ventas_test_'))throw new RuntimeException('Solo test local');$db=new mysqli('127.0.0.1','root','',$name,33319);$db->set_charset('utf8mb4');$db->query('SET time_zone='.chr(39).'+00:00'.chr(39));return $db;}}",encoding='utf8')
"""
assert anchor in runner;runner=runner.replace(anchor,anchor+extra)
with tempfile.NamedTemporaryFile('w',suffix='.py',dir=R/'tests',encoding='utf8',delete=False) as file:file.write(runner);path=pathlib.Path(file.name)
try:subprocess.run([os.sys.executable,str(path)],cwd=R,env=dict(os.environ,PYTHONDONTWRITEBYTECODE='1'),check=True)
finally:path.unlink()
