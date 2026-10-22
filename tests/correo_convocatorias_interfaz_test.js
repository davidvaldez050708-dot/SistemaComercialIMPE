'use strict';
const assert = require('node:assert/strict');
const api = require('../public/javascript/correo_convocatorias.js');
class Field {
    constructor(select = false) {
        this.select = select; this.options = []; this._value = ''; this.listeners = new Map(); this.disabled = false;
        this.classes = new Set(); this.classList = {
            add: x => this.classes.add(x),
            toggle: (x, on) => on ? this.classes.add(x) : this.classes.delete(x)
        };
    }
    get value() { return this._value; }
    set value(v) { v = String(v); this._value = !this.select || this.options.some(o => o.value === v) ? v : ''; }
    replaceChildren(...options) { this.options = options; this.value = this._value; }
    appendChild(o) { this.options.push(o); }
    removeAttribute(name) { delete this[name]; }
    addEventListener(name, handler) { this.listeners.set(name, [...(this.listeners.get(name) || []), handler]); }
    async emit(name, detail) {
        const event = { detail, prevented: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() {} };
        await Promise.all((this.listeners.get(name) || []).map(f => f(event))); return event;
    }
}
const s = n => ({ id: n, nombre: n === 11 ? 'Guanajuato' : 'Sinaloa' });
const c = n => ({ id: n, nombre: n === 90 ? 'Alfa' : 'Beta', referencia: 'F-' + n });
const rows = [
    { id: 1, titulo: 'Handly', estados: [s(11), s(25)], convenios: [c(90)] },
    { id: 2, titulo: 'Handly', estados: [s(25)], convenios: [c(91)] },
    { id: 3, titulo: 'Sin imagen', estados: [s(11)], convenios: [] },
    { id: 4, titulo: 'Handly', estados: [s(11)], convenios: [c(91)] }
];
const ids = list => list.map(x => x.id);
assert.deepEqual(ids(api.filtrar(rows, 11, '')), [1, 3, 4]);
assert.deepEqual(ids(api.filtrar(rows, '', 90)), [1]);
assert.deepEqual(ids(api.filtrar(rows, 11, 91)), [4]);
assert.deepEqual(ids(api.filtrar(rows, 25, 90)), [1]);
assert.deepEqual(ids(api.filtrar(rows, 25, 999)), []);
assert.notEqual(api.etiqueta(rows[0]), api.etiqueta(rows[1]));
assert.match(api.etiqueta(rows[0]), /#1/);
const fields = {};
for (const name of ['estado', 'convenio', 'status', 'imagen-sha', 'preview', 'imagen', 'caption', 'limpiar']) {
    fields['[data-convocatoria-' + name + ']'] = new Field(['estado', 'convenio'].includes(name));
}
for (const name of ['convocatoria', 'form', 'draft', 'body', 'files']) {
    fields['[data-marketing-mail-' + name + ']'] = new Field(name === 'convocatoria');
}
const modal = new Field(), detail = new Field();
modal.querySelector = key => fields[key];
const detailFields = {};
for (const name of ['detalle', 'detalle-imagen', 'detalle-caption']) { detailFields['[data-convocatoria-' + name + ']'] = new Field(); }
detail.querySelector = key => detailFields[key];
const doc = { getElementById: id => id === 'modalCorreoMarketing' ? modal : detail, createElement: () => new Field() };
const get = name => fields['[data-convocatoria-' + name + ']'];
const select = fields['[data-marketing-mail-convocatoria]'];
fields['[data-marketing-mail-body]'].value = 'Texto del usuario sin cambios';
fields['[data-marketing-mail-files]'].files = ['manual.pdf'];
let calls = 0, delayed = null, delayNext = false;
const response = data => ({ ok: true, json: async () => data });
const full = id => ({ ...rows.find(x => x.id === id), imagen_sha: id === 3 ? '' : 'a'.repeat(64),
    imagen_url: id === 3 ? '' : 'index.php?controller=correoMarketing&action=imagenConvocatoria&id=' + id + '&v=' + 'a'.repeat(64),
    imagen_mensaje: id === 3 ? 'Sin imagen; puedes enviar.' : 'Imagen embebida' });
const ui = api.iniciar(doc, async url => {
    calls++;
    const match = url.match(/&id=(\d+)/);
    if (!match) { return response({ ok: true, estados: [s(11), s(25)], convocatorias: rows }); }
    const id = Number(match[1]);
    if (delayNext) { delayNext = false; return new Promise(resolve => { delayed = () => resolve(response({ ok: true, convocatoria: full(id) })); }); }
    return response({ ok: true, convocatoria: full(id) });
});
(async () => {
    await ui.load(); await ui.load(); assert.equal(calls, 1, 'Un único catálogo por página');
    get('estado').value = '11'; await get('estado').emit('change');
    assert.deepEqual(select.options.map(o => o.value), ['', '1', '3', '4']);
    get('convenio').value = '91'; await get('convenio').emit('change');
    assert.deepEqual(select.options.map(o => o.value), ['', '4']); assert.equal(calls, 1, 'Filtros sin consultas duplicadas');
    await get('limpiar').emit('click'); select.value = '1'; await select.emit('change');
    assert.match(get('imagen').src, /id=1&/); assert.equal(get('imagen-sha').value, 'a'.repeat(64));
    const sameSrc = get('imagen').src; await select.emit('change'); assert.equal(get('imagen').src, sameSrc, 'Sin duplicar nodos/imágenes');
    select.value = '2'; delayNext = true; const old = ui.choose();
    const blocked = await fields['[data-marketing-mail-form]'].emit('submit'); assert.equal(blocked.prevented, true, 'Esperar a la selección pendiente');
    select.value = '1'; await ui.choose(); delayed(); await old;
    assert.match(get('imagen').src, /id=1&/, 'Respuesta atrasada no sustituye la selección actual');
    select.value = '3'; await select.emit('change');
    assert.ok(get('preview').classes.has('d-none')); assert.match(get('status').textContent, /Sin imagen/);
    assert.equal((await fields['[data-marketing-mail-form]'].emit('submit')).prevented, false, 'Sin imagen no bloquea envío');
    await modal.emit('correo:convocatoria-limpiar');
    await modal.emit('correo:convocatoria-borrador', { ...full(1), filtro_estado: 11, filtro_convenio: 90 });
    assert.equal(get('estado').value, '11'); assert.equal(get('convenio').value, '90'); assert.equal(select.value, '1');
    assert.match(get('imagen').src, /id=1&/);
    get('estado').value = '25'; await get('estado').emit('change'); assert.equal(select.value, '1', 'Convocatoria con cobertura en ambos estados permanece seleccionada');
    get('convenio').value = '91'; await get('convenio').emit('change'); assert.equal(select.value, ''); assert.ok(get('preview').classes.has('d-none'));
    await detail.emit('correo:convocatoria-detalle', full(1)); assert.match(detailFields['[data-convocatoria-detalle-imagen]'].src, /id=1&/);
    await detail.emit('correo:convocatoria-detalle', null); assert.equal(detailFields['[data-convocatoria-detalle-imagen]'].src, undefined);
    await get('limpiar').emit('click'); assert.equal(select.value, ''); assert.equal(get('imagen-sha').value, '');
    assert.equal(fields['[data-marketing-mail-body]'].value, 'Texto del usuario sin cambios');
    assert.deepEqual(fields['[data-marketing-mail-files]'].files, ['manual.pdf']);
    console.log('OK: estado, convenio, combinación, cobertura múltiple, duplicados por ID, catálogo único, CID preview, sin duplicados, respuestas atrasadas, sin imagen, borrador, historial y texto/adjuntos conservados. DOM/API simulados.');
})().catch(error => { console.error(error); process.exitCode = 1; });
