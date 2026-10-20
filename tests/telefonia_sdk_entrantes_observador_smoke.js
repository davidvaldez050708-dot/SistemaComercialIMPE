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
        if (selector === '.zdrm-webphone-call-ico.zdrm-webphone-call-btn-up')
            return !value.hangupActive ? {} : null;
        if (selector === '.zdrm-webphone-callername')
            return {textContent:value.callerName};
        throw new Error('Selector no autorizado: ' + selector);
    }
};
const events = [];
const watch = factory.create(doc, {
    onRinging(x) { events.push(['ring',x]); },
    onStopped() { events.push(['stop']); },
    onConnected() { events.push(['connected']); },
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
// Una transferencia no está confirmada por pulsar Contestar ni por CSS.
value.ringing = false;
value.inputIncoming = false;
value.hangupActive = false;
const transferred = [];
let established = false;
let ended = false;
doc.defaultView.zdrmWebPhone = {webCallSession:{
    direction:'incoming',
    isEstablished:()=>established,
    isEnded:()=>ended
}};
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
established = true;
for (let i=0;i<4;i++) interval();
assert.deepEqual(transferred,['ring','connected'],
    'un solo evento de conversación al confirmarse SIP');
for (let i=0;i<5;i++) interval();
assert.equal(transferred.filter(v=>v==='connected').length,1);
ended = true;
established = false;
for (let i=0;i<5;i++) interval();
assert.equal(transferred.at(-1),'idle');
assert.equal(second.isConnected(),false);
second.stop();
// Rechazo: ni un DOM residual ni su parpadeo producen otra invitación.
value.inputIncoming=true;value.ringing=true;ended=false;
const rejectedEvents=[];
const rejected=factory.create(doc,{onRinging:()=>rejectedEvents.push('ring'),onStopped(){},onIdle:()=>rejectedEvents.push('idle')});
rejected.markDeclined();for(let i=0;i<20;i++)interval();
value.ringing=false;interval();value.ringing=true;interval();
assert.deepEqual(rejectedEvents,['ring']);
ended=true;interval();assert.deepEqual(rejectedEvents,['ring','idle']);
doc.defaultView.zdrmWebPhone.webCallSession={direction:'incoming',isEstablished:()=>false,isEnded:()=>false};
interval();assert.deepEqual(rejectedEvents,['ring','idle','ring'],'Nueva RTCSession del mismo número sí se ofrece');
rejected.stop();
doc.defaultView.zdrmWebPhone.webCallSession=null;
let lateInvites=0,newRings=0;
const late=factory.create(doc,{onRinging:()=>newRings++,onStopped(){},onDeclinedSession:()=>lateInvites++});
late.markDeclined();doc.defaultView.zdrmWebPhone.webCallSession={direction:'incoming',isEstablished:()=>false,isEnded:()=>false};
interval();interval();assert.equal(lateInvites,0,'Sin identidad SIP previa no auto rechazar un INVITE nuevo');assert.equal(newRings,2);
late.stop();
for(const extension of ['101','150']){
 value.inputIncoming=false;value.ringing=false;let rings=0;
 const sip={id:'invite-'+extension,direction:'incoming',remote_identity:{uri:{user:'100'},display_name:'Recepción'},isEstablished:()=>false,isEnded:()=>false};
 doc.defaultView.zdrmWebPhone.webCallSession=sip;
 const noDom=factory.create(doc,{onRinging:call=>{rings++;assert.equal(call.caller,'100');},onStopped(){}});
 assert.equal(rings,1,'INVITE válido sin clases nativas muestra llamada '+extension);
 for(let i=0;i<10;i++)interval();assert.equal(rings,1);
 doc.defaultView.zdrmWebPhone.webCallSession={...sip,id:sip.id+'-replacement'};
 interval();assert.equal(rings,2,'INVITE nuevo se publica sin esperar idle/END anterior');
 doc.defaultView.zdrmWebPhone.webCallSession=sip;
 interval();assert.equal(rings,3);
 noDom.markDeclined();doc.defaultView.zdrmWebPhone.webCallSession={...sip};interval();assert.equal(rings,3,'Mismo ID SIP no se reofrece por recrear el objeto');
 doc.defaultView.zdrmWebPhone.webCallSession={...sip,id:sip.id+'-new'};interval();assert.equal(rings,4,'Nuevo INVITE del mismo número se admite');noDom.stop();
}
console.log('Observador SDK Analista: OK (timbrado, aceptación asíncrona, sin falso perdido)');

value.inputIncoming=false;value.ringing=false;doc.defaultView.zdrmWebPhone.webCallSession=null;
let attempts=0;const retry=factory.create(doc,{onRinging(){return ++attempts>1;},onStopped(){}});
value.inputIncoming=true;value.ringing=true;interval();interval();interval();
assert.equal(attempts,2,'Invitación no se consume si el host la rechaza provisionalmente');retry.stop();
