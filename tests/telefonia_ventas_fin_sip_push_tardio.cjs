'use strict';
/* node tests/telefonia_ventas_fin_sip_push_tardio.cjs */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const ob=fs.readFileSync('public/javascript/telefonia_sdk_entrantes_observador.js','utf8');
const host=fs.readFileSync('public/javascript/telefonia_host.js','utf8');
function extract(start,end){
 const a=host.indexOf(start),b=host.indexOf(end,a+start.length);
 assert.ok(a>=0&&b>a,'Hook del host no encontrado: '+start);
 const snippet=host.slice(a,b);
 return snippet.replace(/^\s*on(?:Idle|SessionBound): function/,'function').replace(/,\s*$/,'');
}
const bindSrc=extract('                onSessionBound: function','                onConnected: function');
const idleSrc=extract('                onIdle: function','\n            }\n        ) || null;');
const callbackNames=['onSessionBound','onIdle'];
function hostFixture(){
 const rt={state:{active:true,direction:'incoming',phase:'in-progress',pbxCallId:'PBXx123456',
  callToken:'sdk-prospecto-123456',context:{type:'SDK_INCOMING',transferReference:'PBXx123456',
  sdkConnected:true,sdkInviteId:''},duration:9},retired:[],closed:0};
 const wrapper=`
 let state=rt.state,answeredAtMs=500;
 const config={salesSdkIncoming:true};
 const window={zdrmWebPhone:{webCallSession:null},setTimeout(){}};
 const isSdkOnlyIncoming=()=>state.context?.type==='SDK_INCOMING'&&!state.pbxCallId;
 const currentDuration=()=>state.duration;
 const publish=p=>{state={...state,...p};rt.state=state};
 const retirePbxCall=ref=>rt.retired.push(ref);
 const ocultarHostEntrante=()=>{rt.closed++};
 const marcarPestanaEntrante=()=>{};
 const pollIncoming=()=>{};
 const clearSdkOnlyIncoming=()=>{};
 const transferFlow=null;
 return {onSessionBound:(${bindSrc}),onIdle:(${idleSrc})};
 `;
 return {rt,hooks:new Function('rt',wrapper)(rt)};
}
const f=hostFixture();
let dom=true,session=null,log={ring:0,connected:0};
const widget={callState:'incoming',webCallSession:null};
const win={zdrmWebPhone:widget,MutationObserver:class{observe(){}disconnect(){}},
 setInterval:()=>1,clearInterval:()=>{}};
const doc={body:{},defaultView:win,querySelector(selector){
 if(!dom)return null;
 if(selector==='#zdrm-webphone-phonenumber-input.incoming')return {value:'+527777885173'};
 if(selector==='.zdrm-webphone-call-ico.zdrm-ringing')return {};
 if(selector==='.zdrm-webphone-callername')return {textContent:'Persona'};
 return null;
}};
const root={};vm.runInNewContext(ob,{window:root,console});
const obs=root.IMPE_ZADARMA_SDK_RING_OBSERVER.create(doc,{
 onRinging:()=>{log.ring++;return true;},onStopped:()=>{},
 onConnected:()=>{log.connected++},
 onSessionBound:f.hooks.onSessionBound,onIdle:f.hooks.onIdle
});
assert.equal(log.ring,1,'Primero entra PUSH sin RTCSession');
const listeners={};
session={id:'rtc-real-150',direction:'incoming',remote_identity:{uri:{user:'527777885173'}},
 isEnded:()=>false,isEstablished:()=>true,
 on:(name,fn)=>(listeners[name]??=[]).push(fn)};
dom=false;widget.callState='answered';widget.webCallSession=session;obs.checkNow();
assert.equal(f.rt.state.context.sdkInviteId,'rtc-real-150','Se enlaza RTCSession creada después del PUSH');
assert.equal(log.connected,1);
widget.webCallSession=null;
listeners.ended.forEach(fn=>fn());
assert.equal(f.rt.state.active,false,'END real cierra de inmediato interfaz');
assert.equal(f.rt.state.phase,'incoming-finished');
assert.equal(f.rt.state.status,'ended-unconfirmed','No inventar finalización PBX');
assert.deepEqual(f.rt.retired,['PBXx123456']);
obs.stop();
// END tardío de un SIP anterior NO puede cerrar el diálogo nuevo.
const other=hostFixture();
other.rt.state.context.sdkInviteId='rtc-current';
other.hooks.onIdle({sessionEnded:true,sessionId:'rtc-previous'});
assert.equal(other.rt.state.active,true,'Un FIN de sesión anterior no cierra otra');
console.log('OK: PUSH -> RTCSession tardía -> END, modal final; PBX sin inventar; eventos ajenos aislados');
