"""Regresiones de captura más lecturas reales de reportes/supervisión en MariaDB local desechable."""
import os,pathlib,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
runner=(R/'tests/ventas_todos_cierres_db.py').read_text(encoding='utf8')
anchor=" fixture=fixture.replace(\"SET time_zone='-06:00'\",\"SET time_zone='+00:00'\")"
assert anchor in runner
runner=runner.replace(anchor,anchor+"\n fixture=fixture.replace(needle,\" require __DIR__.'/ventas_catalogo_envios_casos.php';\\n require __DIR__.'/ventas_resultados_dobles_casos.php';\\n require __DIR__.'/ventas_consulta_doble_casos.php';\\n require __DIR__.'/ventas_reporte_horarios_casos.php';\\n\"+needle)\n for case in ['ventas_catalogo_envios_casos.php','ventas_resultados_dobles_casos.php','ventas_consulta_doble_casos.php','ventas_reporte_horarios_casos.php']:shutil.copyfile(root/'tests'/case,project/'tests'/case)")
anchor=" fixture=(root/'tests/telefonia_ventas_db.php').read_text(encoding='utf8')"
extra="""
 for name in ['VentasActividadRepository.php','VentasActividadService.php','VentasActividadMetricas.php','VentasActividadPdfView.php','VentasDesempenoPdfView.php','SupervisionLlamadasRepository.php']:
  shutil.copyfile(root/'app/services'/name,project/'app/services'/name)
 (project/'app/helpers/VentasActividadAccess.php').write_bytes(subprocess.check_output(['git','show','77d57ada:app/helpers/VentasActividadAccess.php'],cwd=root))
 (project/'app/helpers/PermissionHelper.php').write_text('<?php function tienePermiso($code){global $db; $role=(int)($_SESSION["rol_id"]??0);$s=$db->prepare("SELECT p.id FROM permisos p JOIN rol_permisos r ON r.permiso_id=p.id WHERE r.rol_id=? AND p.codigo=? AND p.estado=1");$s->bind_param("is",$role,$code);$s->execute();return (bool)$s->get_result()->fetch_assoc();}',encoding='utf8')
"""
assert anchor in runner;runner=runner.replace(anchor,extra+anchor)
with tempfile.NamedTemporaryFile(prefix='consulta_runner_',suffix='.py',dir=R/'tests',delete=False) as f:
 p=pathlib.Path(f.name);f.write(runner.encode())
try:subprocess.run([os.sys.executable,str(p)],check=True)
finally:p.unlink()
