#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Fixture PBX: sólo una ficha sintética y sin exponer números ni llamar."""
from pathlib import Path
import re
src=(Path(__file__).resolve().parents[1]/'tools/crear_prospecto_prueba_pbx_157.php').read_text(encoding='utf8')
required=[
    "const IMPE_PBX_TEST_DB='u170338188_red_crm';",
    "const IMPE_PBX_TEST_ADVISOR=22;",
    "const IMPE_PBX_TEST_EXTENSION='157';",
    "const IMPE_PBX_TEST_MARKER='EMBUDO-F2A-PBX-157-001';",
    "FICHA_PBX_CONTROLADA_22_157",
    "telefonia_extensiones",
    "telefonia_ventas_resultados",
    "telefonia_ventas_llamadas",
    "prospecto_embudo_llamadas",
    "prospecto_distribucion_control",
    "meta_habilitado",
    "aliados_habilitado",
    "getenv('IMPE_EMBUDO_NUMERO')",
    "ProspectoCentralDatos::telefono",
    "TelefoniaContactosService::normalizarTelefono",
    "WHERE telefono_normalizado=?",
    "'nombre_completo'=>'PRUEBA ZADARMA 157 - CONTACTO AUTORIZADO'",
    "public function" if False else "function pbxPreflight",
    "require_once $site.'/app/services/ProspectoCentralRepository.php'",
    "INSERT INTO prospecto_asignaciones",
    "FICHA PBX DE PRUEBA" if False else "PRUEBA PBX CREADA",
    "$db->begin_transaction()",
    "$db->rollback()",
    "$db->commit()",
    "MANIFIESTO PRIVADO",
    "if (!empty($o['check']))",
    "numero_nacional_en_pbx",
    "mismoNumeroAsignado",
    "El puente comercial del sitio aún no fue validado",
    "numero_nacional_en_pbx"
]
for string in required:
    assert string in src,string
assert src.index("if (!empty($o['check']))") < src.index("pbxManifest((string)$o['backup-root']")
assert src.index("if (!hash_equals(IMPE_PBX_TEST_DB") < src.index("pbxManifest((string)$o['backup-root']")
assert src.index("pbxManifest((string)$o['backup-root']") < src.index("$db->begin_transaction()")
assert len(re.findall(r"\bINSERT INTO prospecto_asignaciones\b",src))==1
for forbidden in ("TRUNCATE ", "DROP TABLE", "DELETE FROM ", "UPDATE prospecto_distribucion_control",
                  "CALL Zadarma", "file_get_contents('http"):
    assert forbidden not in src,forbidden
assert re.search(r'echo\s+.*?\$phone',src)==None,'Do not print phone'
assert '"telefono"=>' not in src,'Do not log raw phone'
assert "SIN LLAMADAS" in src
print("CHECK OK: sólo fixture PBX ext157 con entrada privada, rollback y distribución apagada.")
