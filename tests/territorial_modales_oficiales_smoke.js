/* Pruebas sin navegador, red ni base de datos para los modales oficiales. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const raiz = path.join(__dirname, '..');
const { clasificarTipo } = require(
    path.join(raiz, 'public/javascript/data_territorial_modal_oficial.js')
);

const categorias = {
    poblacion: 'territorio',
    municipios: 'territorio',
    actividad: 'economia',
    actividad_municipal: 'economia',
    escolaridad_adulta: 'educacion',
    contexto_educativo_general: 'educacion',
    perfil_educativo_prioritario: 'educacion',
    perfil_adulto_laboral: 'educacion',
    poder_adquisitivo: 'importaciones',
    rezago_educativo: 'importaciones'
};
for (const [tipo, esperado] of Object.entries(categorias)) {
    assert.equal(clasificarTipo(tipo, 'Cualquier fuente'), esperado, tipo);
}
// Producción tiene un indicador 15+ todavía no incorporado a main;
// no debe perder visibilidad ni quedar en una categoría incorrecta.
assert.equal(clasificarTipo('perfil_educativo_general_15', ''), 'educacion');
assert.equal(clasificarTipo('perfil_educativo_general_15_mas', ''), 'educacion');
assert.equal(clasificarTipo('perfil_general', 'Perfil educativo general 15+'), 'educacion');
assert.equal(clasificarTipo('fuente-nueva', 'Fuente de municipios'), 'territorio');
assert.equal(clasificarTipo('fuente-nueva', 'Otros indicadores'), 'otras');

const css = fs.readFileSync(
    path.join(raiz, 'public/css/responsive_sistema.css'), 'utf8'
);
const js = fs.readFileSync(
    path.join(raiz, 'public/javascript/data_territorial_modal_oficial.js'), 'utf8'
);
const pie = fs.readFileSync(
    path.join(raiz, 'app/views/layout/dashboard_footer.php'), 'utf8'
);
const vista = fs.readFileSync(
    path.join(raiz, 'app/views/data_territorial/index.php'), 'utf8'
);
for (const modal of [
    'modalActualizarInformacionEstados',
    'modalActualizarInformacionOficial'
]) {
    assert.match(css, new RegExp('#' + modal + ' \\.modal-dialog'));
    assert.match(css, new RegExp('#' + modal + ' \\.system-form-modal > \\.modal-body'));
    assert.match(vista, new RegExp('id="' + modal + '"'));
}
assert.match(css, /max-height: calc\(100dvh - 32px\)/);
assert.match(css, /\.data-official-option-list \[hidden\]/);
assert.match(js, /opcion\.tarjeta\.hidden = opcion\.grupo !== activo/);
assert.match(js, /opcion\.casilla\.addEventListener\('change', refrescar\)/);
assert.match(js, /indicadores\.textContent = seleccionadas/);
assert.match(js, /opcionesDisponibles\.textContent = String\(opciones\.length\)/);
assert.match(pie, /'data_territorial_modal_oficial\.js'/);
// La vista sigue manteniendo todas las 10 acciones documentadas en main.
assert.equal((vista.match(/data-official-options="mass"/g) || []).length, 1);
for (const tipo of Object.keys(categorias)) {
    assert.ok(vista.includes('value="' + tipo + '"'), 'falta ' + tipo);
}

console.log('Modales oficiales: OK (10 opciones, compatibilidad 15+, dimensiones y filtros)');
