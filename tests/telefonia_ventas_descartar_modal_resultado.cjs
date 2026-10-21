'use strict';
/* node tests/telefonia_ventas_descartar_modal_resultado.cjs
 * Verifica el cierre del modal final de Ventas con PBX o SDK exactos.
 */
const fs=require('node:fs');
const assert=require('node:assert/strict');
const shell=fs.readFileSync('public/javascript/telefonia_shell.js','utf8');
const start=shell.indexOf('        dismissFinishedCall: function (ref, token) {');
const end=shell.indexOf('        isResultFormOpen: function',start);
assert.ok(start>0&&end>start,'API de descarte de llamada guardada');
const code=shell.slice(start,end).replace(/^\s*dismissFinishedCall: function/, 'function').replace(/,\s*$/,'');
function make(call,opts={}){
 const box={currentState:opts.active?{active:true}:opts.currentState||call,
  recentFinishedIncoming:call?{state:{...call}}:null,recentFinishedOutgoing:null,
  resultFormOwner:null,commands:[],renders:0};
 const fn=new Function('box',`
   let currentState=box.currentState;
   let recentFinishedIncoming=box.recentFinishedIncoming;
   let recentFinishedOutgoing=box.recentFinishedOutgoing;
   let resultFormOwner=box.resultFormOwner;
   const sendPhoneCommand=(type,payload)=>box.commands.push({type,payload});
   const render=()=>{box.recentFinishedIncoming=recentFinishedIncoming;
     box.recentFinishedOutgoing=recentFinishedOutgoing;
     box.resultFormOwner=resultFormOwner;box.renders++};
   return (${code});
 `)(box);
 return {box,fn};
}
const pbx='PBXtransferida100a150';
const token='sdk-this-session-external';
let sdk=make({callToken:token,pbxCallId:'',phase:'incoming-finished',active:false,context:{type:'SDK_INCOMING'}});
assert.equal(sdk.fn(pbx,token),true,'Cerrar con resultado PBX y token SDK reservado');
assert.equal(sdk.box.recentFinishedIncoming,null,'No debe quedar modal vacío');
assert.deepEqual(sdk.box.commands,[{type:'CLEAR_FINISHED',payload:{callToken:token}}]);
let other=make({callToken:'sdk-other-session',pbxCallId:'',phase:'incoming-finished',active:false,context:{type:'SDK_INCOMING'}});
assert.equal(other.fn(pbx,token),false,'Resultado anterior no retira llamada posterior');
assert.equal(other.box.renders,0);
assert.ok(other.box.recentFinishedIncoming);
let own=make({callToken:'sdk-any',pbxCallId:pbx,phase:'incoming-finished',active:false});
assert.equal(own.fn(pbx,''),true,'PBX exacto también permite descartar el modal');
let active=make({callToken:token,pbxCallId:pbx,phase:'in-progress',active:true},{active:true});
assert.equal(active.fn(pbx,token),false,'No cerrar modal de llamada viva');
assert.equal(active.box.commands.length,0);
const ventas=fs.readFileSync('public/javascript/telefonia_ventas_shell.js','utf8');
assert.match(ventas,/dismissFinishedCall\?\.\(ref,token\|\|own\?\.callToken\|\|''\)/);
assert.match(ventas,/if\(!task\)return;\s*remove\(e\.data\.ref,task\.callToken\|\|''\)/);
console.log('OK: cierre de modal tras resultado, SDK exacto, PBX exacto y aislamiento entre llamadas');
