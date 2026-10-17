'use strict';
const assert = require('node:assert/strict');
const factory = require(process.env.IMPE_OBSERVER_UNDER_TEST ||
    '../public/javascript/telefonia_sdk_entrantes_observador.js');

function harness(initialSession = null) {
    let tick;
    const dom = {incoming:false, ringing:false, up:true, number:'7777885173'};
    const sdk = {webCallSession:initialSession};
    const doc = {
        body:{},
        defaultView:{
            zdrmWebPhone:sdk,
            MutationObserver:class { observe() {} disconnect() {} },
            setInterval(fn) {tick=fn; return 1;},
            clearInterval() {}
        },
        querySelector(selector) {
            if (selector === '#zdrm-webphone-phonenumber-input.incoming')
                return dom.incoming ? {value:dom.number} : null;
            if (selector === '.zdrm-webphone-call-ico.zdrm-ringing')
                return dom.ringing ? {} : null;
            if (selector === '.zdrm-webphone-callername') return {textContent:'Marketing'};
            if (selector === '.zdrm-webphone-call-btn-up' ||
                selector === '.zdrm-webphone-call-ico.zdrm-webphone-call-btn-up')
                return dom.up ? {} : null;
            return null;
        }
    };
    const events = [];
    const observer = factory.create(doc, {
        onRinging:() => events.push('ring'),
        onStopped:() => events.push('stopped'),
        onConnected:() => events.push('connected'),
        onIdle:() => events.push('idle')
    });
    return {dom, sdk, events, observer, tick:() => tick()};
}
function session() {
    return {
        direction:'incoming', established:false, ended:false,
        isEstablished() {return this.established;},
        isEnded() {return this.ended;}
    };
}
function ring(h) {
    h.dom.incoming=true;
    h.dom.ringing=true;
    h.tick();
}

// El push hace sonar el widget ANTES de que Contestar registre SIP.
// La referencia SIP todavía puede apuntar a una llamada anterior terminada.
const previous = session();
previous.ended=true;
const h = harness(previous);
ring(h);
assert.deepEqual(h.events,['ring'],
    'el modal debe abrir aunque la referencia SIP anterior esté terminada');
assert.equal(h.observer.isRinging(),true);
assert.equal(h.observer.markAnswerRequested(),true);
for (let i=0;i<10;i++) h.tick();
assert.deepEqual(h.events,['ring'],'el push no confirma conversación SIP');
const first = session();
h.sdk.webCallSession=first;
h.tick();
first.established=true;
h.dom.up=false;
for (let i=0;i<3;i++) h.tick();
assert.deepEqual(h.events,['ring','connected'],
    'conecta aunque incoming y ringing permanezcan en el widget');
assert.equal(h.observer.isConnected(),true);
assert.equal(h.observer.isRinging(),false);

// Cierre SIP con DOM atrasado: ninguna llamada debe reactivarse.
first.established=false;
first.ended=true;
h.sdk.webCallSession=null;
for (let i=0;i<12;i++) h.tick();
assert.deepEqual(h.events,['ring','connected','idle']);
assert.equal(h.observer.isConnected(),false);
h.dom.incoming=false;
h.dom.ringing=false;
h.dom.up=true;
h.tick();

// Segunda transferencia del MISMO número, otra vez sin sesión SIP al timbrar.
ring(h);
assert.deepEqual(h.events,['ring','connected','idle','ring'],
    'la siguiente transferencia muestra Contestar antes de crear SIP');
assert.equal(h.observer.markAnswerRequested(),true);
const second = session();
h.sdk.webCallSession=second;
second.established=true;
h.tick();
assert.equal(h.events.at(-1),'connected');
h.dom.ringing=false;
h.tick();
h.dom.ringing=true;
h.tick();
assert.equal(h.events.filter(x=>x==='ring').length,2,
    'una clase transitoria no vuelve a timbrar una conversación confirmada');
second.established=false;
second.ended=true;
h.dom.incoming=false;
h.dom.ringing=false;
h.sdk.webCallSession=null;
h.tick();
assert.equal(h.events.at(-1),'idle');

// Tercera llamada: rechazada sin respuesta; no iniciar cronómetro.
ring(h);
const third = session();
h.sdk.webCallSession=third;
h.tick();
third.ended=true;
h.tick();
assert.equal(h.events.at(-1),'idle');
assert.equal(h.events.filter(x=>x==='connected').length,2);
assert.equal(h.events.filter(x=>x==='idle').length,3);
h.observer.stop();

const fresh=harness();
ring(fresh);
assert.deepEqual(fresh.events,['ring'],'primer push con referencia SIP vacía');
for (let i=0;i<4;i++) fresh.tick();
assert.equal(fresh.events.length,1);
fresh.observer.stop();
const anonymous=harness();
anonymous.dom.number='anonymous';
ring(anonymous);
assert.deepEqual(anonymous.events,['ring'],'conservar timbrado sin Caller ID numérico');
anonymous.observer.stop();
console.log('OK: push antes de SIP, tres transferencias, respuesta y cierre sin reactivación');
