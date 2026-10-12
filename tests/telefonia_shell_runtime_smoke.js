'use strict';
/* Pruebas de ciclo de vida sin navegador real ni conexión externa. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(
    require('node:path').join(__dirname, '../public/javascript/telefonia_shell.js'),
    'utf8'
);

const storage = new Map();
function boot(opts = {}) {
    const elements = {};
    const classes = new Set();
    const element = () => {
        const ownClasses = new Set();
        return {
        hidden: false, textContent: '', dataset: {}, events: {},
        children: [], contentWindow: {}, title: '', style: {},
        classList: {
            add(k) {ownClasses.add(k);},
            remove(k) {ownClasses.delete(k);},
            toggle(k, on) {
                if (on) {classes.add(k);ownClasses.add(k);}
                else {classes.delete(k);ownClasses.delete(k);}
            },
            contains(k) { return ownClasses.has(k); }
        },
        addEventListener(k, fn) { this.events[k] = fn; },
        setAttribute(k, value) { this[k] = String(value); },
        removeAttribute(k) { if (k === 'hidden') this.hidden = false; },
        appendChild(node) { this.children.push(node); },
        remove() { this.removed = true; },
        getBoundingClientRect() {
            return {left:50,top:50,width:380,height:300};
        },
        setPointerCapture() {}
    };};
    const selectors = [
        '[data-shell-call]','[data-shell-host-region]','[data-shell-status]',
        '[data-shell-status-text]','[data-shell-retry]','[data-shell-restore]',
        '[data-shell-call-title]','[data-shell-call-status]',
        '[data-shell-minimize]', '[data-shell-call-help]',
        '[data-incoming-panel]','[data-incoming-number]',
        '[data-incoming-state]','[data-incoming-title]',
        '[data-incoming-eyebrow]','[data-incoming-timer]',
        '[data-incoming-answer]','[data-incoming-decline]',
        '[data-incoming-mute]','[data-incoming-hangup]',
        '[data-incoming-notice]','[data-incoming-minimize]',
        '[data-incoming-answer-compact]','[data-incoming-decline-compact]',
        '[data-incoming-mute-compact]','[data-incoming-hangup-compact]',
        '[data-incoming-drag-handle]','[data-incoming-compact-timer]'
    ];
    selectors.forEach(x => elements[x] = element());
    elements['impeCrmFrame'] = element();
    elements['impeCrmFrame'].contentWindow = {location:{href:'https://crm.ejemplo.org/index.php?controller=home&action=index'}};
    const intervals = [];
    const windowEvents = {};
    const win = {
        IMPE_PHONE_SHELL_CONFIG: {
            userId: 8, extension: '100',
            hostUrl: 'https://crm.ejemplo.org/index.php?controller=telefonia&action=host&display=embed',
            loginUrl: 'https://crm.ejemplo.org/index.php?controller=login&action=mostrarLogin',
            autoReception: opts.auto !== false
        },
        location: {origin:'https://crm.ejemplo.org', replace(url) {this.redirect = url;}},
        addEventListener(k,fn) {windowEvents[k] = fn;},
        innerWidth:1280, innerHeight:780,
        setInterval(fn) {intervals.push(fn); return intervals.length;}
    };
    const doc = {
        querySelector(k) {return elements[k];},
        getElementById(k) {return elements[k];},
        createElement() {return element();},
        title: ''
    };
    const ls = {
        getItem(k) {return storage.get(k) ?? null;},
        setItem(k,v) {storage.set(k,String(v));},
        removeItem(k) {storage.delete(k);}
    };
    const channels = [];
    class BroadcastChannel {
        constructor() {channels.push(this);this.events={};}
        addEventListener(k,fn) {this.events[k] = fn;}
        close() {}
        message(value) {this.events.message?.({data:value});}
    }
    const ctx = vm.createContext({
        window:win,document:doc,localStorage:ls,BroadcastChannel,URL,
        Date:opts.dateClass || Date,Math,JSON,
        console
    });
    vm.runInContext(source,ctx);
    return {window:win,elements,classes,intervals,channels,windowEvents};
}

storage.clear();
const first = boot();
assert.equal(first.elements['[data-shell-host-region]'].children.length,1,
    'marketing auto-mounts engine');
assert.match(first.elements['[data-shell-host-region]'].children[0].src,/display=embed/);
assert.ok(first.window.IMPE_TELEPHONY_SHELL.isEmbedded);
assert.equal(typeof first.window.IMPE_TELEPHONY_SHELL.ensureHost,'function');
assert.strictEqual(first.window.IMPE_TELEPHONY_SHELL.ensureHost(),
    first.elements['[data-shell-host-region]'].children[0].contentWindow);

const active = {type:'STATE',state:{
    userId:8,updatedAt:Date.now(),hostReady:true,active:true,
    direction:'incoming',destination:'5512345678',phase:'in-progress'
}};
first.channels[0].message(active);
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),false,
    'incoming call hides original Zadarma panel');
assert.equal(first.elements['[data-incoming-panel]'].hidden,false,
    'incoming call shows CRM modal');
assert.match(first.elements['[data-shell-call-status]'].textContent,/5512345678/);
first.elements['[data-incoming-minimize]'].events.click();
assert.equal(first.elements['[data-incoming-panel]'].hidden,false,
    'minimizing retains CRM modal, in compact mode');
assert.equal(first.elements['[data-incoming-panel]'].classList.contains('is-compact'),true);
assert.equal(first.elements['[data-shell-restore]'].hidden,true,
    'compact mode needs no extra restore button');
assert.equal(first.elements['[data-shell-host-region]'].children.length,1);

first.elements['[data-incoming-minimize]'].events.click();
assert.equal(first.elements['[data-incoming-panel]'].classList.contains('is-compact'),false);
assert.equal(first.elements['[data-incoming-panel]'].hidden,false);
const handle=first.elements['[data-incoming-drag-handle]'];
handle.events.pointerdown({button:0,pointerId:11,clientX:100,clientY:100,
    target:{closest(){return false;}},preventDefault(){}});
handle.events.pointermove({pointerId:11,clientX:190,clientY:200,
    preventDefault(){}});
handle.events.pointerup({pointerId:11});
assert.equal(first.elements['[data-incoming-panel]'].style.left,'140px',
    'dragging moves the modal horizontally');
assert.equal(first.elements['[data-incoming-panel]'].style.top,'150px',
    'dragging moves the modal vertically');
first.channels[0].message({type:'STATE',state:{
    userId:8,updatedAt:Date.now(),hostReady:true,active:true,
    direction:'outgoing',destination:'5512345678',phase:'in-progress',
    context:{type:'TELEPHONY_MANUAL'}
}});
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),false,
    'salientes sin segundo widget blanco Zadarma');
assert.equal(first.elements['[data-incoming-panel]'].hidden,false,
    'salientes muestran el mismo panel CRM arrastrable');
assert.equal(first.elements['[data-incoming-eyebrow]'].textContent,'LLAMADA SALIENTE');
assert.equal(first.elements['[data-incoming-hangup]'].hidden,false,
    'salientes pueden colgar desde el panel único');
assert.equal(first.elements['[data-incoming-answer]'].hidden,true,
    'salientes nunca muestran Contestar');
// Una gestión de Seguimiento mantiene su tarjeta de registro propia.
first.channels[0].message({type:'STATE',state:{
    userId:8,updatedAt:Date.now(),hostReady:true,active:true,
    direction:'outgoing',destination:'5512345678',phase:'in-progress',
    context:{type:'SEGUIMIENTO'}
}});
assert.equal(first.elements['[data-incoming-panel]'].hidden,true,
    'seguimiento no recibe una tarjeta saliente duplicada');
first.channels[0].message({type:'STATE',state:{
    userId:8,updatedAt:Date.now(),hostReady:true,active:true,
    direction:'outgoing',destination:'5512345678',phase:'in-progress',
    context:{type:'TELEPHONY_MANUAL'}
}});
first.windowEvents.message({
    origin:'https://crm.ejemplo.org',
    source:first.elements['[data-shell-host-region]'].children[0].contentWindow,
    data:{type:'IMPE_TELEPHONY_NATIVE_CONTROLS',userId:8}
});
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),true,
    'transfer fallback deliberately shows official Zadarma controls');
first.elements['[data-shell-minimize]'].events.click();
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),false,
    'user can minimize provider transfer controls without hanging up');
// Colgar desde el modal saliente NO desmonta el iframe ni abre otro SIP.
const outgoingActions=[];
first.elements['impeCrmFrame'].contentWindow.IMPE_TELEPHONY_PERSISTENT = {
    hangup(){outgoingActions.push('hangup');}
};
first.elements['[data-incoming-hangup]'].events.click();
assert.deepEqual(outgoingActions,['hangup'],
    'Colgar usa el controlador persistente real del CRM');
assert.equal(first.elements['[data-incoming-panel]'].hidden,true,
    'Colgar oculta inmediatamente el modal sin una segunda interfaz');
assert.equal(first.elements['[data-shell-host-region]'].children.length,1,
    'tras Colgar el SDK sigue en el mismo iframe para recibir entrantes');

const second = boot();
assert.equal(second.elements['[data-shell-host-region]'].children.length,0,
    'second tab does not register same SIP extension');
assert.throws(() => second.window.IMPE_TELEPHONY_SHELL.ensureHost());

storage.clear();
storage.set('impe:telephony:auto-reception:8','0');
const optedOut = boot();
assert.equal(optedOut.elements['[data-shell-host-region]'].children.length,0,
    'auto-activation may be disabled per user');
optedOut.window.IMPE_TELEPHONY_SHELL.ensureHost();
assert.equal(optedOut.elements['[data-shell-host-region]'].children.length,1,
    'manual activation still works');
// Dos entrantes consecutivas: el timbrado nunca hereda el contador previo
// y los controles responden de inmediato sin mensajes de éxito innecesarios.
storage.clear();
let mockNow = Date.now();
class ClockDate extends Date {static now() {return mockNow;}}
const quick = boot({dateClass:ClockDate});
const ops = [];
const frame = quick.elements['[data-shell-host-region]'].children[0];
frame.contentWindow.IMPE_ZADARMA_INCOMING = {
    answer() {ops.push('answer');return true;},
    decline() {ops.push('decline');return true;},
    hangup() {ops.push('hangup');return true;},
    error() {return 'Error SDK';}
};
const push = (callId,phase,active,duration) => quick.channels[0].message({
    type:'STATE',
    state:{userId:8,updatedAt:mockNow,hostReady:true,active,
        direction:'incoming',destination:'5512345678',phase,
        pbxCallId:callId,duration}
});
push('call-uno','incoming-ringing',true,24);
assert.equal(quick.elements['[data-incoming-timer]'].textContent,'00:00',
    'el timbrado no hereda la duración anterior');
quick.elements['[data-incoming-answer]'].events.click();
assert.deepEqual(ops,['answer']);
assert.equal(quick.elements['[data-incoming-notice]'].hidden,true,
    'sin mensaje rojo de Contestando tras una acción válida');
assert.equal(quick.elements['[data-incoming-answer]'].disabled,true,
    'no se permite enviar un doble clic al SDK');
push('call-uno','in-progress',true,1);
assert.equal(quick.elements['[data-incoming-hangup]'].hidden,false);
assert.equal(quick.elements['[data-incoming-hangup]'].disabled,false);
quick.elements['[data-incoming-hangup]'].events.click();
assert.deepEqual(ops,['answer','hangup']);
assert.equal(quick.elements['[data-incoming-panel]'].hidden,true,
    'la tarjeta se oculta de inmediato, sin esperar a PBX');
assert.equal(quick.elements['[data-incoming-notice]'].textContent,'',
    'no debe aparecer Finalizando llamada');
push('call-uno','incoming-finished',false,25);
push('call-dos','incoming-ringing',true,25);
assert.equal(quick.elements['[data-incoming-panel]'].hidden,false,
    'la nueva llamada reaparece inmediatamente');
assert.equal(quick.elements['[data-incoming-timer]'].textContent,'00:00',
    'la segunda llamada comienza desde cero');
assert.equal(quick.elements['[data-incoming-answer]'].disabled,false,
    'los botones están listos para la segunda llamada');
assert.equal(quick.elements['[data-incoming-notice]'].hidden,true,
    'sin avisos de la llamada anterior');
quick.elements['[data-incoming-answer]'].events.click();
assert.deepEqual(ops,['answer','hangup','answer'],
    'la segunda entrante acepta inmediatamente sin estados arrastrados');
push('call-dos','in-progress',true,2);
assert.equal(quick.elements['[data-incoming-timer]'].textContent,'00:02');
mockNow += 5000;
quick.intervals[0](); // El reloj local no espera el polling de la centralita.
assert.equal(quick.elements['[data-incoming-timer]'].textContent,'00:07',
    'el reloj transcurre de forma continua sin nuevo evento PBX');
push('call-dos','in-progress',true,13); // La PBX reporta una duración mayor.
assert.equal(quick.elements['[data-incoming-timer]'].textContent,'00:07',
    'un evento atrasado no provoca saltos de varios segundos');
assert.match(quick.elements['[data-incoming-mute]'].innerHTML,/bi-mic-mute/,
    'mismo icono de silenciar que Seguimiento');
assert.match(quick.elements['[data-incoming-mute]'].innerHTML,/<span>Silenciar<\/span>/,
    'misma estructura de contenido que Seguimiento');
push('call-dos','in-progress',true,14);
mockNow += 1000;
quick.intervals[0]();
assert.equal(quick.elements['[data-incoming-timer]'].textContent,'00:08');

console.log('Shell WebRTC: OK (llamadas consecutivas, contador 00:00, respuesta inmediata, cierre y lease)');
