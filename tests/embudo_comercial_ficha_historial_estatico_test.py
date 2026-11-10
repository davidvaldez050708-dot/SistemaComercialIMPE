#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Guardas estáticas: leer únicamente historial del prospecto ya autorizado."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
repo=(root/'app/services/EmbudoComercialRepository.php').read_text(encoding='utf8')
view=(root/'app/views/embudo_comercial/index.php').read_text(encoding='utf8')
detail=(root/'app/views/embudo_comercial/detalle.php').read_text(encoding='utf8')
css=(root/'public/css/embudo_comercial.css').read_text(encoding='utf8')
start=repo.index('public function detalle(')
scope=repo[start:]
assert scope.index('if (!$rows) return null;') < scope.index("SELECT m.id,m.etapa_anterior"),\
       'historial solamente después de comprobación de acceso'
assert "WHERE p.id=? AND a.vigente=1" in scope
assert "AND a.asesor_id=?" in scope
assert "WHERE m.asignacion_id=?" in scope
assert "[(int)$p['asignacion_id']]" in scope
assert "LIMIT 51" in scope
assert "array_slice($historial,0,50)" in scope
assert "ORDER BY m.creado_at DESC,m.id DESC" in scope
assert "LEFT JOIN usuarios u" in scope
assert "historial_movimientos" in detail and "historial_truncado" in detail
assert "$esc($mov['motivo'])" in detail
assert "$esc($autor)" in detail
assert "EmbudoComercialPresentacion::fechaUtc" in view
assert "EmbudoComercialPresentacion::fechaUtc" in detail
assert "EmbudoComercialPresentacion::fechaLocal" in view
assert 'id="impe-embudo-fecha-wrap" hidden>Próxima acción' in view
assert "$vista==='embudo'?'Arrastra una ficha" in view
assert "impe-embudo-timeline" in css
assert "impe-embudo-card-bottom{flex-direction:column" in css
print("CHECK OK: historial limitado a asignación autorizada, fechas y textos consistentes.")
