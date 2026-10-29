"""Entrega selectiva de destinatarios de avisos. Sin publicación ni migración."""
import ast
import base64
import difflib
import hashlib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='5d5eb913921c1ee843e55436edf86a4b110809d7'
NEW=['database/migrations/2026_10_29_agenda_comentarios_destinatario.sql','tools/configurar_destinatarios_agenda.php']
CHANGED=['app/services/AgendaComentarioRepository.php','app/services/AgendaComentarioService.php',
         'app/views/seguimiento_vinculacion/partials/agenda_comentarios.php',
         'public/css/agenda_comentarios.css','public/javascript/agenda_comentarios.js']
def fragments(old,new):
    a,b=old.splitlines(keepends=True),new.splitlines(keepends=True)
    return [{'before':''.join(a[g[0][1]:g[-1][2]]),'after':''.join(b[g[0][3]:g[-1][4]])}
            for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(3)]
payload=[]
for path in NEW+CHANGED:
    content=(ROOT/path).read_text(encoding='utf8');entry={'path':path}
    if path in NEW:entry.update(content=content,sha256=hashlib.sha256(content.encode()).hexdigest())
    else:
        before=subprocess.check_output(['git','show',BASE+':'+path],cwd=ROOT).decode('utf8')
        entry['fragments']=fragments(before,content)
        if not entry['fragments']:raise RuntimeError('Sin cambio en '+path)
        if path=='public/javascript/agenda_comentarios.js':
            old=subprocess.check_output(['git','show','fd787fb7:'+path],cwd=ROOT).decode('utf8')
            entry['alternatives']=[fragments(old,content)]
        previous=subprocess.check_output(['git','show','f9569c92:'+path],cwd=ROOT).decode('utf8')
        if previous!=content:
            entry.setdefault('alternatives',[]).append(fragments(previous,content))
    payload.append(entry)
tree=ast.parse((ROOT/'tools/generar_publicador_agenda_comentarios.py').read_text(encoding='utf8'))
program=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='PROGRAM' for t in n.targets))
label='agenda-destinatarios'
program=program.replace("$version = 'agenda-comentarios-v1';",f"$version = '{label}-v2';")
program=program.replace("'/agenda-comentarios-'.date",f"'/{label}-'.date")
program=program.replace('Sólo archivos listados; ninguna consulta SQL ni envío.','Sólo archivos listados; restore consulta SQL para proteger destinatarios.')
program=program.replace('Sin SQL, envíos ni cambios de credenciales.','Sin escrituras SQL, envíos ni cambios de credenciales.')
program='\n'.join(line for line in program.splitlines() if 'Crear sólo las tablas nuevas:' not in line and
                 'Los ocho archivos nuevos preceden' not in line and 'En restauración se retiran primero' not in line)
# Un rollback a código sin destinatarios volvería a notificar a todos.
guard=r'''
        safePath($live.'/config/db_connection.php');
        if (!is_file($live.'/config/db_connection.php')) stopDeploy('No se pudo comprobar destinatarios antes de restaurar. SITIO INTACTO.');
        require_once $live.'/config/db_connection.php';
        try {
            $restoreDb=(new Database())->connect(false);
            $column=$restoreDb->query("SHOW COLUMNS FROM agenda_comentarios LIKE 'destinatario_id'")->fetch_assoc();
            if ($column) {
                $count=$restoreDb->query('SELECT COUNT(*) AS total FROM agenda_comentarios WHERE destinatario_id IS NOT NULL AND eliminado_at IS NULL')->fetch_assoc();
                if (!is_array($count) || !array_key_exists('total',$count) || (int)$count['total']>0)
                    stopDeploy('Hay comentarios dirigidos: la versión anterior notificaría a todos. SITIO INTACTO.');
            }
        } catch (Throwable $restoreError) {
            stopDeploy('Restauración detenida para conservar los destinatarios. Comprueba los comentarios dirigidos y la conexión de BD. SITIO INTACTO.');
        }
'''
program=program.replace("    if ($mode==='--restore') {\n        $restore=", "    if ($mode==='--restore') {"+guard+"\n        $restore=")
program=program.replace('__PAYLOAD__',base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode())
shell='''#!/usr/bin/env bash
# Destinatarios de avisos de comentarios. Check/apply sin SQL ni envíos.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-destinatarios.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
'''+program+'''
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
'''
(ROOT/'tools/publicar_destinatarios_agenda_hostinger.sh').write_text(shell,encoding='utf8',newline='\n')
print('OK: publicador de destinatarios generado; migración CLI separada y restore protegido.')
