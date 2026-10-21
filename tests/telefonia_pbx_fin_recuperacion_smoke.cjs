/* node tests/telefonia_pbx_fin_recuperacion_smoke.cjs
 * Sin red, SDK real ni MySQL. Verifica el comando que SOLO cierra la
 * misma conversación SIP en recuperación tras la confirmación PBX propia.
 */
'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),path=require('node:path');
const host=fs.readFileSync(path.resolve(__dirname,'../public/javascript/telefonia_host.js'),'utf8');
const marker='    const processCommand = function (message) {';
const a=host.indexOf(marker),b=host.indexOf('\n    if (channel) {',a);
assert.ok(a>=0&&b>a,'No se encontró el procesador de comandos host');
const body=host.slice(a,b);
const create=(overrides={})=>{
 const runtime={state:{active:true,direction:'incoming',phase:'recovering',
   callToken:'sdk-dialog-external',pbxCallId:'',duration:21,
   context:{type:'SDK_INCOMING',transferReference:'PBX123456',sdkConnected:true},
   ...overrides},retired:[]};
 const script=`
 let state=rt.state,lastCommandId='',answeredAtMs=1234;
 const publish=patch=>{state={...state,...patch};rt.state=state;return state;};
 const currentDuration=()=>state.duration;
 const retirePbxCall=ref=>rt.retired.push(ref);
 const ocultarHostEntrante=()=>{};
 const marcarPestanaEntrante=()=>{};
 ${body}
 return processCommand;`;
 return {rt:runtime,apply:new Function('rt',script)(runtime)};
};
const call=(c,ref='PBX123456',token='sdk-dialog-external')=>c.apply({
 type:'COMMAND',id:'sample',action:'PBX_CONFIRMED_INCOMING_END',
 payload:{ref,callToken:token,seconds:13,connected:true}});
let ok=create();call(ok);
assert.equal(ok.rt.state.active,false);
assert.equal(ok.rt.state.phase,'incoming-finished');
assert.equal(ok.rt.state.duration,13);
assert.equal(ok.rt.state.context.pbxEnded,true);
assert.deepEqual(ok.rt.retired,['PBX123456']);
for(const [kind,overrides,ref,token] of [
 ['Otra referencia',{},'PBX654321','sdk-dialog-external'],
 ['Otro diálogo',{},'PBX123456','sdk-previous'],
 ['Conversación aún activa',{phase:'in-progress'},'PBX123456','sdk-dialog-external'],
 ['Otro origen',{direction:'outgoing'},'PBX123456','sdk-dialog-external']]){
 let x=create(overrides);call(x,ref,token);
 assert.equal(x.rt.state.active,true,kind);
}
const sales=fs.readFileSync(path.resolve(__dirname,'../public/javascript/telefonia_ventas_shell.js'),'utf8');
assert.ok(sales.includes('if(facts.finalizada){'),'El resultado PBX final debe procesarse aun con recuperación activa');
assert.ok(sales.includes('phone.confirmFinishedCall?.(ref,facts,state.callToken)'));
const shell=fs.readFileSync(path.resolve(__dirname,'../public/javascript/telefonia_shell.js'),'utf8');
assert.ok(shell.includes("sendPhoneCommand('PBX_CONFIRMED_INCOMING_END'"),'El shell debe comunicar cierre PBX propio al host');
console.log('OK: PBX final libera sólo sesión exacta y Ventas notifica sin F5');
