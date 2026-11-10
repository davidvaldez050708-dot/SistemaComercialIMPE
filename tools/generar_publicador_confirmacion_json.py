"""Respuestas JSON de Agenda. Publicador sin SQL ni envíos."""
import ast
import base64
import difflib
import hashlib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
payload=[]
path='tools/diagnosticar_confirmacion_reunion.php'
content=(ROOT/path).read_text(encoding='utf8')
payload.append({'path':path,'content':content,'sha256':hashlib.sha256(content.encode()).hexdigest()})
for path in ['app/controllers/AgendaReunionController.php','public/javascript/agenda_reunion.js']:
    a=subprocess.check_output(['git','show','8a5b2f23:'+path],cwd=ROOT).decode('utf8').splitlines(keepends=True)
    b=(ROOT/path).read_text(encoding='utf8').splitlines(keepends=True)
    fragments=[{'before':''.join(a[g[0][1]:g[-1][2]]),'after':''.join(b[g[0][3]:g[-1][4]])}
               for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(3)]
    if not fragments:raise RuntimeError('Sin cambio en '+path)
    payload.append({'path':path,'fragments':fragments})
tree=ast.parse((ROOT/'tools/generar_publicador_agenda_comentarios.py').read_text(encoding='utf8'))
program=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='PROGRAM' for t in n.targets))
program=program.replace("$version = 'agenda-comentarios-v1';","$version = 'agenda-confirmacion-json-v1';")
program=program.replace("'/agenda-comentarios-'.date","'/agenda-confirmacion-json-'.date")
program='\n'.join(line for line in program.splitlines() if 'Crear sólo las tablas nuevas:' not in line and
                 'Los ocho archivos nuevos preceden' not in line and 'En restauración se retiran primero' not in line)
program=program.replace('__PAYLOAD__',base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode())
shell='''#!/usr/bin/env bash
# Respuestas JSON y diagnóstico de confirmación. Publicación sin SQL ni envíos.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-json.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
'''+program+'''
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
'''
(ROOT/'tools/publicar_confirmacion_json_hostinger.sh').write_text(shell,encoding='utf8',newline='\n')
print('OK: publicador JSON generado, tres archivos. Sin SQL ni publicación.')
