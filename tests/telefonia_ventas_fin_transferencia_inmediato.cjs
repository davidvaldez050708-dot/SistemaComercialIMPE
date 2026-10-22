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
function consult({sales=true,stage='ENTREGADA',sdkEnded=true,contextValid=true,stillAlive=true}={}){
 const ref='pbx-exact-transfer-150',rt={state:{active:true,phase:'in-progress',direction:'incoming',
 pbxCallId:'',duration:9,context:{type:'SDK_INCOMING',sdkConnected:true,
 transferOperation:contextValid?77:99,transferReference:ref}},retired:[],hidden:0,polls:0};
 const sdk={isEnded:()=>sdkEnded};
 const fn=new Function('rt','sdk','code','sales',`
 let state=rt.state,answeredAtMs=500;const config={salesSdkIncoming:sales};
 const window={zdrmWebPhone:{webCallSession:sdk}};
 const currentDuration=()=>9;
 const publish=p=>{state={...state,...p};rt.state=state};
 const retirePbxCall=x=>rt.retired.push(x);
 const ocultarHostEntrante=()=>{rt.hidden++};
 const marcarPestanaEntrante=()=>{};
 const pollIncoming=()=>{rt.polls++};
 return eval('('+code+')');`)(rt,sdk,cb,sales);
 fn({modo:'DIRECTA',estado:stage,id:77,referencia:ref});
 return rt;
}
assert.equal(consult().state.active,false,'ENTREGADA y sesión terminada liberan modal inmediatamente');
assert.equal(consult({sdkEnded:false}).state.phase,'recovering','No cerrar sesión viva');
assert.equal(consult({stage:'TIMBRANDO'}).state.phase,'recovering','No cerrar consulta no entregada');
assert.equal(consult({contextValid:false}).state.active,true,'No cerrar una operación distinta');
assert.equal(consult({sales:false}).state.active,true,'Analista conserva su flujo');
console.log('OK: fin SIP exacto 100->150 inmediato; sin cierre PBX falso; no afectar Analista ni SIP diferente');
