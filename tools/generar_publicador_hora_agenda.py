"""Sólo el selector visual de hora. No publica ni conecta con la BD."""
import ast
import base64
import difflib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='a119dfe5b1b6159e7c648053f00f40dc43e6f5a9'
FILES=['app/views/seguimiento_vinculacion/partials/agenda_comentarios.php',
       'public/css/agenda_comentarios.css','public/javascript/agenda_comentarios.js']
payload=[]
for path in FILES:
    old=subprocess.check_output(['git','show',BASE+':'+path],cwd=ROOT).decode('utf8').splitlines(keepends=True)
    new=(ROOT/path).read_text(encoding='utf8').splitlines(keepends=True)
    fragments=[{'before':''.join(old[g[0][1]:g[-1][2]]),'after':''.join(new[g[0][3]:g[-1][4]])}
               for g in difflib.SequenceMatcher(None,old,new,autojunk=False).get_grouped_opcodes(3)]
    if not fragments:raise RuntimeError('Sin cambio en '+path)
    payload.append({'path':path,'fragments':fragments})
tree=ast.parse((ROOT/'tools/generar_publicador_agenda_comentarios.py').read_text(encoding='utf8'))
program=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='PROGRAM' for t in n.targets))
program=program.replace("$version = 'agenda-comentarios-v1';","$version = 'agenda-hora-nativa-v1';")
program=program.replace("'/agenda-comentarios-'.date","'/agenda-hora-nativa-'.date")
program='\n'.join(line for line in program.splitlines() if 'Crear sólo las tablas nuevas:' not in line and
                 'Los ocho archivos nuevos preceden' not in line and 'En restauración se retiran primero' not in line)
program=program.replace('__PAYLOAD__',base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode())
shell='''#!/usr/bin/env bash
# Sólo selector visual de hora: vista, CSS y JS del editor. Sin SQL ni envíos.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-hora.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
'''+program+'''
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
'''
(ROOT/'tools/publicar_hora_agenda_hostinger.sh').write_text(shell,encoding='utf8',newline='\n')
print('OK: publicador de hora generado; tres archivos. Sin publicación ni SQL.')
