"""Regresiones anteriores y catálogo V2 sobre MariaDB local desechable; sin configuración real."""
import os,pathlib,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
# Reutiliza el runner de cierre: incorpora los casos nuevos antes de reasignar la extensión.
runner=(R/'tests/ventas_todos_cierres_db.py').read_text(encoding='utf8')
needle=" fixture=fixture.replace(\"SET time_zone='-06:00'\",\"SET time_zone='+00:00'\")"
assert needle in runner
runner=runner.replace(needle,needle+"\n fixture=fixture.replace(needle,\" require __DIR__.'/ventas_catalogo_envios_casos.php';\\n\"+needle)\n shutil.copyfile(root/'tests/ventas_catalogo_envios_casos.php',project/'tests/ventas_catalogo_envios_casos.php')")
# El script vive bajo tests para conservar la raíz explícita que usa el runner.
with tempfile.NamedTemporaryFile(prefix='catalogo_runner_',suffix='.py',dir=R/'tests',delete=False) as f:
 p=pathlib.Path(f.name);f.write(runner.encode())
try:subprocess.run([os.environ.get('PYTHON_BINARY',os.sys.executable),str(p)],check=True)
finally:p.unlink()
