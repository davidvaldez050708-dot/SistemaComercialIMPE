'use strict';
const assert = require('node:assert/strict');
const factory = require(process.env.IMPE_OBSERVER_UNDER_TEST ||
    '../public/javascript/telefonia_sdk_entrantes_observador.js');
let tick;
const state = {incoming:true, ringing:true, up:true, established:false, ended:false};
const session = {
    direction:'incoming',
    isEstablished: () => state.established,
    isEnded: () => state.ended
};
const doc = {
    body:{},
    defaultView:{
        zdrmWebPhone:{webCallSession:session},
        MutationObserver:class { observe() {} disconnect() {} },
        setInterval(fn) {tick=fn; return 1;},
        clearInterval() {}
    },
    querySelector(selector) {
        if (selector === '#zdrm-webphone-phonenumber-input.incoming')
            return state.incoming ? {value:'7777885173'} : null;
        if (selector === '.zdrm-webphone-call-ico.zdrm-ringing')
            return state.ringing ? {} : null;
        if (selector === '.zdrm-webphone-callername') return {textContent:'Marketing'};
        if (selector === '.zdrm-webphone-call-btn-up' ||
            selector === '.zdrm-webphone-call-ico.zdrm-webphone-call-btn-up')
            return state.up ? {} : null;
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
assert.deepEqual(events,['ring']);
observer.markAnswerRequested();
for (let i=0;i<10;i++) tick();
assert.deepEqual(events,['ring'],'contestar no confirma una sesión todavía en negociación');
// El SDK conserva incoming y puede conservar ringing tras answer() directo.
state.established=true;
state.up=false;
for (let i=0;i<3;i++) tick();
assert.deepEqual(events,['ring','connected'],
    'debe conectar durante la conversación aunque incoming permanezca');
assert.equal(observer.isConnected(),true);
assert.equal(observer.isRinging(),false);
state.established=false;
state.ended=true;
// El DOM puede conservar las marcas durante la limpieza de una llamada.
state.up=true; // Zadarma vuelve a este icono AL COLGAR.
// El proveedor puede retirar la referencia; la sesión ya observada sigue
// siendo la autoridad para su propio cierre.
doc.defaultView.zdrmWebPhone.webCallSession=null;
for (let i=0;i<12;i++) tick();
assert.deepEqual(events,['ring','connected','idle'],
    'colgar finaliza una vez y nunca inicia otra conversación');
assert.equal(observer.isConnected(),false);
// Un DOM que no cambió no reactiva la sesión acabada.
for (let i=0;i<12;i++) tick();
assert.deepEqual(events,['ring','connected','idle']);
state.incoming=false;
state.ringing=false;
tick();
// Nueva transferencia con una sesión SIP distinta.
state.established=false;
state.ended=false;
state.incoming=true;
state.ringing=true;
doc.defaultView.zdrmWebPhone.webCallSession={...session};
tick();
assert.equal(events.at(-1),'ring');
state.ended=true; // rechazada antes de confirmar
tick();
assert.equal(events.at(-1),'idle');
assert.equal(events.filter(x=>x==='connected').length,1);
observer.stop();
console.log('OK: transferencia SIP, incoming persistente, cierre y llamada posterior');
