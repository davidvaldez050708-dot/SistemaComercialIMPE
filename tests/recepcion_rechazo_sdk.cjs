'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const path=require('node:path'),root=path.resolve(process.env.IMPE_REJECTION_SOURCE_ROOT||'.');
const bridgeFactory=require(path.join(root,'public/javascript/telefonia_entrantes_bridge.js'));
const observerFactory=require(path.join(root,'public/javascript/telefonia_sdk_entrantes_observador.js'));
function harness(){
 const sdk={callState:'',webCallSession:null,operations:[],finishCall(){this.operations.push('finishCall');this.callState='canceling';},cancel(){throw Error('No usar cancel directamente');},answer(){this.operations.push('answer');}};
 const dom={ring:false};let tick;
 const doc={body:{},defaultView:{zdrmWebPhone:sdk,MutationObserver:class{observe(){}disconnect(){}},setInterval(fn){tick=fn;return 1;},clearInterval(){}},querySelector(s){
  if(s==='#zdrm-webphone-phonenumber-input.incoming')return dom.ring?{value:'5550000000'}:null;
  if(s==='.zdrm-webphone-call-ico.zdrm-ringing')return dom.ring?{}:null;
  if(s==='.zdrm-webphone-call-ico.zdrm-webphone-call-btn-up')return !dom.ring?{}:null;
  return null;
 }};
 const events=[];
 const observer=observerFactory.create(doc,{onRinging:c=>events.push(['ring',c]),onStopped:()=>events.push(['stop']),onIdle:c=>events.push(['idle',c]),onConnected:()=>events.push(['connected'])});
 let token=0;
 const bridge=bridgeFactory.create(doc,()=>observer.isRinging(),()=>observer.isConnected(),()=>token);
 return {sdk,dom,events,observer,bridge,tick:()=>tick(),ring(){token++;sdk.callState='incoming';dom.ring=true;tick();},reject(){const sent=bridge.decline(String(token));if(sent)observer.markDeclined(sdk.webCallSession);return sent;}};
}
const h=harness();h.ring();assert.equal(h.reject(),true);
assert.deepEqual(h.sdk.operations,['finishCall'],'Rechazo PUSH usa el control completo del proveedor');
assert.equal(h.sdk.callState,'canceling');
for(let i=0;i<20;i++)h.tick();
assert.equal(h.events.filter(e=>e[0]==='ring').length,1,'DOM residual no reabre');
assert.equal(h.events.filter(e=>e[0]==='idle').length,0,'Invocar rechazo no confirma cierre');
// Nueva oferta sin pasar por reposo y sin retirar clases CSS del widget.
h.ring();assert.equal(h.events.filter(e=>e[0]==='ring').length,2);
assert.equal(h.observer.isRinging(),true);assert.equal(h.reject(),true);
assert.equal(h.sdk.operations.length,2);
// El nuevo PUSH puede llegar antes de que se muestree canceling.
h.ring();assert.equal(h.events.filter(e=>e[0]==='ring').length,3);
h.reject();h.sdk.callState='';h.dom.ring=false;
for(let i=0;i<10;i++)h.tick();
// Volver a reposo tras un rechazo sin sesión debe notificarse también.
assert.equal(h.events.filter(e=>e[0]==='idle').length,1);
assert.equal(h.events.find(e=>e[0]==='idle')[1].pushEnded,true);
assert.equal(h.events.filter(e=>e[0]==='connected').length,0);
h.observer.stop();
const repeatedSdk={callState:'incoming',sent:0,finishCall(){this.sent++;this.callState='canceling';}};
const repeated=bridgeFactory.create({defaultView:{zdrmWebPhone:repeatedSdk}},()=>true,()=>false,()=>'same-pbx');
assert.equal(repeated.decline('same-pbx'),true);
assert.equal(repeated.decline('same-pbx'),true);assert.equal(repeatedSdk.sent,1);
repeatedSdk.callState='incoming';repeated.allowNewInvitation('same-pbx');
assert.equal(repeated.decline('same-pbx'),true);assert.equal(repeatedSdk.sent,2,'Nueva oferta del mismo PBX se rechaza realmente, sin heredar deduplicación');

