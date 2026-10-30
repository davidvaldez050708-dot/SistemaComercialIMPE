'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{EventEmitter}=require('node:events');
const root=process.env.IMPE_RECEPTION_CLOSE_SOURCE_ROOT||'.';
const bridgeSource=fs.readFileSync(root+'/public/javascript/telefonia_entrantes_bridge.js','utf8');
const hostSource=fs.readFileSync(root+'/public/javascript/telefonia_host.js','utf8').replace(/\r\n/g,'\n');
let now=100000;class Clock extends Date{static now(){return now;}}
const bridgeContext={module:{exports:{}},Date:Clock,console};
vm.runInNewContext(bridgeSource,bridgeContext);
const factory=bridgeContext.module.exports;
const extract=(start,end)=>{
 const a=hostSource.indexOf(start);assert(a>=0,start);const b=hostSource.indexOf(end,a+start.length);assert(b>a,end);return hostSource.slice(a+start.length,b);
};
function setup({sdkIncoming=false,session=null}={}){
 const dom={ring:true};const sdk={callState:'incoming',webCallSession:session,talking:0,calls:0,finishCall(){this.calls++;this.callState='canceling';}};
 const doc={defaultView:{zdrmWebPhone:sdk,navigator:{onLine:true}},querySelector(){return dom.ring?{}:null;}};
 const env={state:{active:true,direction:'incoming',phase:'incoming-ringing',callToken:'call-a',pbxCallId:'pbx-a',context:{}},config:{analystSdkIncoming:sdkIncoming},permiteEntrantes:true,answeredAtMs:0,muted:false,widgetReady:true,window:doc.defaultView,declinedInvitations:new Map(),analystSdkObserver:null,actualizarEstadoTrasAccion(){},emptyState:()=>({active:false,direction:'',phase:'idle',context:{}}),published:[],hides:0,ocultarHostEntrante(){env.hides++;},marcarPestanaEntrante(){},publish(p){Object.assign(env.state,p);env.published.push({...env.state});}};
 env.incomingBridge=factory.create(doc,()=>env.state.phase==='incoming-ringing',()=>env.state.phase==='in-progress',()=>env.state.callToken);
 vm.runInNewContext('this.reconcileReceptionDecline=function(){'+extract('    const reconcileReceptionDecline = function () {','\n    };')+'};this.decline=function(){'+extract('        decline: function () {','\n        },\n        recheck:')+'};',env);
 return {env,sdk,dom,doc,bridge:env.incomingBridge};
}
const push=setup();assert.equal(push.env.decline(),true);
assert.equal(push.env.state.phase,'rejecting');assert.equal(push.sdk.calls,1);
now+=60000;assert.equal(push.env.reconcileReceptionDecline(),false,'Ni 60 segundos inventan cierre mientras canceling sigue activo');
push.sdk.callState='';assert.equal(push.env.reconcileReceptionDecline(),false,'No cerrar mientras el widget aún timbra');
push.dom.ring=false;push.sdk.talking=1; // Un flag escalar residual del SDK no acredita una RTCSession.
assert.equal(push.env.reconcileReceptionDecline(),false);
now+=1999;assert.equal(push.env.reconcileReceptionDecline(),false);
now++;assert.equal(push.env.reconcileReceptionDecline(),true);
assert.equal(push.env.state.active,false);assert.equal(push.env.state.phase,'ready');assert.equal(push.env.hides,1);
assert.equal(push.env.state.pbxEnded,undefined,'No crear END PBX ni historial sintético');
assert.equal(push.env.reconcileReceptionDecline(),false,'Cierre idempotente');
assert.equal(push.sdk.calls,1,'Comprobar estado no reenvía colgados');

// La ventana de reposo se reinicia si el timbrado vuelve o se pierde la red.
const interrupted=setup();interrupted.env.decline();interrupted.sdk.callState='';interrupted.dom.ring=false;
interrupted.env.reconcileReceptionDecline();now+=1500;interrupted.dom.ring=true;interrupted.env.reconcileReceptionDecline();
now+=5000;interrupted.dom.ring=false;assert.equal(interrupted.env.reconcileReceptionDecline(),false);
now+=1000;interrupted.doc.defaultView.navigator.onLine=false;assert.equal(interrupted.env.reconcileReceptionDecline(),false);
now+=5000;interrupted.doc.defaultView.navigator.onLine=true;assert.equal(interrupted.env.reconcileReceptionDecline(),false);
now+=2000;assert.equal(interrupted.env.reconcileReceptionDecline(),true);

function sip(id){
 const s=new EventEmitter();Object.assign(s,{id,direction:'incoming',ended:false,established:false,sent:0,isEnded(){return this.ended;},isEstablished(){return this.established;},terminate(){this.sent++;}});return s;
}
const first=sip('sip-a'),direct=setup({session:first});
// La referencia se retira antes de que isEnded cambie, tal como hace el SDK.
first.terminate=function(){this.sent++;direct.sdk.webCallSession=null;this.emit('failed');};
assert.equal(direct.env.decline(),true);assert.equal(direct.env.state.phase,'ready','Evento terminal síncrono de la sesión exacta libera Recepción');
assert.equal(first.sent,1);

const old=sip('old'),newer=setup({session:old});newer.env.decline();
newer.sdk.webCallSession=sip('new-live');old.ended=true;old.emit('failed');
assert.equal(newer.env.reconcileReceptionDecline(),false,'END viejo no cierra otra sesión viva');
assert.equal(newer.env.state.active,true);
newer.sdk.webCallSession=old;old.established=true;assert.equal(newer.env.reconcileReceptionDecline(),false,'No cerrar una sesión establecida');
old.established=false;newer.env.state.context.pbxAnswered=true;assert.equal(newer.env.reconcileReceptionDecline(),false,'No cerrar si PBX acreditó respuesta');

for(const sdkIncoming of [true]){
 const sales=setup({sdkIncoming,session:sip('sales')});sales.env.decline();sales.sdk.webCallSession.ended=true;sales.sdk.webCallSession.emit('failed');
 assert.equal(sales.env.reconcileReceptionDecline(),false,'Analista y Ventas conservan su observador y su flujo de resultados');
 assert.equal(sales.env.state.active,true);
}
const reused=setup();reused.env.decline();reused.bridge.allowNewInvitation('call-a');
reused.env.state.context.declineRequested=true;assert.equal(reused.bridge.declineStatus('call-a').status,'pending','No reutilizar confirmación de una invitación anterior');
const failed=setup();failed.sdk.finishCall=()=>false;assert.equal(failed.env.decline(),false);assert.equal(failed.bridge.declineStatus('call-a').status,'pending');
console.log('PASS cierre local: SIP terminal incluso síncrono, reposo PUSH sostenido, timbre residual, red interrumpida, diálogo nuevo, respuesta confirmada y separación de Analista/Ventas.');
