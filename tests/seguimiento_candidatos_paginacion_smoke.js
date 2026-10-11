'use strict';
const assert = require('node:assert/strict');
const {
    crearPagina, crearFirma
} = require('../public/javascript/seguimiento_candidatos_paginacion.js');

const candidatos = Array.from({length: 75}, (_, i) => ({
    clave_origen: 'DENUE-' + String(i + 1).padStart(3, '0'),
    nombre: 'Establecimiento ' + (i + 1),
    seguimiento_existente: i === 40,
    seguimiento_url: i === 40 ? 'index.php?seguimiento=41' : null
}));
const original = candidatos.map(x => x.clave_origen).join('|');

for (let numeroPagina = 1; numeroPagina <= 8; numeroPagina++) {
    const pagina = crearPagina(candidatos, 75, numeroPagina, 10);
    assert.ok(pagina, 'página válida ' + numeroPagina);
    assert.equal(pagina.pagina, numeroPagina);
    assert.equal(pagina.total_resultados, 75);
    assert.equal(pagina.resultados_por_pagina, 10);
    assert.equal(pagina.candidatos.length, numeroPagina === 8 ? 5 : 10);
    assert.equal(pagina.hay_mas, numeroPagina < 8);
    assert.equal(pagina.candidatos[0].clave_origen,
        candidatos[(numeroPagina - 1) * 10].clave_origen);
}

assert.equal(crearPagina(candidatos, 75, 5, 10).candidatos[0].seguimiento_existente, true);
assert.equal(crearPagina(candidatos, 75, 5, 10).candidatos[0].seguimiento_url, 'index.php?seguimiento=41');
assert.equal(candidatos.map(x => x.clave_origen).join('|'), original, 'no mutar orden');
assert.equal(crearPagina(candidatos, 74, 2, 10), null, 'total parcial/no confiable');
assert.equal(crearPagina(candidatos, 75, 9, 10), null, 'fuera de rango');
assert.equal(crearPagina(candidatos, 75, 0, 10), null, 'página cero');
assert.equal(crearPagina(candidatos, 75, 2, 0), null, 'límite cero');
assert.equal(crearPagina([], 0, 1, 10), null, 'lista vacía');
assert.equal(crearPagina(Array.from({length: 301}), 301, 1, 10), null, 'tope de memoria');
assert.equal(crearPagina(candidatos, 75, 1.1, 10), null, 'página entera');
assert.equal(crearFirma(7, 0, 'EMPRESAS', ''), '7|0|EMPRESAS|');
assert.notEqual(
    crearFirma(7, 0, 'EMPRESAS', ''),
    crearFirma(7, 1, 'EMPRESAS', '')
);
assert.notEqual(
    crearFirma(7, 0, 'EMPRESAS', ''),
    crearFirma(7, 0, 'INSTITUCIONES', '')
);
assert.notEqual(
    crearFirma(7, 0, 'EMPRESAS', ''),
    crearFirma(7, 0, 'EMPRESAS', 'universidad')
);
console.log('Paginación local: OK (75 resultados, 8 páginas, metadatos y filtros)');
