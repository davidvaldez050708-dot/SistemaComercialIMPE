#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Guardas para el reporte y JS reales de v4, sin DB o PBX."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
report=(root/'app/services/TelefoniaControlService.php').read_text(encoding='utf-8')
view=(root/'app/views/telefonia/index.php').read_text(encoding='utf-8')
js=(root/'public/javascript/telefonia_ventas_resultados.js').read_text(encoding='utf-8')
catalog=(root/'app/services/TelefoniaResultadoVentasCatalogo.php').read_text(encoding='utf-8')
service=(root/'app/services/TelefoniaResultadoVentasService.php').read_text(encoding='utf-8')
modal=(root/'app/views/telefonia/resultado_ventas_form.php').read_text(encoding='utf-8')
assert "public const VERSION = '4'" in catalog
assert "completarRegistros(mysqli $db,array $registros)" in catalog
assert "catalogo_version" in catalog and "contacto_persona" in catalog
assert "data-sales-result-picker" in modal and "data-sales-contact-person" in modal
assert "picked.includes('REQUIERE_SEGUIMIENTO')" in js
assert "nextDate.required=seguimiento" in js
assert "const contactPerson=form.elements.namedItem('contacto_persona')" in js
assert "normalizarSeleccion($datos)" in service
assert "in_array($datos['catalogo_version'],['3','4'],true)" in service
assert "TelefoniaResultadoVentasService::esConversacion($resultado)" in report
assert "GROUP BY e.pbx_call_id, e.internal" in report
assert "LEFT JOIN telefonia_ventas_resultado_selecciones" not in report
assert "$stats['en_seguimiento_ventas']++" in report
assert "$ref['en_seguimiento_ventas']++" in report
assert "'CONVERSACION_PERSONA' => 'Se habló con el prospecto'" in view
assert "'REQUIERE_SEGUIMIENTO' => 'En seguimiento'" in view
assert "En seguimiento · Ventas" in view
assert "$textoResultado = $call['proceso']==='Ventas'" in view
print("CHECK OK: historial v4, reporte PBX por tramo sin duplicados y etiquetas nuevas.")
