"""Vistas antes/después con los mismos datos y recursos reales; sin producción."""
import os,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
s=(R/'tests/reportes_ventas_automaticos_qa.py').read_text(encoding='utf8')
s=s.replace("OUT=R/'tmp/reportes-auto-qa'","OUT=R/'tmp/reportes-diseno-qa'")
s=s.replace('fixtures/ventas_auto_services.php','fixtures/ventas_diseno_services.php')
start=s.index("    (P/'app/helpers/AvatarHelper.php').write_text(");end=s.index('\n',start)
s=s[:start]+"    shutil.copyfile(R/'app/helpers/AvatarHelper.php',P/'app/helpers/AvatarHelper.php')"+s[end:]
s=s.replace("for path in ['public/css/ventas_actividad.css','public/javascript/reportes_pdf_descarga.js']:","for path in ['public/javascript/reportes_pdf_descarga.js']:")
anchor="    for name in ['sidebar.php','topbar.php']:"
extra="""
    from reportes_diseno_recursos import cargar_recursos
    cargar_recursos(P)
    source=P/'app/views/reportes/ventas_actividad.php';source.rename(source.with_name('ventas_actual.php'))
    before=subprocess.check_output(['git','show','5d867653:app/views/reportes/ventas_actividad.php'],cwd=R).decode().replace('ventas_actividad.css','ventas_actividad_antes.css')
    source.with_name('ventas_antes.php').write_text(before,encoding='utf8')
    source.write_text('<?php require __DIR__.(isset($_GET["antes"])?"/ventas_antes.php":"/ventas_actual.php");',encoding='utf8')
    (P/'public/css/ventas_actividad_antes.css').write_bytes(subprocess.check_output(['git','show','77d57ada:public/css/ventas_actividad.css'],cwd=R))
"""
assert anchor in s;s=s.replace(anchor,extra+'\n'+anchor)
s=s.replace('<body style="padding:20px">','<body><main class="admin-content">')
s=s.replace('</head><body>','<link rel="stylesheet" href="assets/manrope.css"><link rel="stylesheet" href="assets/bootstrap-icons.css"></head><body>')
s=s.replace("write_text('</body></html>'","write_text('</main></body></html>'")
anchor="        subprocess.run(['node',str(R/'tests/reportes_ventas_automaticos_browser.cjs')],env=env,check=True)"
assert anchor in s;s=s.replace(anchor,anchor+"\n        subprocess.run(['node',str(R/'tests/reportes_ventas_diseno_browser.cjs')],env=env,check=True)")
s=s.replace('PASS: controlador genera sin parámetro inicial, sin SQL ni llamadas externas; vistas reales en escritorio/móvil y dentro del iframe del CRM.','PASS: diseño y filtros automáticos comprobados con vistas reales, tipografía Manrope e iconos del sistema; sin SQL ni llamadas externas.')
with tempfile.NamedTemporaryFile(prefix='diseno_qa_',suffix='.py',dir=R/'tests',delete=False) as f:path=pathlib.Path(f.name);f.write(s.encode())
try:subprocess.run([sys.executable,str(path)],env=dict(os.environ,PYTHONDONTWRITEBYTECODE='1'),check=True)
finally:path.unlink()
