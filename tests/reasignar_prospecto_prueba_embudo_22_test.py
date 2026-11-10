#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Assert that reassignment changes exactly one synthetic, untouched fixture."""
from pathlib import Path
import re
src=(Path(__file__).resolve().parents[1]/
     'tools/reasignar_prospecto_prueba_embudo_22.php').read_text(encoding='utf-8')
must=[
    "const IMPE_FIX_DB='u170338188_red_crm';",
    'const IMPE_FIX_PROSPECTO=14;',
    'const IMPE_FIX_ASIGNACION=1;',
    'const IMPE_FIX_ASESOR_ANTES=8;',
    'const IMPE_FIX_ASESOR_DESPUES=22;',
    "const IMPE_FIX_ORIGEN='EMBUDO-F2A-PRUEBA-001';",
    'REASIGNAR_PRUEBA_14_AL_ASESOR_22',
    'if (!empty($options[\'check\']))',
    "hash_equals(IMPE_FIX_DB,(string)$options['expect-db'])",
    "'telefono_original']!==null",
    "'telefono_normalizado']!==null",
    "'correo']!==null",
    "asignacion_id",
    "prospecto_distribucion_registros",
    'impeManifest((string)$options',
    '$db->begin_transaction();',
    'UPDATE prospecto_asignaciones SET asesor_id=?',
    'AND vigente=1 AND estado=\\'NUEVO\\'',
    'if ($changed!==1)',
    '$db->rollback();',
    '$db->commit();'
]
for m in must:
    assert m in src, "Missing safety gate: "+m
assert src.index("if (!empty($options['check']))") < src.index(
    'impeManifest((string)$options'), 'Check must not write'
assert src.index('impeManifest((string)$options') < src.index(
    '$db->begin_transaction();'), 'Backup must precede change'
assert re.findall(r'\bUPDATE\s+([a-z_]+)',src,re.I)==['prospecto_asignaciones'],\
    'Only assignments can be updated'
assert not re.search(r'\b(?:DELETE|DROP|TRUNCATE|ALTER|CREATE TABLE)\b',src,re.I)
assert "meta_habilitado" in src and "aliados_habilitado" in src
for name in ('prospecto_embudo_etapas','prospecto_embudo_movimientos',
             'prospecto_embudo_agenda','prospecto_embudo_llamadas'):
    assert "'"+name+"'" in src
print('CHECK OK: únicamente prospecto 14/asignación 1, asesor 8 -> 22; transacción y respaldo.')
