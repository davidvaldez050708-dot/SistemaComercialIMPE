'use strict';
/* Reproduce hangup de 100->150: JsSIP emite ended ANTES de retirar la
 * RTCSession o actualizar isEnded. Nunca cerrar SIP de otra llamada. */
const assert=require('node:assert/strict'),fs=require('node:fs');
const code=fs.readFileSync('public/javascript/telefonia_host.js','utf8');
const begin=code.indexOf('                onIdle: function (evidence={}) {');
const end=code.indexOf('\n            }\n        ) || null;',begin);
assert(begin>=0&&end>begin,'onIdle original');
const src='function(evidence={}) {'+code.slice(begin+'                onIdle: function (evidence={}) {'.length,end);
function fixture({sales=true,sessionId='rtc-own-150',activeId=sessionId,active=true,ended=false,ref='out_104f634c799a7fd246dfe5bad6e572b0fe32415e'}={}){
 const rt={state:{active:true,direction:'incoming',phase:'in-progress',pbxCallId:ref,duration:9,
  callToken:'sdk-transfer-current',context:{type:'SDK_INCOMING',sdkInviteId:sessionId,
   sdkConnected:true,transferOperation:77,transferReference:ref}},hidden:0,polls:0,retired:[]};
 const sdk={id:activeId,direction:'incoming',isEnded:()=>ended};
 const env=new Function('rt','sdk','body',`
 let state=rt.state,answeredAtMs=900;
 const window={zdrmWebPhone:{webCallSession:sdk},setTimeout:()=>{}};
 const config={salesSdkIncoming:${sales}};
 const isSdkOnlyIncoming=()=>false;
 const currentDuration=()=>9;
 const publish=p=>{state={...state,...p};rt.state=state};
 const retirePbxCall=x=>rt.retired.push(x);
 const ocultarHostEntrante=()=>{rt.hidden++};
 const marcarPestanaEntrante=()=>{};
 const pollIncoming=()=>{rt.polls++};
 const clearSdkOnlyIncoming=()=>{};
 const transferFlow=null;
 return eval('('+body+')');
 `)(rt,sdk,src);
 return {rt,trigger:e=>env(e)};
}
const own=fixture();own.trigger({sessionEnded:true,sessionId:'rtc-own-150'});
assert.equal(own.rt.state.active,false,'END de sesión propia debe cerrar inmediatamente, aunque isEnded sea false');
assert.equal(own.rt.state.phase,'incoming-finished');
assert.equal(own.rt.state.status,'ended-unconfirmed','No inventar cierre Zadarma');
assert.equal(Boolean(own.rt.state.context.pbxEnded),false,'El cierre local nunca acredita un END PBX');
assert.equal(own.rt.hidden,1);
assert.deepEqual(own.rt.retired,['out_104f634c799a7fd246dfe5bad6e572b0fe32415e']);
const different=fixture({activeId:'rtc-new-150'});different.trigger({sessionEnded:true,sessionId:'rtc-own-150'});
assert.equal(different.rt.state.active,true,'Una sesión entrante nueva no puede ser cerrada por END anterior');
const wrong=fixture();wrong.trigger({sessionEnded:true,sessionId:'rtc-unrelated'});
assert.equal(wrong.rt.state.active,true,'Otro SIP no puede cerrar la llamada actual');
const analyst=fixture({sales:false});analyst.trigger({sessionEnded:true,sessionId:'rtc-own-150'});
assert.equal(analyst.rt.state.active,true,'No cambiar tratamiento del Analista');
const detached=fixture({ended:true});detached.trigger({sessionEnded:true,sessionId:'rtc-own-150'});
assert.equal(detached.rt.state.active,false,'SIP terminado por el proveedor también cierra');
const cbStart=code.indexOf('        consultEnded:op=>{');
const cbEnd=code.indexOf('\n    })||null;',cbStart);
assert(cbStart>=0&&cbEnd>cbStart);
const cb='op=>{'+code.slice(cbStart+'        consultEnded:op=>{'.length,cbEnd).replace(/\n        }\s*$/,'\n}');
function consult({sales=true,stage='ENTREGADA',sdkEnded=true,contextValid=true,sessionDifferent=false,answered=true,tokenValid=true}={}){
 const ref='pbx-exact-transfer-150',rt={state:{active:true,phase:'in-progress',direction:'incoming',
 callToken:'sdk-transfer-150',pbxCallId:'',duration:9,context:{type:'SDK_INCOMING',sdkConnected:answered,
 transferOperation:contextValid?77:99,transferReference:ref}},retired:[],hidden:0,polls:0};
 const sdk={id:'rtc-transfer-150',isEnded:()=>sdkEnded};
 const other={id:'rtc-new-150',isEnded:()=>false};
 const fn=new Function('rt','sdk','other','code','sales','sessionDifferent',`
 let state=rt.state,answeredAtMs=500;const config={salesSdkIncoming:sales};
 const window={zdrmWebPhone:{webCallSession:sessionDifferent?other:sdk}};
 const currentDuration=()=>9;
 const publish=p=>{state={...state,...p};rt.state=state};
 const retirePbxCall=x=>rt.retired.push(x);
 const ocultarHostEntrante=()=>{rt.hidden++};
 const marcarPestanaEntrante=()=>{};
 const pollIncoming=()=>{rt.polls++};
 return eval('('+code+')');`)(rt,sdk,other,cb,sales,sessionDifferent);
 fn({modo:'DIRECTA',estado:stage,id:77,referencia:ref},
    tokenValid?'sdk-transfer-150':'sdk-another-call',sdk);
 return rt;
}
assert.equal(consult().state.active,false,'ENTREGADA y sesión terminada liberan modal inmediatamente');
const sent=consult({stage:'ENVIADA'});
assert.equal(sent.state.active,false,'Saliente de Recepción transferida a Asesor termina localmente aun con PBX ENVIADA');
assert.equal(sent.state.phase,'incoming-finished');
assert.equal(sent.state.status,'ended-unconfirmed','Nunca inventar END PBX');
assert.equal(sent.state.context.sdkEnded,true,'END exacto sí queda marcado');
assert.equal(sent.state.context.pbxEnded,undefined,'El audio finalizado no certifica PBX');
assert.equal(consult({sdkEnded:false}).state.phase,'recovering','No cerrar sesión viva');
assert.equal(consult({sessionDifferent:true}).state.active,true,'Otra llamada SIP viva impide cerrar la anterior');
assert.equal(consult({stage:'TIMBRANDO'}).state.phase,'recovering','No cerrar consulta no entregada');
assert.equal(consult({contextValid:false}).state.active,true,'No cerrar una operación distinta');
assert.equal(consult({tokenValid:false}).state.active,true,'No cerrar token de llamada distinto');
assert.equal(consult({answered:false}).state.active,true,'No presentar resultado de un intento sin respuesta');
assert.equal(consult({sales:false}).state.active,true,'Analista conserva su flujo');
// Caso real con operación ENTREGADA y RTCSession distinta al primer PUSH:
// la transferencia externa registra su propio END, aunque onIdle no se dispare.
const receiver=code.match(/        receiverEnded:\(op,token,endedSession\)=>\{([\s\S]*?)\n        \},\n        consultEnded/);
assert(receiver,'Callback receptor directo');
function receiverCase({sales=true,stage='ENTREGADA',currentDifferent=false,ended=true,operation=77}={}){
 const ref='pbx-exact-transfer-150';
 const rt={state:{active:true,direction:'incoming',phase:'recovering',
  callToken:'sdk-current-external',duration:9,
  context:{type:'SDK_INCOMING',sdkConnected:true,transferReference:ref,transferOperation:77}},
  retired:[],hidden:0};
 const own={id:'rtc-current-external',isEnded:()=>ended};
 const next={id:'rtc-next-call',isEnded:()=>false};
 const fn=new Function('rt','own','live','sales','body',`
 let state=rt.state,answeredAtMs=1234;
 const config={salesSdkIncoming:sales};
 const window={zdrmWebPhone:{webCallSession:live}};
 const currentDuration=()=>9;
 const publish=p=>{state={...state,...p};rt.state=state};
 const retirePbxCall=x=>rt.retired.push(x);
 const ocultarHostEntrante=()=>{rt.hidden++};
 const marcarPestanaEntrante=()=>{};
 return eval('(function(op,token,endedSession){'+body+'})');
 `)(rt,own,currentDifferent?next:own,sales,receiver[1]);
 fn({id:operation,referencia:ref,estado:stage,modo:'DIRECTA'},'sdk-current-external',own);
 return rt;
}
assert.equal(receiverCase().state.active,false,'END del tramo ENTREGADA cierra incluso sin onIdle');
assert.equal(receiverCase().state.context.pbxEnded,undefined,'No inventa END PBX');
assert.equal(receiverCase({currentDifferent:true}).state.active,true,'Nueva sesión viva no se cierra');
assert.equal(receiverCase({ended:false}).state.active,true,'Una sesión SIP viva no se declara terminada');
assert.equal(receiverCase({sales:false}).state.active,true,'Analista se mantiene intacto');
assert.equal(receiverCase({operation:99}).state.active,true,'Operación diferente no reutiliza cierre');
assert.equal(receiverCase({stage:'TIMBRANDO'}).state.active,true,'Una transferencia no entregada no se cierra como conversación');

console.log('OK: fin SIP exacto 100->150 inmediato; sin cierre PBX falso; no afectar Analista ni SIP diferente');
