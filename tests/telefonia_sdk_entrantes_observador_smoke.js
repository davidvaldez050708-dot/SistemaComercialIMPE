'use strict';
const assert = require('node:assert/strict');
const path = require('node:path');
const factory = require(path.join(__dirname,
    '../public/javascript/telefonia_sdk_entrantes_observador.js'));

let observer;
let interval = null;
const value = {number: '', callerName: '', inputIncoming: false, ringing: false, hangupActive:false};
const doc = {
    defaultView: {
        MutationObserver: class {
            constructor(cb) { this.cb = cb; observer = this; }
            observe() { this.attached = true; }
            disconnect() { this.attached = false; }
        },
        setInterval(cb, ms) { interval = cb; assert.equal(ms, 250); return 1; },
        clearInterval(id) {assert.equal(id, 1); interval = null;}
    },
    body: {},
    querySelector(selector) {
        if (selector === '#zdrm-webphone-phonenumber-input.incoming')
            return value.inputIncoming ? {value:value.number} : null;
        if (selector === '.zdrm-webphone-call-ico.zdrm-ringing')
            return value.ringing ? {} : null;
        if (selector === '.zdrm-webphone-call-btn-up')
            return value.hangupActive ? {} : null;
        if (selector === '.zdrm-webphone-callername')
            return {textContent:value.callerName};
        throw new Error('Selector no autorizado: ' + selector);
    }
};
const events = [];
const watch = factory.create(doc, {
    onRinging(x) { events.push(['ring',x]); },
    onStopped() { events.push(['stop']); },
    onIdle() { events.push(['idle']); }
});
assert.equal(observer.attached,true);
assert.equal(watch.isRinging(),false);
assert.equal(factory.create({}, {}),null);

value.ringing = true; // Sólo el icono: no demuestra entrante.
observer.cb();
assert.equal(events.length,0);
value.inputIncoming = true;
value.number = '100';
value.callerName = 'Recepcion-Tania';
observer.cb();
assert.equal(events.length,1);
assert.deepEqual(events[0],['ring',{caller:'100',callerName:'Recepcion-Tania'}]);
assert.equal(watch.isRinging(),true);
interval();
assert.equal(events.length,1,'sin eventos duplicados en cada ciclo');
value.ringing = false;
observer.cb();
assert.equal(events.length,1,'no cerrar por una sola transición de CSS');
interval();
assert.equal(events.length,2);
assert.deepEqual(events[1],['stop']);
assert.equal(watch.isRinging(),false);
value.inputIncoming = false;
value.hangupActive = true;
observer.cb();
for (let i=0;i<12;i++) interval();
assert.equal(events.length,2,'contestar y quitar ringing NO finaliza conversación');
value.hangupActive = false;
for (let i=0;i<8;i++) interval();
assert.equal(events.length,3);
assert.deepEqual(events[2],['idle'],'el teléfono volvió al reposo tras desconectar');
for (let i=0;i<4;i++) interval();
assert.equal(events.length,3,'no repetir eventos idle');
value.inputIncoming = true;
value.number = '101';
value.ringing = true;
interval();
assert.equal(events.length,4,'una llamada posterior genera un evento nuevo');
assert.equal(events[3][1].caller,'101');
watch.stop();
assert.equal(observer.attached,false);
assert.equal(interval,null);
// Al recibir una transferencia, el primer clic Contestar retira 'incoming'
// antes de que el SDK enseñe Colgar: jamás declarar no contestada.
value.ringing = false;
value.inputIncoming = false;
value.hangupActive = false;
const transferred = [];
const second = factory.create(doc,{
    onRinging(){transferred.push('ring')},
    onStopped(){transferred.push('missed')},
    onConnected(){transferred.push('connected')},
    onIdle(){transferred.push('idle')}
});
value.inputIncoming = true;
value.ringing = true;
interval();
assert.deepEqual(transferred,['ring']);
assert.equal(second.markAnswerRequested(),true);
value.inputIncoming = false;
value.ringing = false;
value.hangupActive = false;
for (let i=0;i<9;i++) interval();
assert.deepEqual(transferred,['ring'],'negociación SIP no es llamada perdida');
value.hangupActive = true;
for (let i=0;i<4;i++) interval();
assert.deepEqual(transferred,['ring','connected'],
    'un solo evento de conversación observada tras aparecer Colgar');
for (let i=0;i<5;i++) interval();
assert.equal(transferred.filter(v=>v==='connected').length,1);
second.stop();
console.log('Observador SDK Analista: OK (timbrado, aceptación asíncrona, sin falso perdido)');
