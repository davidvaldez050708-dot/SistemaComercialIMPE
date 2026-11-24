import base64,hashlib,json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
helper='app/helpers/PausaConsultasAutomaticas.php'
data=(root/helper).read_text(encoding='utf-8').encode('utf-8')
rows=[dict(path=helper,new_file=True,content=base64.b64encode(data).decode('ascii'),after_sha=hashlib.sha256(data).hexdigest())]
for name,before,include in [
    ('index.php','<?php\n',"require_once __DIR__.'/app/helpers/PausaConsultasAutomaticas.php';\n"),
    ('prueba_telefonia/api/avisos_llamadas.php','<?php\ndeclare(strict_types=1);\n',"require_once dirname(__DIR__,2).'/app/helpers/PausaConsultasAutomaticas.php';\n"),
    ('tools/cron_zoho_leads.php','<?php\n',"require_once dirname(__DIR__).'/app/helpers/PausaConsultasAutomaticas.php';\n")
]:
    rows.append(dict(path=name,new_file=False,hunks=[dict(before=before,after=before+include)]))
(root/'tools/contencion_mysql_release.json').write_text(json.dumps(dict(release='contencion-mysql-temporal-v1',files=rows),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
p=(root/'tools/publicar_marcador_estado.py').read_text(encoding='utf-8')
p=p.replace('import tempfile','import tempfile\nimport time')
p=p.replace("FILES=('public/javascript/telefonia_marcador.js',)",'FILES='+repr(tuple(row['path'] for row in rows)))
p=p.replace('marcador_estado_release.json','contencion_mysql_release.json').replace('marcador-estado-','contencion-mysql-')
p=p.replace('Actualiza sólo la interfaz del marcador; no toca SIP ni permisos.','Pausa temporal de consultas automáticas, sin modificar privilegios ni llamadas.')
p=p.replace("mode.add_argument('--check',action='store_true');", "mode.add_argument('--reanudar',action='store_true');mode.add_argument('--check',action='store_true');")
anchor="    plan=[]\n"
p=p.replace(anchor,"""    flag=backups/'pausa-consultas-automaticas.json'
    if args.reanudar:
        if flag.exists():flag.unlink()
        print('REANUDACION_OK: consultas automáticas habilitadas; no se cambiaron privilegios.');return
    def activar():
        backups.mkdir(parents=True,exist_ok=True,mode=0o700)
        write(flag,json.dumps({'version':1,'hasta':int(time.time())+3600}).encode('utf-8'),0o600)
        print('PAUSA_ACTIVA: 60 minutos; Zoho, avisos y sondeos no críticos. Webhooks, telefonía activa y guardado de resultados no se interceptan.')
    plan=[]
""")
p=p.replace("if not plan:print('SIN_CAMBIOS: paquete ya aplicado.');return", "if not plan:\n            activar();print('SIN_CAMBIOS: paquete ya aplicado.');return")
p=p.replace("    print(('RESTAURACION_OK'", "    if args.apply:activar()\n    print(('RESTAURACION_OK'")
p=p.replace('Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.','Contención temporal instalada; no se cambiaron privilegios MySQL.')
(root/'tools/publicar_contencion_mysql.py').write_text(p,encoding='utf-8')
print('Paquete de contención: 4 archivos; pausa temporal y reanudación sin consultas SQL.')
