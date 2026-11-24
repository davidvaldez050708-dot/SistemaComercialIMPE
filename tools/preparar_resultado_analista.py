import base64
import difflib
import hashlib
import json
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[1]
files = ('app/services/TelefoniaResultadoAnalistaService.php',
         'prueba_telefonia/api/resultado_saliente_analista.php',
         'app/controllers/SeguimientoVinculacionController.php',
         'app/services/InteraccionRutaService.php',
         'public/javascript/seguimiento_llamada_zadarma.js')
rows = []
for name in files:
    new = (root / name).read_text(encoding='utf-8').encode('utf-8')
    base = subprocess.run(['git','show','0caac61697e0349dbb9dd32a23c55550db3b0645:'+name], cwd=str(root), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if base.returncode:
        rows.append(dict(path=name,new_file=True,content=base64.b64encode(new).decode('ascii'),after_sha=hashlib.sha256(new).hexdigest()))
        continue
    a = base.stdout.decode('utf-8').replace('\r\n','\n').splitlines(True)
    b = new.decode('utf-8').splitlines(True)
    hunks = []
    for group in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(2):
        hunks.append(dict(before=''.join(a[group[0][1]:group[-1][2]]),after=''.join(b[group[0][3]:group[-1][4]])))
    if name.endswith('seguimiento_llamada_zadarma.js'):
        technical = """        const fetchTechnical = async (url, options) => {
            const abort = new AbortController();
            const timeout = window.setTimeout(() => abort.abort(), 12000);
            try { return await fetch(url, {...options, signal:abort.signal}); }
            finally { window.clearTimeout(timeout); }
        };
"""
        h = hunks[0]
        h['variants'] = [dict(before=h['before'].replace('        const usuarioId =',technical+'        const usuarioId ='),
                              after=h['after'].replace('        const usuarioId =',technical+'        const usuarioId ='))]
    rows.append(dict(path=name,new_file=False,hunks=hunks))
(root/'tools/resultado_analista_release.json').write_text(json.dumps(dict(release='analista-resultado-evidencia-v1',files=rows),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
publisher = (root/'tools/publicar_marcador_estado.py').read_text(encoding='utf-8')
publisher = publisher.replace("FILES=('public/javascript/telefonia_marcador.js',)", 'FILES='+repr(files))
publisher = publisher.replace('marcador_estado_release.json','resultado_analista_release.json').replace('marcador-estado-','resultado-analista-')
publisher = publisher.replace('Actualiza sólo la interfaz del marcador; no toca SIP ni permisos.', 'Valida resultados de llamadas de analistas antes de registrar la interacción.')
publisher = publisher.replace('Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.', 'Validación de resultados de analistas instalada; se conservan permisos y registros históricos.')
(root/'tools/publicar_resultado_analista.py').write_text(publisher,encoding='utf-8')
print('Paquete: '+str(len(rows))+' archivos, publicación selectiva con respaldo privado.')
