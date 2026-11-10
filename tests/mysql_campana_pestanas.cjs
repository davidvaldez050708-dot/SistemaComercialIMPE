// Ejecuta el coordinador de la copia conciliada con red, Web Locks y bus simulados.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const fuente = fs.readFileSync(process.argv[2], 'utf8');
const inicio = fuente.indexOf('        const tokenCampana =');
const fin = fuente.indexOf('\n        consultarRecordatorios();', inicio);
assert.ok(inicio >= 0 && fin > inicio);
const bloque = fuente.slice(inicio, fin);
const mensajes = new Map();
const activos = new Map();
const pausas = [];
let solicitudes = 0;
let reintentos = 0;
let caida = false;
const respuesta = {ok: true, recordatorios: [{id: 1, pendiente_operativo: true}], avisos: []};

class Canal {
    constructor(clave) {
        this.clave = clave;
        if (!mensajes.has(clave)) mensajes.set(clave, []);
        mensajes.get(clave).push(this);
    }
    addEventListener(_, fn) { this.recibir = fn; }
    postMessage(data) {
        for (const otro of mensajes.get(this.clave)) {
            if (otro !== this) otro.recibir({data});
        }
    }
}

const locks = {
    async request(clave, opciones, fn) {
        while (activos.has(clave)) {
            if (opciones.ifAvailable) return fn(null);
            await activos.get(clave);
        }
        let liberar;
        activos.set(clave, new Promise(resolve => { liberar = resolve; }));
        try { return await fn({}); }
        finally { activos.delete(clave); liberar(); }
    }
};

function pestaña(token) {
    const recibidos = [];
    const contexto = {
        window: {IMPE_CSRF_TOKEN: token, BroadcastChannel: Canal, navigator: {locks}},
        claveUsuario: ':usuario:3',
        endpoint: '/pendientes',
        consultaEnCurso: false,
        document: {hidden: false},
        cargaInicialCompletada: false,
        fallosDeCarga: 0,
        procesarDatosCampana(data) { recibidos.push(data); contexto.cargaInicialCompletada = true; },
        mostrarFalloCarga() {},
        programarReintentoCarga() { reintentos++; },
        console: {error() {}},
        async fetch() {
            solicitudes++;
            await new Promise(resolve => pausas.push(resolve));
            return {ok: !caida, status: caida ? 503 : 200, json: async () => respuesta};
        }
    };
    vm.createContext(contexto);
    vm.runInContext(bloque, contexto);
    return {recibidos, consultar: explicita => vm.runInContext('consultarRecordatorios(' + Boolean(explicita) + ')', contexto)};
}

(async function () {
    const a = pestaña('sesion-a');
    const b = pestaña('sesion-a');
    const ajena = pestaña('sesion-b');
    const solicitudesIniciales = Promise.all([a.consultar(), b.consultar()]);
    assert.equal(solicitudes, 1);
    pausas.shift()();
    await solicitudesIniciales;
    assert.deepEqual(a.recibidos, [respuesta]);
    assert.deepEqual(b.recibidos, [respuesta]);
    assert.deepEqual(ajena.recibidos, []);

    caida = true;
    const reintentosPrevios = reintentos;
    const fallo = ajena.consultar();
    pausas.shift()();
    await fallo;
    assert.deepEqual(ajena.recibidos, []);
    assert.equal(reintentos, reintentosPrevios + 1);
    caida = false;
    const recuperacion = ajena.consultar();
    pausas.shift()();
    await recuperacion;
    assert.deepEqual(ajena.recibidos, [respuesta]);

    const rutinaria = a.consultar();
    const explicita = b.consultar(true);
    pausas.shift()();
    await rutinaria;
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(pausas.length, 1, 'Un refresco explícito espera una consulta propia');
    pausas.shift()();
    await explicita;
    assert.ok(b.recibidos.every(item => item.recordatorios[0].pendiente_operativo));
    console.log('Campana: deduplicación, aislamiento de sesión, refresco explícito y recuperación 503 verificados.');
})().catch(error => { console.error(error); process.exitCode = 1; });
