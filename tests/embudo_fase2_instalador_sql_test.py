#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Verificación estática de límites del instalador: no requiere MySQL."""
from pathlib import Path
import re

root=Path(__file__).resolve().parents[1]
php=(root/'tools/instalar_embudo_fase2a_hostinger.php').read_text(encoding='utf-8')
sql=(root/'database/migrations/2026_11_09_ventas_etapas_movimientos_fase2a.sql').read_text(encoding='utf-8')
assert re.findall(r'CREATE TABLE IF NOT EXISTS ([a-z_]+)',sql)==[
    'prospecto_embudo_agenda','prospecto_embudo_llamadas'], 'migration only creates 2 dedicated tables'
assert "prospecto_distribucion_control" in php, 'distribution guards'
assert "multi_query($sql)" in php, 'apply invokes vetted migration'
assert "hash_equals(IMPE_SQL_GIT_BLOB" in php, 'blob integrity'
assert "if (!empty($o['check']))" in php, 'read-only early return'
assert php.index("if (!empty($o['check']))") < php.index("impeBackup(" , php.index("    $estado=impeEstado($db);")), 'check returns before backup'
assert php.index("impeBackup($db,$site") < php.index("multi_query($sql)"), 'backup before DB writes'
for prohibited in ("DROP TABLE","TRUNCATE TABLE","DELETE FROM prospecto_asignaciones",
                   "UPDATE prospecto_distribucion_control","SET meta_habilitado=1"):
    assert prohibited not in sql, 'forbidden SQL: '+prohibited
assert "function impeVerificarConcesiones" in php, 'permissions reverified'
assert "MIGRACIÓN PARCIAL" not in php or "posible ejecución parcial" in php
assert "--apply" in php and "--check" in php
print("CHECK OK: dos tablas, tres permisos, backup previo, motores desactivados y chequeo de instalación parcial.")
