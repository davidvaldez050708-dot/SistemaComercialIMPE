"""Publicador de un único archivo. Sin conexiones ni publicación automática."""
import ast
import base64
import difflib
import json
from pathlib import Path
import subprocess
import sys
sys.dont_write_bytecode=True
from agenda_comentarios_compat import recordatorios_hostinger, recordatorios_hostinger_telefono

ROOT=Path(__file__).resolve().parents[1]
BASE='7bca643d'
FILE='public/javascript/recordatorios.js'
before=subprocess.check_output(['git','show',BASE+':'+FILE],cwd=ROOT).decode('utf8')
after=(ROOT/FILE).read_text(encoding='utf8')
def fragments(old,new):
    a,b=old.splitlines(keepends=True),new.splitlines(keepends=True)
    return [{'before':''.join(a[g[0][1]:g[-1][2]]),'after':''.join(b[g[0][3]:g[-1][4]])}
            for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(3)]
host_before,host_after=recordatorios_hostinger(before,after)
legacy=subprocess.check_output(['git','show','5116b62c:'+FILE],cwd=ROOT).decode('utf8')
legacy_host,_=recordatorios_hostinger(legacy,after)
phone_before,phone_after=recordatorios_hostinger_telefono(before,after)
legacy_phone,_=recordatorios_hostinger_telefono(legacy,after)
payload=[{'path':FILE,'fragments':fragments(before,after),'alternatives':[
    fragments(host_before,host_after),fragments(phone_before,phone_after),
    fragments(legacy,after),fragments(legacy_host,host_after),fragments(legacy_phone,phone_after)]}]
if not payload[0]['fragments']:raise RuntimeError('Sin cambio de avisos.')

# Reutilizar bloqueo, respaldo y restauración del publicador revisado, sin
# ejecutar su generador ni reescribir su entrega histórica.
tree=ast.parse((ROOT/'tools/generar_publicador_agenda_comentarios.py').read_text(encoding='utf8'))
program=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='PROGRAM' for t in n.targets))
program=program.replace("$version = 'agenda-comentarios-v1';","$version = 'agenda-comentarios-avisos-v1';")
program=program.replace("'/agenda-comentarios-'.date","'/agenda-comentarios-avisos-'.date")
program='\n'.join(line for line in program.splitlines() if 'Crear sólo las tablas nuevas:' not in line and
                 'Los ocho archivos nuevos preceden' not in line and 'En restauración se retiran primero' not in line)
encoded=base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode()
program=program.replace('__PAYLOAD__',encoded)
shell='''#!/usr/bin/env bash
# Sólo avisos de comentarios en recordatorios.js. Sin SQL, CSS ni permisos.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-avisos.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
'''+program+'''
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
'''
(ROOT/'tools/publicar_avisos_comentarios_hostinger.sh').write_text(shell,encoding='utf8',newline='\n')
print('OK: publicador generado; sólo recordatorios.js. Sin SQL ni publicación.')
