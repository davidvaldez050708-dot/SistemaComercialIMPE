"""Ejecuta los contratos legado/V2 y los casos V3 en la misma base local desechable."""
import os,pathlib,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
runner=(R/'tests/ventas_todos_cierres_db.py').read_text(encoding='utf8')
needle=" fixture=fixture.replace(\"SET time_zone='-06:00'\",\"SET time_zone='+00:00'\")"
assert needle in runner
extra="\n fixture=fixture.replace(needle,\" require __DIR__.'/ventas_catalogo_envios_casos.php';\\n require __DIR__.'/ventas_resultados_dobles_casos.php';\\n\"+needle)\n for case in ['ventas_catalogo_envios_casos.php','ventas_resultados_dobles_casos.php']:shutil.copyfile(root/'tests'/case,project/'tests'/case)"
runner=runner.replace(needle,needle+extra)
with tempfile.NamedTemporaryFile(prefix='doble_runner_',suffix='.py',dir=R/'tests',delete=False) as f:
 p=pathlib.Path(f.name);f.write(runner.encode())
try:subprocess.run([os.sys.executable,str(p)],check=True)
finally:p.unlink()
