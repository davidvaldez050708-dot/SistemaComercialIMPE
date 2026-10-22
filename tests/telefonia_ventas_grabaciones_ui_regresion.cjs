'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const get=p=>fs.readFileSync(p,'utf8');
const salesView=get('app/views/telefonia/ventas_llamadas.php');
const salesUi=get('public/javascript/telefonia_ventas_resultados.js');
const receptionUi=get('public/javascript/telefonia_recepcion_marketing.js');
const salesApi=get('prueba_telefonia/api/grabacion_ventas.php');
const receptionApi=get('prueba_telefonia/api/grabacion_transferida_pbx.php');
const service=get('app/services/TelefoniaGrabacionesTransferenciaVentasService.php');
assert.match(salesView,/data-sales-transferida=/,'Ventas distingue la llamada transferida');
assert.match(salesView,/Consultar grabaciones/,'Botón de dos tramos visible sin END');
assert.match(salesUi,/const transferred=audio\.dataset\.salesTransferida==='1'/,
 'No inferir transferencia por DOM o número');
assert.match(salesUi,/if\(root\.dataset\.evidenceUrl&&!transferred\)/,
 'La reconciliación antigua que exige END no bloquea audios transferidos');
assert.match(receptionUi,/info\.segmentos, info\.segmentos_info\|\|\[\]/,
 'Recepción muestra metadatos por segmento');
assert.match(salesApi,/\$transferida[\s\S]*?\$svc->resolver\(\$pbxCallId/,
 'Ventas resuelve su grupo PBX autorizado');
assert.match(salesApi,/\}else\{\s*\$ids=\$call\['segmentos'\]/,
 'Marcaciones individuales siguen usando su camino anterior');
assert.match(receptionApi,/if\(\$comercial->operacionComercial/,
 'Recepción usa nuevo servicio sólo en convenios comerciales');
assert.match(receptionApi,/else\{[\s\S]*?\$receiver=/,
 'Reproductor del Analista conserva su implementación');
assert.match(service,/NOTIFY_RECORD/);
assert.match(service,/NOTIFY_ANSWER/);
console.log('OK: ventas y recepción comparten tramos 100/150, individuales y Analista sin cambio de ruta');
