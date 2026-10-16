'use strict';
/* Test de contrato: estado PBX + evidencia local SDK.
 * Ejecutar: node tests/telefonia_analista_transferencia_estado_smoke.js
 * No requiere Zadarma ni base de datos, no modifica archivos.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(
    path.join(__dirname, '../public/javascript/telefonia_host.js'), 'utf8');
const start = source.indexOf('    const applyIncomingState = function (call) {');
const end = source.indexOf('    const pollIncoming = async function () {', start);
assert(start !== -1 && end > start, 'No se localizó la conciliación entrante');
const fragment = source.slice(start, end);
const step = new Function('initial','pbxCall', [
    'let state=JSON.parse(JSON.stringify(initial));',
    'let answeredAtMs=Number(state.answeredAtMs||0);',
    'const extension="101";',
    'const publish=patch=>{state=Object.assign({},state,patch)};',
    'const finalizarEntrante=()=>{};',
    'const marcarPestanaEntrante=()=>{};',
    'const mostrarHostEntrante=()=>{};',
    fragment,
    'applyIncomingState(pbxCall);return state;'
].join('\n'));

const caller = '+525512341234';
const pbxId = 'in_TEST_PBX_123456';
const event = {pbx_call_id:pbxId,estado:'ringing',caller_id:caller,duration:0};
const base = (overrides={})=>Object.assign({
    active:true,direction:'incoming',phase:'incoming-ringing',
    destination:caller,pbxCallId:'',callToken:'sdk-123456789012',
    requestedAt:Math.floor(Date.now()/1000),context:{type:'SDK_INCOMING'}
},overrides);

assert.equal(step(base({active:false,direction:'',context:null}),event).phase,
    'incoming-ringing','PBX empieza timbrando');
const sdk = step(base({phase:'in-progress',answeredAtMs:Date.now()-12000,
    context:{type:'SDK_INCOMING',sdkConnected:true}}),event);
assert.equal(sdk.phase,'in-progress',
    'La PBX no debe devolver a timbrando el audio ya conectado');
assert.equal(sdk.context.sdkConnected,true);
const mismatch = step(base({phase:'in-progress',destination:'+525500009999',
    context:{type:'SDK_INCOMING',sdkConnected:true}}),event);
assert.equal(mismatch.phase,'incoming-ringing','El caller distinto no hereda llamada');
const pbxAnswered = step(base({pbxCallId:pbxId,phase:'in-progress',
    context:{type:'INCOMING',pbxAnswered:true},answeredAtMs:Date.now()-2000}),event);
assert.equal(pbxAnswered.phase,'in-progress','Un evento viejo no degrada PBX ANSWER');
const finishing = step(base({pbxCallId:pbxId,phase:'finishing',
    context:{type:'INCOMING',sdkConnected:true}}),event);
assert.equal(finishing.phase,'finishing','No revivir audio después de colgar');
const finished = step(base({pbxCallId:pbxId,phase:'incoming-finished',
    context:{type:'INCOMING',sdkConnected:true}}),event);
assert.equal(finished.phase,'incoming-finished','No revivir llamada finalizada');
const shell = fs.readFileSync(
    path.join(__dirname, '../public/javascript/telefonia_shell.js'),'utf8');
assert(shell.includes("state.phase === 'answering'"),'Falta estado de conexión');
assert(shell.includes("state.phase === 'finishing'"),'Falta estado de finalización');
const passive = fs.readFileSync(
    path.join(__dirname, '../public/javascript/telefonia_analista_historial_pasivo.js'),'utf8');
assert(passive.includes("state.context?.type === 'INCOMING'"),
    'Falta consulta de expediente en flujo PBX');
console.log('Flujo analista SDK/PBX: 8 pruebas correctas');
