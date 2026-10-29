"""Corrige únicamente la asignación SQL observada en Hostinger, conservando WHERE."""
import ast
import base64
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
fixture=(ROOT/'tests/fixtures/agenda_confirmar_hostinger.php').read_text(encoding='utf8')
old=next(line for line in fixture.splitlines(keepends=True) if "$sql = 'UPDATE reuniones_vinculacion SET ' ." in line)
wrong="'UPDATE reuniones_vinculacion SET ' . SeguimientoTerritorialScope::reunionSupervisor('reuniones_vinculacion') . ',zoom_url"
correct="'UPDATE reuniones_vinculacion SET cuenta_clave_id = ?,zoom_url"
assert old.count(wrong)==1
new=old.replace(wrong,correct)
payload=[{'path':'app/services/AgendaReunionRepository.php','fragments':[{'before':old,'after':new}]}]
tree=ast.parse((ROOT/'tools/generar_publicador_agenda_comentarios.py').read_text(encoding='utf8'))
program=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='PROGRAM' for t in n.targets))
program=program.replace("$version = 'agenda-comentarios-v1';","$version = 'agenda-confirmacion-sql-v1';")
program=program.replace("'/agenda-comentarios-'.date","'/agenda-confirmacion-sql-'.date")
program='\n'.join(line for line in program.splitlines() if 'Crear sólo las tablas nuevas:' not in line and
                 'Los ocho archivos nuevos preceden' not in line and 'En restauración se retiran primero' not in line)
program=program.replace('__PAYLOAD__',base64.b64encode(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).decode())
shell='''#!/usr/bin/env bash
# Sólo asignación SET en confirmar(). Conserva WHERE territorial y parámetros.
# Check/apply/restore no ejecutan SQL ni confirman reuniones.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-sql.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
'''+program+'''
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
'''
(ROOT/'tools/publicar_confirmacion_sql_hostinger.sh').write_text(shell,encoding='utf8',newline='\n')
print('OK: parche de una línea generado. Sólo AgendaReunionRepository.php; sin SQL ni publicación.')
