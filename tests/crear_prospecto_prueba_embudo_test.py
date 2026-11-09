#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Static safety tests: isolated CRM prospect fixture, no real DB connection."""
from pathlib import Path
import re
src=(Path(__file__).resolve().parents[1]/
     'tools/crear_prospecto_prueba_embudo.php').read_text(encoding='utf-8')
assert "MARCA_EMBUDO_TEST = 'EMBUDO-F2A-PRUEBA-001'" in src
assert 'function preflightFixture(' in src
assert "if (!empty($o['check']))" in src
assert src.index("if (!empty($o['check']))") < src.index(
    'respaldoFixture((string)$o[', src.index('try {',src.index('function respaldoFixture'))
), 'check must return before backup'
assert src.index("if (!$isTest)") < src.index("respaldoFixture((string)$o[")
assert "hash_equals((string)$o['expect-db'],$dbName)" in src, 'exact matching DB required'
assert "prueba|staging|sandbox" in src
assert "'telefono_original'=>null" in src
assert "SIN TELEFONO" in src
assert "IF NOT EXISTS" not in src, 'fixture may not change schema'
assert "begin_transaction()" in src
assert "$db->rollback()" in src and "$db->commit()" in src
assert "prospecto_asignaciones" in src and "VALUES (?,?,NULL,UTC_TIMESTAMP(),?,NULL,NULL,1,'NUEVO')" in src
assert "prospecto_embudo_etapas" in src and "No crear prospecto_embudo_etapas" in src
for prohibited in ("TRUNCATE ","DELETE FROM ", "DROP TABLE", "UPDATE prospecto_distribucion_control",
                   "meta_habilitado=1", "aliados_habilitado=1", "curl ", "file_get_contents('https://"):
    assert prohibited not in src, 'forbidden SQL/integration: '+prohibited
assert re.search(r"if \(\$existing\) \{\s*if \(.*?YA INSTALADA",src,re.S),'idempotence'
print('CHECK OK: test-only DB gate, exact DB name, no phone, one assignment, transaction and idempotence.')
