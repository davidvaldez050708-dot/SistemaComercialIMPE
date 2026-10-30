'use strict';
/* Métodos reales de Zadarma, sin acceso a PBX ni navegador externo. */
const assert = require('node:assert/strict');
const api = require('../public/javascript/telefonia_entrantes_bridge.js');
const calls = [];
let ringing = true;
let talking = false;
let failed = '';
const sdk = {
    answer() {calls.push(['answer',this]); return undefined;},
    cancel() {calls.push(['cancel',this]); return undefined;},
    finishCall() {calls.push(['finishCall',this]); return undefined;},
    micSwitch(value) {calls.push(['micSwitch',this,value]);return undefined;},
    regToAnswer() {throw new Error('No registrar SIP durante una llamada');}
};
const document = {defaultView:{zdrmWebPhone:sdk}};
const bridge = api.create(document,()=>ringing,()=>talking);
assert.equal(bridge.available(),true);
assert.equal(bridge.answer(),true);
assert.equal(calls[0][0],'answer');
assert.equal(calls[0][1],sdk,'conserva this del objeto original');
assert.equal(bridge.answer(),false,'evita doble clic inmediato');
assert.match(bridge.error(),/enviada/);
assert.equal(bridge.hangup(),false,'no cuelga mientras suena');
assert.equal(bridge.mute(true),false,'no silencia si no hay conversación');
ringing = false;
talking = true;
assert.equal(bridge.answer(),false,'no contesta si cambió el estado');
assert.equal(bridge.canHangup(),true);
assert.equal(bridge.mute(true),true);
assert.equal(calls.at(-1)[0],'micSwitch');
assert.equal(calls.at(-1)[2],'on','on silencia según el SDK real');
assert.equal(bridge.mute(false),true);
assert.equal(calls.at(-1)[2],'off','off activa el micrófono');
assert.equal(bridge.hangup(),true);
assert.equal(calls.at(-1)[0],'finishCall');
talking=false;
assert.equal(bridge.hangup(),false,'no cuelga la sesión después del fin');
ringing=true;
assert.equal(bridge.decline(),true);
assert.equal(calls.at(-1)[0],'finishCall');
assert.equal(calls.some(x=>x[0]==='regToAnswer'),false,
    'nunca ejecuta el registro SIP al atender');

const noEngine=api.create({defaultView:{}},()=>true,()=>false);
assert.equal(noEngine.available(),false);
assert.equal(noEngine.answer(),false);
assert.match(noEngine.error(),/no tiene disponible/);
assert.equal(calls.length,5,'fallos no invocan métodos nuevos');

const failedSdk={answer(){return 'Error interno de Zadarma';}};
const bad=api.create({defaultView:{zdrmWebPhone:failedSdk}},()=>true,()=>false);
assert.equal(bad.answer(),false);
assert.equal(bad.error(),'Error interno de Zadarma');
failedSdk.answer=()=>{throw new Error('simulated');};
assert.equal(bad.answer(),false);
assert.match(bad.error(),/no pudo ejecutar/);

console.log('WebRTC entrante: OK (SDK directo, estados, mute, colgar, rechazar y errores)');
