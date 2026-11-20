// Audio aislado, sin navegador, red, sesión ni base de datos del CRM.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/javascript/recordatorios.js'), 'utf8');
const start = source.indexOf('        const fechaRecordatorio =');
const end = source.indexOf('        const estadoVisibleRecordatorio =', start);
const now = Date.parse('2026-11-20T10:00:00');
let starts = [], resumed = 0;
const listeners = {};
const context = {
    Date: class extends Date { static now() { return now; } },
    window: { AudioContext: class {
        constructor() { this.state = 'suspended'; this.currentTime = 0; this.destination = {}; }
        resume() { resumed++; this.state = 'running'; return Promise.resolve(); }
        createOscillator() {
            return { frequency: {}, connect() {}, disconnect() {},
                start(at) { starts.push(at); }, stop() {} };
        }
        createGain() {
            return { gain: { setValueAtTime() {}, linearRampToValueAtTime() {} }, connect() {}, disconnect() {} };
        }
    } },
    document: { hidden: false, addEventListener(name, fn) { listeners[name] = fn; } }
};
vm.createContext(context);
vm.runInContext(source.slice(start, end) + '\nthis.play = sonarProximaAccion; this.title = tituloProximaAccion;', context);
const pending = { fecha: '2026-11-20 11:00:00', estado: 'proxima' };
for (const [minutes, expected] of [[31, '31 minutos'], [1, '1 minuto'], [60, '1 hora'], [61, '1 hora y 1 minuto'], [180, '3 horas']]) {
    const title = context.title({ accion: 'Volver a llamar', alerta_at: (now + minutes * 60000) / 1000 });
    assert.equal(title, 'Volver a llamar · En ' + expected);
}
context.play({ tipo: '1H' }, pending);
assert.equal(starts.length, 0, 'No reproduce antes de un gesto');
listeners.pointerdown({ isTrusted: false });
assert.equal(resumed, 0, 'Un gesto sintético no habilita audio');
listeners.pointerdown({ isTrusted: true });
assert.equal(resumed, 1);
// Incluye los intervalos actuales y otros posibles emitidos por el servidor.
// Esto no configura ni genera avisos de 30/5 minutos en el sistema.
for (const tipo of ['3H', '1H', '30M', '10M', '5M', '2H', '15M']) {
    starts = [];
    context.play({ tipo }, pending);
    assert.equal(starts.length, 3, 'Un aviso vigente reproduce el aviso musical: ' + tipo);
}
for (const fecha of ['2026-11-19 11:00:00', '2026-11-20 09:59:59', '2026-11-20 10:00:00', '', 'inválida']) {
    starts = [];
    // Incluye aviso encolado cuando era futuro y mostrado después de vencer.
    context.play({ tipo: '10M', fecha: pending.fecha }, { ...pending, fecha });
    assert.equal(starts.length, 0, 'No suena una fecha vencida o inválida: ' + fecha);
}
for (const tipo of ['VENCIDA', 'OBSERVACION', 'TELEFONIA_PERDIDA', 'REUNION_SOLICITADA', '', '0M', '-5M', '5M_VENCIDA']) {
    starts = [];
    context.play({ tipo }, pending);
    assert.equal(starts.length, 0, 'No altera el audio de otras notificaciones: ' + tipo);
}
for (const changes of [{ estado: 'vencida' }, { leida: 1 }]) {
    starts = [];
    context.play({ tipo: '1H' }, { ...pending, ...changes });
    assert.equal(starts.length, 0);
}
context.document.hidden = true;
starts = [];
context.play({ tipo: '1H' }, pending);
assert.equal(starts.length, 0, 'Pestaña oculta sin audio');
context.document.hidden = false;
listeners.keydown({ isTrusted: true });
assert.equal(starts.length, 0, 'No deja sonidos pendientes para otro gesto');

// Ejecutar también la rotación real: el sonido sólo acompaña un toast mostrado.
const rotationStart = source.indexOf('        const mostrarProximaAccion =');
const rotationEnd = source.indexOf('        const ultimoTurno =', rotationStart);
let visible = true, turns = 0;
const delivered = new Map();
Object.assign(context, {
    claveUsuario: ':usuario:5', toastActivo: null,
    claveRecordatorio: r => String(r.id),
    esRecordatorioVencidoRecurrente: r => r.estado === 'vencida',
    leerSesion: key => delivered.get(key) || '', guardarSesion: (key, value) => delivered.set(key, value),
    CLAVE_ULTIMO_TOAST_VENCIDO_ID: 'last', CLAVE_COLA_AVISOS: 'queue',
    mostrarToast: () => visible,
    registrarToastVencido: () => turns++,
    etiquetaVisibleRecordatorio: () => ''
});
vm.runInContext(source.slice(rotationStart, rotationEnd) + '\nthis.rotate = mostrarProximaAccion;', context);
// Las tres fuentes operativas comparten el presentador, sin filtro de rol de audio.
for (const sourceName of ['seguimiento', 'acuerdos', 'aliados']) {
    delivered.clear();
    for (const tipo of ['3H', '1H', '30M', '10M', '5M']) {
        context.recordatoriosActuales = [{ ...pending, id: 12, accion: sourceName }];
        context.colaAvisos = [{ clave: '12', aviso: { tipo } }];
        starts = []; const before = turns;
        assert.equal(context.rotate(), true);
        assert.equal(starts.length, 3, sourceName + ': suena al presentar ' + tipo);
        assert.equal(turns, before + 1, 'Conserva el consumo de un solo turno');
        assert.equal(context.colaAvisos.length, 0, 'Conserva el retiro del aviso mostrado');
        assert.equal(context.rotate(), false, 'Un anticipo mostrado no se repite');
    }
}
context.colaAvisos = [{ clave: '12', aviso: { tipo: '10M' } }];
delivered.clear();
visible = false; starts = []; const before = turns;
assert.equal(context.rotate(), false);
assert.equal(starts.length, 0, 'Toast que no se pudo mostrar no suena');
assert.equal(turns, before, 'No consume el turno');
assert.equal(context.colaAvisos.length, 1, 'Conserva la cola');
visible = true;
context.recordatoriosActuales[0].fecha = '2026-11-20 09:59:59';
assert.equal(context.rotate(), false, 'Un anticipo vencido no se entrega como vigente');
assert.equal(starts.length, 0, 'Aviso que venció en cola se muestra sin sonido');
context.recordatoriosActuales = [];
assert.equal(context.rotate(), false, 'Una acción resuelta no genera sonido');
context.recordatoriosActuales = [{ ...pending, id: 13, tipo_anticipo: '1H' }];
context.colaAvisos = [];
assert.equal(context.rotate(), true, 'Recupera desde campana aunque el aviso de BD ya se envió');
assert.equal(context.rotate(), false, 'Recargar con el mismo almacenamiento no duplica el anticipo');
context.window.AudioContext = undefined;
assert.doesNotThrow(() => listeners.pointerdown({ isTrusted: true }));
console.log('OK: todos los anticipos en horas/minutos con sonido; vencidas, cola caducada, leídas y otros avisos sin sonido.');
