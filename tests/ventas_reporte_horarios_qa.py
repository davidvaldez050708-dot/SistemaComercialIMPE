"""Reutiliza el servidor aislado y las vistas reales para revisar horarios y PDF."""
import pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
s=(R/'tests/ventas_consulta_doble_qa.py').read_text(encoding='utf8')
s=s.replace("OUT=R/'tmp/consulta-qa'","OUT=R/'tmp/pdfs/ventas-horarios'")
s=s.replace("$d=$fixture['actividad'];","$d=$fixture['hoy'];")
s=s.replace("foreach(['reporte'=>new VentasActividadPdfView(),'desempeno'=>new VentasDesempenoPdfView()] as $name=>$view){","foreach(['hoy','rango'] as $name){$view=new VentasActividadPdfView();$d=$fixture[$name];")
start=s.index("if($mode==='historial')");end=s.index('?></body></html>',start)
s=s[:start]+"$actividad=$fixture[$mode]??$fixture['hoy'];$errorActividad='';$urlActividadPdf='';require __DIR__.'/app/views/reportes/ventas_actividad.php';\n"+s[end:]
s=s.replace('ventas_consulta_doble_browser.cjs','ventas_reporte_horarios_browser.cjs')
s=s.replace('PASS: vistas reales y PDF de Reportes/Desempeño generados con Dompdf, sin acceso a producción.','PASS: seis cortes del reporte en escritorio/móvil y dos PDF reales con Dompdf, sin acceso a producción.')
with tempfile.NamedTemporaryFile(prefix='horarios_qa_',suffix='.py',dir=R/'tests',delete=False) as f:
    path=pathlib.Path(f.name);f.write(s.encode())
try:subprocess.run([sys.executable,str(path)],check=True)
finally:path.unlink()
