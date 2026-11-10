#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Regresiones no negociables de Hostinger en el despliegue 2026-11-10."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
read=lambda p:(root/p).read_text(encoding="utf-8")
js=read("public/javascript/telefonia_ventas_resultados.js")
form=read("app/views/telefonia/resultado_ventas_form.php")
service=read("app/services/TelefoniaResultadoVentasService.php")
control=read("app/services/TelefoniaControlService.php")
report=read("app/views/telefonia/index.php")
catalog=read("app/services/TelefoniaResultadoVentasCatalogo.php")
assert "IMPE_EMBUDO_ASIGNACION_RESULTADO_V1" in js
assert "root.dataset.salesAssignmentId" in js
assert "form.elements.prospecto_asignacion_id.value" in js
assert js.index("form.elements.prospecto_asignacion_id.value")<js.index("if(fields)fields.hidden=false")
assert 'name="prospecto_asignacion_id"' in form
assert "IMPE_EMBUDO_PBX_POSTCOMMIT_V1" in service
assert service.index("$this->db->commit();")<service.index("IMPE_EMBUDO_PBX_POSTCOMMIT_V1")
assert "EmbudoComercialResultadoBridge($this->db)" in service
assert "error_log('[embudo_resultado_pbx]'" in service
assert "TelefoniaExtensionService($this->db)" in control
assert "new TelefoniaResultadoVentasService($this->db)" in control
assert "(new ZadarmaWebhookEventStoreService())->asegurarEstructura()" not in control
assert "data-control-auto-filters" in report
assert "data-control-clear" in report
assert "data-control-filter-loading" in report
assert "data-control-filter-message" in report
assert "telefonia_control_filtros_v2.css" in report
assert "puedeAdministrarExtensiones" in report
assert "call['estado_pbx']" in report
assert "call['resultado_estado']" in report
assert "En seguimiento · Ventas" in report
assert "REQUIERE_SEGUIMIENTO" in report
assert "CONVERSACION_PERSONA" in report
assert "GROUP BY e.pbx_call_id, e.internal" in control
assert "LEFT JOIN telefonia_ventas_resultado_selecciones" not in control
assert "const contacto=" not in js or "data-sales-contact-person" in form
assert "data-sales-contact-person" in form
assert "public const VERSION = '4'" in catalog
assert "completarRegistros(mysqli $db,array $registros)" in catalog
assert "'CONVERSACION_PERSONA'=>'Se habló con el prospecto'" in catalog
assert "'REQUIERE_SEGUIMIENTO'=>'En seguimiento'" in catalog
print("CHECK OK: contexto embudo, postcommit PBX, catálogo V4, filtros, permisos y estados del reporte preservados.")
