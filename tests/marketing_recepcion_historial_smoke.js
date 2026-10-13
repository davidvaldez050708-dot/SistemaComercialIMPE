'use strict';
const assert = require('node:assert/strict');
const {normalizarNumeroDevolucion,puedeLlamarAlRegistro} =
    require('../public/javascript/telefonia_recepcion_marketing.js');

for(const estado of ['Atendida','Perdida','Transferida','En curso','Sonando','Completada','Sin respuesta','Ocupada','Cancelada']) {
    assert.equal(puedeLlamarAlRegistro({estado,numero:'+52 777 788 5173'}),true,
        'llamar debe mostrarse independientemente del estado: '+estado);
}
assert.equal(normalizarNumeroDevolucion('7777885173'),'527777885173');
assert.equal(puedeLlamarAlRegistro({estado:'Atendida',numero:'0000000000'}),false);
assert.equal(puedeLlamarAlRegistro({estado:'Perdida',numero:'Privado'}),false);
assert.equal(puedeLlamarAlRegistro({estado:'Transferida'}),false);
console.log('Marketing · Llamar: OK (todas las filas, formato y número válido)');
