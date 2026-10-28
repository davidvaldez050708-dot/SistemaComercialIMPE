"""Publicador de un único archivo. Sin conexiones ni publicación automática."""
import ast
import base64
import difflib
import json
import hashlib
from pathlib import Path
import subprocess
import sys
sys.dont_write_bytecode=True
from agenda_comentarios_compat import recordatorios_hostinger, recordatorios_hostinger_telefono, recordatorios_hostinger_actual

ROOT=Path(__file__).resolve().parents[1]
CONFIRMACION='--confirmacion' in sys.argv
VISTOS='--vistos' in sys.argv
INTEGRACION='--integracion' in sys.argv or VISTOS
BASE='9256fa4a' if CONFIRMACION else '7bca643d'
FILE='public/javascript/agenda_comentarios.js' if CONFIRMACION else 'public/javascript/recordatorios.js'
before=subprocess.check_output(['git','show',BASE+':'+FILE],cwd=ROOT).decode('utf8')
after=(ROOT/FILE).read_text(encoding='utf8')
def fragments(old,new):
    a,b=old.splitlines(keepends=True),new.splitlines(keepends=True)
    return [{'before':''.join(a[g[0][1]:g[-1][2]]),'after':''.join(b[g[0][3]:g[-1][4]])}
            for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(3)]
if CONFIRMACION:
    payload=[{'path':FILE,'fragments':fragments(before,after)}]
else:
    host_before,host_after=recordatorios_hostinger(before,after)
    legacy=subprocess.check_output(['git','show','5116b62c:'+FILE],cwd=ROOT).decode('utf8')
    legacy_host,_=recordatorios_hostinger(legacy,after)
    phone_before,phone_after=recordatorios_hostinger_telefono(before,after)
    legacy_phone,_=recordatorios_hostinger_telefono(legacy,after)
    payload=[{'path':FILE,'fragments':fragments(before,after),'alternatives':[
        fragments(host_before,host_after),fragments(phone_before,phone_after),
        fragments(legacy,after),fragments(legacy_host,host_after),fragments(legacy_phone,phone_after)]}]
if not payload[0]['fragments']:raise RuntimeError('Sin cambio de avisos.')
if INTEGRACION:
    # Admitir la entrega de lectura/toast ya instalada y la variante SSH
    # telefónica, conservando sus clases y condiciones existentes.
    previous=subprocess.check_output(['git','show','9256fa4a:'+FILE],cwd=ROOT).decode('utf8')
    previous_host,current_host=recordatorios_hostinger(previous,after)
    previous_phone,current_phone=recordatorios_hostinger_telefono(previous,after)
    previous_actual,current_actual=recordatorios_hostinger_actual(previous,after)
    payload[0]['alternatives'].extend([fragments(previous,after),fragments(previous_host,current_host),fragments(previous_phone,current_phone),fragments(previous_actual,current_actual)])
    editor='public/javascript/agenda_comentarios.js'
    editor_before=subprocess.check_output(['git','show','9256fa4a:'+editor],cwd=ROOT).decode('utf8')
    editor_after=(ROOT/editor).read_text(encoding='utf8')
    editor_confirmed=subprocess.check_output(['git','show','0037e45f:'+editor],cwd=ROOT).decode('utf8')
    payload.append({'path':editor,'fragments':fragments(editor_before,editor_after),'alternatives':[fragments(editor_confirmed,editor_after)]})
    if VISTOS:
        last=subprocess.check_output(['git','show','98e13a75:'+FILE],cwd=ROOT).decode('utf8')
        lh,ah=recordatorios_hostinger(last,after)
        lp,ap=recordatorios_hostinger_telefono(last,after)
        la,aa=recordatorios_hostinger_actual(last,after)
        payload[0]['alternatives'].extend([fragments(last,after),fragments(lh,ah),fragments(lp,ap),fragments(la,aa)])
        last_editor=subprocess.check_output(['git','show','98e13a75:'+editor],cwd=ROOT).decode('utf8')
        payload[1]['alternatives'].append(fragments(last_editor,editor_after))
if VISTOS:
    added=[]
    for path in ['database/migrations/2026_10_28_agenda_comentarios_vistos.sql','tools/configurar_avisos_vistos_agenda.php']:
        content=(ROOT/path).read_text(encoding='utf8')
        added.append({'path':path,'content':content,'sha256':hashlib.sha256(content.encode()).hexdigest()})
    backend=[]
    for path in ['app/services/AgendaComentarioRepository.php','app/services/AgendaComentarioService.php','app/controllers/AgendaComentarioController.php']:
        old=subprocess.check_output(['git','show','98e13a75:'+path],cwd=ROOT).decode('utf8')
        backend.append({'path':path,'fragments':fragments(old,(ROOT/path).read_text(encoding='utf8'))})
    payload=added+backend+payload

# Reutilizar bloqueo, respaldo y restauración del publicador revisado, sin
# ejecutar su generador ni reescribir su entrega histórica.
tree=ast.parse((ROOT/'tools/generar_publicador_agenda_comentarios.py').read_text(encoding='utf8'))
program=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='PROGRAM' for t in n.targets))
label='agenda-comentarios-integracion' if INTEGRACION else ('agenda-comentarios-confirmacion' if CONFIRMACION else 'agenda-comentarios-avisos')
if VISTOS:label='agenda-comentarios-vistos'
program=program.replace("$version = 'agenda-comentarios-v1';",f"$version = '{label}-v1';")
program=program.replace("'/agenda-comentarios-'.date",f"'/{label}-'.date")
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
name='publicar_confirmacion_comentario_hostinger.sh' if CONFIRMACION else 'publicar_avisos_comentarios_hostinger.sh'
if INTEGRACION:name='publicar_integracion_toasts_comentarios_hostinger.sh'
if VISTOS:name='publicar_avisos_vistos_agenda_hostinger.sh'
if CONFIRMACION:shell=shell.replace('# Sólo avisos de comentarios en recordatorios.js. Sin SQL, CSS ni permisos.',
    '# Sólo confirmación al añadir comentarios en agenda_comentarios.js. Sin SQL ni cambios en recordatorios.')
if INTEGRACION:shell=shell.replace('# Sólo avisos de comentarios en recordatorios.js. Sin SQL, CSS ni permisos.',
    '# Sólo toasts/lectura de comentarios: dos JavaScript, sin SQL, CSS ni permisos.')
if VISTOS:shell=shell.replace('# Sólo toasts/lectura de comentarios: dos JavaScript, sin SQL, CSS ni permisos.',
    '# Avisos de lectura al autor. Publicación sin SQL, CSS ni cambios de permisos.')
(ROOT/'tools'/name).write_text(shell,encoding='utf8',newline='\n')
print('OK: publicador generado; '+str(len(payload))+' archivo(s). Sin SQL ni publicación.')
