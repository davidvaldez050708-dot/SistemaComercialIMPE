"""Paquete de presentación del aviso, sobre la validación ya instalada."""
import difflib
import json
import subprocess
from pathlib import Path

root=Path(__file__).resolve().parents[1]
name='public/javascript/seguimiento_llamada_zadarma.js'
old=subprocess.check_output(['git','show','c8de6e607ccc9d737d62a695a6af6253467883c9:'+name],cwd=str(root)).decode('utf-8').replace('\r\n','\n')
new=(root/name).read_text(encoding='utf-8')
a,b=old.splitlines(True),new.splitlines(True)
hunks=[]
for group in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(2):
    hunks.append(dict(before=''.join(a[group[0][1]:group[-1][2]]),after=''.join(b[group[0][3]:group[-1][4]])))
(root/'tools/aviso_analista_release.json').write_text(json.dumps(dict(release='aviso-analista-institucional-v1',files=[dict(path=name,new_file=False,hunks=hunks)]),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
publisher=(root/'tools/publicar_marcador_estado.py').read_text(encoding='utf-8')
publisher=publisher.replace("FILES=('public/javascript/telefonia_marcador.js',)",'FILES='+repr((name,)))
publisher=publisher.replace('marcador_estado_release.json','aviso_analista_release.json').replace('marcador-estado-','aviso-analista-')
publisher=publisher.replace('Actualiza sólo la interfaz del marcador; no toca SIP ni permisos.','Actualiza el diseño del aviso y oculta la reconsulta cuando la llamada está confirmada.')
publisher=publisher.replace('Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.','Aviso institucional actualizado; reconsulta visible sólo cuando falta confirmación.')
(root/'tools/publicar_aviso_analista.py').write_text(publisher,encoding='utf-8')
print('Paquete: 1 archivo JS, '+str(len(hunks))+' bloques selectivos.')
