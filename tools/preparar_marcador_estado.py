import difflib,json,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
name='public/javascript/telefonia_marcador.js'
old=subprocess.check_output(['git','show','2ecaaa2:'+name],cwd=str(root)).decode('utf-8').replace('\r\n','\n')
new=(root/name).read_text(encoding='utf-8')
a,b=old.splitlines(True),new.splitlines(True)
hunks=[]
for group in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(1):
    i1,i2=group[0][1],group[-1][2];j1,j2=group[0][3],group[-1][4]
    hunks.append(dict(before=''.join(a[i1:i2]),after=''.join(b[j1:j2])))
spec=dict(release='marcador-estado-v1',files=[dict(path=name,new_file=False,hunks=hunks)])
(root/'tools/marcador_estado_release.json').write_text(json.dumps(spec,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
publisher=(root/'tools/publicar_rendimiento_inicio.py').read_text(encoding='utf-8')
publisher=publisher.replace("FILES=('app/models/RolModel.php', 'app/controllers/LoginController.php', 'app/controllers/TelefoniaController.php')",'FILES='+repr((name,)))
publisher=publisher.replace('rendimiento_inicio_release.json','marcador_estado_release.json').replace('rendimiento-inicio-','marcador-estado-')
publisher=publisher.replace('"""Publicación selectiva de rendimiento de inicio; conserva personalizaciones."""','"""Actualiza sólo la interfaz del marcador; no toca SIP ni permisos."""')
publisher=publisher.replace('Optimizaciones instaladas; reglas de permisos conservadas.','Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.')
(root/'tools/publicar_marcador_estado.py').write_text(publisher,encoding='utf-8')
print('Paquete generado: un archivo JS, '+str(len(hunks))+' bloques selectivos.')