function session(id,direction='incoming',established=false){
 const listeners={};return {id,direction,ended:false,established,sent:0,isEnded(){return this.ended;},isEstablished(){return this.established;},on(k,f){(listeners[k]??=[]).push(f);},terminate(o){assert.equal(o.status_code,486);this.sent++;},end(){this.ended=true;for(const fn of listeners.failed||[])fn();}};
}
const sip=harness();const first=session('sip-1');sip.sdk.webCallSession=first;sip.ring();
assert.equal(sip.reject(),true);assert.equal(sip.bridge.decline('1'),true);assert.equal(first.sent,1,'Idempotencia por RTCSession');
first.end();assert.equal(sip.events.filter(e=>e[0]==='idle').length,1);
const second=session('sip-2');sip.sdk.webCallSession=second;sip.ring();
const count=sip.events.length;first.end();assert.equal(sip.events.length,count,'END anterior no cierra nueva sesión');
assert.equal(sip.observer.isRinging(),true);assert.equal(sip.reject(),true);assert.equal(second.sent,1);
sip.observer.stop();
for(const wrong of [session('out','outgoing'),session('talking','incoming',true)]){
 const b=bridgeFactory.create({defaultView:{zdrmWebPhone:{webCallSession:wrong}}},()=>true,()=>false);
 assert.equal(b.decline('wrong'),false);assert.equal(wrong.sent,0,'No cancelar saliente ni conversación establecida');
}
const broken=bridgeFactory.create({defaultView:{zdrmWebPhone:{finishCall(){return false;}}}},()=>true,()=>false);
assert.equal(broken.decline('broken'),false);assert.match(broken.error(),/rechazó/);

// Ejecutar los controles reales del host con dependencias simuladas: PBX
// atrasado no reofrece el mismo rechazo y END sí conserva su autoridad.
const source=fs.readFileSync(path.join(root,'public/javascript/telefonia_host.js'),'utf8').replace(/\r\n/g,'\n');
const extract=(start,end)=>{const a=source.indexOf(start);assert(a>=0,start);const b=source.indexOf(end,a+start.length);assert(b>a,end);return source.slice(a+start.length,b);};
const state={active:true,direction:'incoming',phase:'incoming-ringing',pbxCallId:'pbx-1',callToken:'invite-1',context:{}};
let sent=0,finals=0;
const env={state,document:{querySelector:()=>({})},window:{zdrmWebPhone:{webCallSession:first,callState:'canceling'}},declinedInvitations:new Map(),permiteEntrantes:true,incomingBridge:{decline:()=>{sent++;return true;}},analystSdkObserver:{markDeclined(){}},actualizarEstadoTrasAccion(){},publish:p=>Object.assign(state,p),transferFlow:null,config:{},retiredIds:new Map(),finalizarEntrante(){finals++;state.active=false;}};
env.reconcileReceptionDecline=()=>false; // El cierre nuevo tiene pruebas integradas propias.
vm.runInNewContext('this.declined=id=>{'+extract('    const declinedInvitation = id => {','\n    };')+'};this.decline=()=>{'+extract('        decline: function () {','\n        },\n        recheck:')+'};this.apply=call=>{'+extract('    const applyIncomingState = function (call) {','\n    };')+'};',env);
env.declinedInvitation=env.declined;
env.decline();for(let i=0;i<10;i++)env.decline();assert.equal(sent,1);
for(let i=0;i<10;i++)env.apply({pbx_call_id:'pbx-1',estado:'ringing'});
assert.equal(state.phase,'rejecting');assert.equal(finals,0);
// Recepción sin observador: el SDK vuelve a incoming con el mismo PBX.
env.window.zdrmWebPhone.callState='incoming';
Object.assign(env,{answeredAtMs:0,extension:'100'});
env.apply({pbx_call_id:'pbx-1',estado:'ringing',caller_id:'5550000000'});
assert.equal(state.phase,'incoming-ringing','Nuevo PUSH nativo libera la supresión sin requerir observer');
assert.equal(state.context.declineRequested,undefined);
env.declinedInvitations.set('pbx-1',{session:first,sdkState:'incoming'});
assert.equal(env.declined('pbx-1'),true,'Clases residuales no bastan sin transición SDK');
env.apply({pbx_call_id:'pbx-1',estado:'ended'});assert.equal(finals,1);
state.active=true;state.context={declineRequested:true};state.pbxCallId='pbx-1';
Object.assign(env,{widgetReady:true,answeredAtMs:0,muted:false});
vm.runInNewContext('this.ring=call=>{'+extract('                onRinging: function (call) {','\n                },\n                onSessionBound:')+'};',env);
env.ring({pushInvitation:true,caller:'5550000000'});
assert.equal(state.phase,'incoming-ringing');assert.equal(state.context.declineRequested,undefined);assert.equal(env.declinedInvitations.has('pbx-1'),false);
assert.match(state.callToken,/^sdk-/);assert.equal(finals,1);
console.log('PASS SDK/host: PUSH completo, reoferta sin reposo, DOM residual, SIP idempotente, permisos de sesión, END atrasado y terminal PBX.');
