"""Prueba el diagnóstico en MariaDB local y datos desechables, sin configuración real."""
import os,pathlib,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
runner=(R/'tests/ventas_todos_cierres_db.py').read_text(encoding='utf8')
needle=' // Reasignación: el tramo congelado conserva su propietario histórico.'
anchor=" fixture=fixture.replace(\"SET time_zone='-06:00'\",\"SET time_zone='+00:00'\")"
extra="""
 shutil.copyfile(root/'app/services/TelefoniaMarcacionesVentasService.php',project/'app/services/TelefoniaMarcacionesVentasService.php')
 (project/'tools').mkdir();shutil.copyfile(root/'tools/diagnosticar_historial_ventas.php',project/'tools/diagnosticar_historial_ventas.php')
 fixture=fixture.replace(needle," require __DIR__.'/ventas_historial_diagnostico_casos.php';\\n"+needle)
 shutil.copyfile(root/'tests/ventas_historial_diagnostico_casos.php',project/'tests/ventas_historial_diagnostico_casos.php')
 (project/'config/db_connection.php').write_text("<?php class Database {public function connect($persistente=true){$name=getenv('IMPE_TEST_DATABASE');if(!str_starts_with($name,'impe_ventas_test_'))throw new RuntimeException('Solo test local');$db=new mysqli('127.0.0.1','root','',$name,33319);$db->query('SET time_zone='.chr(39).'+00:00'.chr(39));return $db;}}",encoding='utf8')
"""
assert anchor in runner;runner=runner.replace(anchor,anchor+extra)
with tempfile.NamedTemporaryFile('w',suffix='.py',dir=R/'tests',encoding='utf8',delete=False) as file:file.write(runner);path=pathlib.Path(file.name)
try:subprocess.run([os.sys.executable,str(path)],cwd=R,env=dict(os.environ,PYTHONDONTWRITEBYTECODE='1'),check=True)
finally:path.unlink()
