"""Recuperación de consultas y errores de conexión; sin publicación ni SQL."""
import ast
import base64
import difflib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
def fragments(old,new):
    a,b=old.splitlines(keepends=True),new.splitlines(keepends=True)
    return [{'before':''.join(a[g[0][1]:g[-1][2]]),'after':''.join(b[g[0][3]:g[-1][4]])}
            for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(3)]
payload=[]
for path,ref in [('public/javascript/agenda_comentarios.js','fd787fb7'),('public/javascript/agenda_reunion.js','8a5b2f23')]:
    before=subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT).decode('utf8')
    after=(ROOT/path).read_text(encoding='utf8')
    entry={'path':path,'fragments':fragments(before,after)}
    if path.endswith('/agenda_reunion.js'):
        modern=subprocess.check_output(['git','show','fd787fb7:'+path],cwd=ROOT).decode('utf8')
        entry['alternatives']=[fragments(modern,after)]
    payload.append(entry)
tree=ast.parse((ROOT/'tools/generar_publicador_agenda_comentarios.py').read_text(encoding='utf8'))
program=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='PROGRAM' for t in n.targets))
program=program.replace("$version = 'agenda-comentarios-v1';","$version = 'agenda-conexion-comentarios-v1';")
program=program.replace("'/agenda-comentarios-'.date","'/agenda-conexion-comentarios-'.date")
program='\n'.join(line for line in program.splitlines() if 'Crear sólo las tablas nuevas:' not in line and
                 'Los ocho archivos nuevos preceden' not in line and 'En restauración se retiran primero' not in line)
program=program.replace('__PAYLOAD__',base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode())
shell='''#!/usr/bin/env bash
# Sólo recuperación de lectura/errores en dos JS de Agenda. Sin SQL ni envíos.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-conexion.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
'''+program+'''
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
'''
(ROOT/'tools/publicar_agenda_conexion_hostinger.sh').write_text(shell,encoding='utf8',newline='\n')
print('OK: publicador de recuperación de conexión generado; dos JavaScript, sin SQL ni publicación.')
