#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Guardas estáticas: reportes usan el resultado principal una sola vez por tramo PBX."""
from pathlib import Path
base=Path(__file__).resolve().parents[1]
report=(base/'app/services/TelefoniaControlService.php').read_text(encoding='utf-8')
view=(base/'app/views/telefonia/index.php').read_text(encoding='utf-8')
js=(base/'public/javascript/telefonia_ventas_resultados.js').read_text(encoding='utf-8')
catalog=(base/'app/services/TelefoniaResultadoVentasCatalogo.php').read_text(encoding='utf-8')
assert "GROUP BY e.pbx_call_id, e.internal" in report, "no duplicar atenciones por selección"
assert "LEFT JOIN telefonia_ventas_resultado_selecciones" not in report, "no duplicar JOIN"
assert "['CONVERSACION_PERSONA','REQUIERE_SEGUIMIENTO']" in report, "dos contactos para KPI"
assert "$call['resultado']==='REQUIERE_SEGUIMIENTO'" in report, "contar resultado principal"
assert "$ref['en_seguimiento_ventas']++" in report, "desglose por asesor"
assert "$stats['en_seguimiento_ventas']++" in report, "KPI general de seguimiento"
assert "'REQUIERE_SEGUIMIENTO' => 'En seguimiento'" in view, "etiqueta nueva"
assert "'CONVERSACION_PERSONA' => 'Se habló con el prospecto'" in view, "contacto legible"
assert "En seguimiento · Ventas" in view, "KPI y ranking"
assert "$textoResultado = $call['proceso']==='Ventas'" in view, "mostrar resultado original"
assert "picked.includes('REQUIERE_SEGUIMIENTO')" in js, "fecha obligatoria dinámica"
assert "nextDate.required=seguimiento" in js, "fecha obligatoria solo seguimiento"
assert "data-sales-result-picker" not in js or "resultRules();" in js, "mantiene selector existente"
assert "public const VERSION = '3'" in catalog, "no romper lectura de registros v3"
assert "resultados_seleccionados" in catalog, "guardar dos resultados"
print("CHECK OK: reporte PBX sin filas duplicadas; fecha y etiquetas visibles, selección v3 intacta.")
