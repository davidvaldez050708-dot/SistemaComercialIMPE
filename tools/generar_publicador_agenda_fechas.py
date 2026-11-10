"""Sólo referencias de calendario: datos locales, leyenda y etiquetas visuales."""
import ast
import base64
import hashlib
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
ADDED=['app/data/agenda_fechas_2026_2027.json',
       'public/css/agenda_fechas_referencia.css','public/javascript/agenda_fechas_referencia.js',
       'app/views/seguimiento_vinculacion/partials/agenda_fechas_referencia.php']
payload=[]
for path in ADDED:
    content=(ROOT/path).read_text(encoding='utf8')
    payload.append({'path':path,'content':content,'sha256':hashlib.sha256(content.encode()).hexdigest()})
start='    </div>\n\n'
hint='    <p class="agenda-mobile-scroll-hint">\n'
anchor=start+hint
added=start+"    <?php require __DIR__ . '/partials/agenda_fechas_referencia.php'; ?>\n\n"+hint
view='app/views/seguimiento_vinculacion/agenda.php'
assert (ROOT/view).read_text(encoding='utf8').count(added)==1
payload.append({'path':view,'fragments':[{'before':anchor,'after':added}]})
tree=ast.parse((ROOT/'tools/generar_publicador_agenda_comentarios.py').read_text(encoding='utf8'))
program=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='PROGRAM' for t in n.targets))
program=program.replace("$version = 'agenda-comentarios-v1';","$version = 'agenda-fechas-referencia-v1';")
program=program.replace("'/agenda-comentarios-'.date","'/agenda-fechas-referencia-'.date")
program='\n'.join(line for line in program.splitlines() if 'Crear sólo las tablas nuevas:' not in line and
                 'Los ocho archivos nuevos preceden' not in line and 'En restauración se retiran primero' not in line)
program=program.replace('__PAYLOAD__',base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode())
shell='''#!/usr/bin/env bash
# Referencias 2026/2027 de calendario.mx. Cuatro archivos nuevos y una inclusión.
# Publicación sin SQL, envíos ni cambios de reuniones, permisos o comentarios.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-fechas.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
'''+program+'''
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
'''
(ROOT/'tools/publicar_agenda_fechas_hostinger.sh').write_text(shell,encoding='utf8',newline='\n')
print('OK: publicador generado; sólo cinco archivos de visualización. Sin publicación ni SQL.')
