'use strict';
const assert = require('node:assert/strict');
const {normalizarNumeroDevolucion,puedeLlamarAlRegistro,coincideEstadoRecepcion} =
    require('../public/javascript/telefonia_recepcion_marketing.js');

for(const estado of ['Atendida','Perdida','Transferida','En curso','Sonando','Completada','Sin respuesta','Ocupada','Cancelada']) {
    assert.equal(puedeLlamarAlRegistro({estado,numero:'+52 777 788 5173'}),true,
        'llamar debe mostrarse independientemente del estado: '+estado);
}
assert.equal(normalizarNumeroDevolucion('7777885173'),'527777885173');
assert.equal(puedeLlamarAlRegistro({estado:'Atendida',numero:'0000000000'}),false);
assert.equal(puedeLlamarAlRegistro({estado:'Perdida',numero:'Privado'}),false);
assert.equal(puedeLlamarAlRegistro({estado:'Transferida'}),false);
for (const [group,states] of Object.entries({
    respondidas:['Atendida','Completada'],
    'sin-respuesta':['Perdida','Sin respuesta'],
    activas:['En curso','Sonando','Marcando'],
    'no-completadas':['Ocupada','Cancelada','Fallida','Número inexistente','No disponible','Sin saldo','Límite excedido'],
    Transferida:['Transferida']
})) {
    for(const estado of states) {
        assert.equal(coincideEstadoRecepcion({estado},group),true,'clasificar '+estado);
        assert.equal(coincideEstadoRecepcion({estado},''),true,'todos incluye '+estado);
    }
}
assert.equal(coincideEstadoRecepcion({estado:'Transferida'},'respondidas'),false);
assert.equal(coincideEstadoRecepcion({finalizada:true,resultado:''},'pendientes'),true);
assert.equal(coincideEstadoRecepcion({finalizada:false,resultado:''},'pendientes'),false);
assert.equal(coincideEstadoRecepcion({finalizada:true,resultado:'BUZON'},'pendientes'),false);
assert.equal(coincideEstadoRecepcion({finalizada:true,resultado:'SIN_CLASIFICAR'},'pendientes'),false);
console.log('Marketing · Llamar: OK (todas las filas, formato y número válido)');
