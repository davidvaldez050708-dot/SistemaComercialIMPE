"""Publicador selectivo de privacidad y selector horario. No publica ni migra BD."""
import ast
import base64
import difflib
import hashlib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='192ebe0d1cd73461e6585432a86a03e87a8ac907'
CHANGED=['app/services/AgendaComentarioRepository.php','app/services/AgendaComentarioService.php',
         'app/views/seguimiento_vinculacion/partials/agenda_comentarios.php',
         'public/css/agenda_comentarios.css','public/javascript/agenda_comentarios.js']
NEW=['database/migrations/2026_10_29_agenda_comentarios_privados.sql','tools/configurar_agenda_comentarios_privados.php']

def fragments(old,new):
    a,b=old.splitlines(keepends=True),new.splitlines(keepends=True)
    return [{'before':''.join(a[g[0][1]:g[-1][2]]),'after':''.join(b[g[0][3]:g[-1][4]])}
            for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(3)]

payload=[]
for path in NEW+CHANGED:
    content=(ROOT/path).read_text(encoding='utf8')
    entry={'path':path}
    if path in NEW:entry.update(content=content,sha256=hashlib.sha256(content.encode()).hexdigest())
    else:
        old=subprocess.check_output(['git','show',BASE+':'+path],cwd=ROOT).decode('utf8')
        entry['fragments']=fragments(old,content)
        if not entry['fragments']:raise RuntimeError('Sin cambio en '+path)
    payload.append(entry)

tree=ast.parse((ROOT/'tools/generar_publicador_agenda_comentarios.py').read_text(encoding='utf8'))
program=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='PROGRAM' for t in n.targets))
label='agenda-comentarios-privados'
program=program.replace("$version = 'agenda-comentarios-v1';",f"$version = '{label}-v1';")
program=program.replace("'/agenda-comentarios-'.date",f"'/{label}-'.date")
program=program.replace('Sólo archivos listados; ninguna consulta SQL ni envío.','Sólo archivos listados; SQL de sólo lectura únicamente para proteger restore.')
program=program.replace('Sin SQL, envíos ni cambios de credenciales.','Sin escrituras SQL, envíos ni cambios de credenciales.')
program='\n'.join(line for line in program.splitlines() if 'Crear sólo las tablas nuevas:' not in line and
                 'Los ocho archivos nuevos preceden' not in line and 'En restauración se retiran primero' not in line)

# Una versión anterior ignora privado. Nunca restaurarla automáticamente si
# hay comentarios privados activos; tampoco adivinar ante un fallo de BD.
guard=r'''
        safePath($live.'/config/db_connection.php');
        if (!is_file($live.'/config/db_connection.php')) stopDeploy('No se puede comprobar privacidad para restaurar. SITIO INTACTO.');
        require_once $live.'/config/db_connection.php';
        try {
            $restoreDb=(new Database())->connect(false);
            $privacyColumn=$restoreDb->query("SHOW COLUMNS FROM agenda_comentarios LIKE 'privado'")->fetch_assoc();
            if ($privacyColumn) {
                $privateCount=$restoreDb->query('SELECT COUNT(*) AS total FROM agenda_comentarios WHERE privado<>0 AND eliminado_at IS NULL')->fetch_assoc();
                if (!is_array($privateCount) || !array_key_exists('total',$privateCount)) stopDeploy('No se pudo comprobar privacidad. SITIO INTACTO.');
                if ((int)$privateCount['total']>0) stopDeploy('No se restaura una versión sin privacidad mientras existan comentarios privados activos. SITIO INTACTO.');
            }
        } catch (Throwable $privacyError) {
            stopDeploy('Restauración detenida para conservar la privacidad. Verifica los comentarios privados y la conexión de BD. SITIO INTACTO.');
        }
'''
program=program.replace("    if ($mode==='--restore') {\n        $restore=", "    if ($mode==='--restore') {"+guard+"\n        $restore=")
encoded=base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode()
program=program.replace('__PAYLOAD__',encoded)
shell='''#!/usr/bin/env bash
# Privacidad y selector horario de comentarios. Check/apply sin SQL ni envíos.
# Restore consulta sólo si hay privados activos y rechaza una degradación insegura.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-privados.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
'''+program+'''
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
'''
(ROOT/'tools/publicar_agenda_privados_hostinger.sh').write_text(shell,encoding='utf8',newline='\n')
print('OK: publicador generado, 7 archivos selectivos. Sin despliegue ni migración.')
