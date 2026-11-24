"""Publica sólo visibilidad y recuperación del borrador; conserva el cierre PBX."""
import difflib,json,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
names=('public/javascript/telefonia_atencion.js','public/javascript/seguimiento_interacciones.js','public/javascript/seguimiento_entrantes_analista.js')
rows=[]
for name in names:
 old=subprocess.check_output(['git','show','bc5e15b6c7bc4633bf6ea7a9dd0560f9f8307913:'+name],cwd=str(root)).decode().replace('\r\n','\n')
 new=(root/name).read_text(encoding='utf-8')
 a,b=old.splitlines(True),new.splitlines(True);hunks=[]
 live=(root/'tmp/contacto-visible-live'/name).read_text(encoding='utf-8')
 for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(2):
  before=''.join(a[g[0][1]:g[-1][2]]);after=''.join(b[g[0][3]:g[-1][4]])
  if live.count(before)!=1:raise RuntimeError('Bloque instalado distinto: '+name)
  live=live.replace(before,after,1);hunks.append(dict(before=before,after=after))
 rows.append(dict(path=name,new_file=False,hunks=hunks))
 p=root/'tmp/contacto-visible-stage'/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(live,encoding='utf-8')
(root/'tools/contacto_visible_release.json').write_text(json.dumps(dict(release='contacto-visible-analista-v1',files=rows),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
p=(root/'tools/publicar_marcador_estado.py').read_text(encoding='utf-8')
p=p.replace("FILES=('public/javascript/telefonia_marcador.js',)",'FILES='+repr(names))
p=p.replace('marcador_estado_release.json','contacto_visible_release.json').replace('marcador-estado-','contacto-visible-')
p=p.replace('Actualiza sólo la interfaz del marcador; no toca SIP ni permisos.','Muestra la captura antes de clasificar y recupera el nombre del borrador exacto.')
p=p.replace('Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.','Contacto visible antes de seleccionar resultado; captura recuperada y validación de cierre conservada.')
(root/'tools/publicar_contacto_visible.py').write_text(p,encoding='utf-8')
print('Paquete selectivo: 3 archivos JS.')
