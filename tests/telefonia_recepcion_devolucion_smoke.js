'use strict';
const assert = require('node:assert/strict');
const {
    normalizarNumeroDevolucion
} = require('../public/javascript/telefonia_recepcion_marketing.js');

assert.equal(normalizarNumeroDevolucion('5512345678'), '525512345678');
assert.equal(normalizarNumeroDevolucion('+52 55 1234 5678'), '525512345678');
assert.equal(normalizarNumeroDevolucion('52 (55) 1234-5678'), '525512345678');
assert.equal(normalizarNumeroDevolucion(''), '');
assert.equal(normalizarNumeroDevolucion('100'), '');
assert.equal(normalizarNumeroDevolucion('555-12'), '');
assert.equal(normalizarNumeroDevolucion('Número privado'), '');
assert.equal(normalizarNumeroDevolucion('00 52 55 1234 5678'), '');
assert.equal(normalizarNumeroDevolucion('1<script>'), '');
assert.equal(normalizarNumeroDevolucion('0000000000'), '');
assert.equal(normalizarNumeroDevolucion('5215512345678'), '525512345678'); // antiguo +52 1 móvil convertido al formato vigente

console.log('Recepción Marketing: OK (normalización, bloqueo de internos y formato)');
