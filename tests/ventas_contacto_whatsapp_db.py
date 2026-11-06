"""Contacto V4 y regresiones V2/V3 en una base local desechable, sin proveedor."""
import os, pathlib, subprocess, tempfile
R=pathlib.Path(__file__).resolve().parents[1]
runner=(R/'tests/ventas_todos_cierres_db.py').read_text(encoding='utf8')
# El contrato de cierre anterior a la conciliación API ya no aplica a esta rama.
# Este runner prueba resultados de llamadas con END explícito; no realiza HTTP.
runner=runner.replace(" require __DIR__.'/ventas_todos_cierres_casos.php';\\n",'')
anchor=' fixture=fixture.replace("SET time_zone=\'-06:00\'","SET time_zone=\'+00:00\'")'
assert anchor in runner
extra="\n fixture=fixture.replace(needle,\" require __DIR__.'/ventas_catalogo_envios_casos.php';\\n require __DIR__.'/ventas_resultados_dobles_casos.php';\\n\"+needle)\n for case in ['ventas_catalogo_envios_casos.php','ventas_resultados_dobles_casos.php']:shutil.copyfile(root/'tests'/case,project/'tests'/case)"
runner=runner.replace(anchor,anchor+extra)
with tempfile.NamedTemporaryFile(prefix='contacto_runner_',suffix='.py',dir=R/'tests',delete=False) as f:
 p=pathlib.Path(f.name);f.write(runner.encode())
try: subprocess.run([os.sys.executable,str(p)],check=True)
finally: p.unlink()
