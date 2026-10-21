/* node tests/telefonia_sdk_ended_directo_smoke.cjs
 * El SDK puede retirar webCallSession entre dos sondeos sin modificar el DOM.
 * El END/FAILED SIP real debe cerrar solamente el diálogo observado.
 */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const src=fs.readFileSync(path.resolve(__dirname,'../public/javascript/telefonia_sdk_entrantes_observador.js'),'utf8');
const callbacks=new Map(),seen={ring:[],idle:[],connected:0},timers=[];
const session=(id)=>{
 const listeners={};let ended=false,established=false;
 return {id,direction:'incoming',remote_identity:{uri:{user:'521234567890'}},
  isEnded:()=>ended,isEstablished:()=>established,
  on:(event,cb)=>{(listeners[event]??=[]).push(cb);},
  emit:(event)=>{if(event==='ended'||event==='failed')ended=true;(listeners[event]||[]).forEach(cb=>cb());},
  connected:()=>{established=true;}
 };
};
const s1=session('sip-a'),s2=session('sip-b');
const win={zdrmWebPhone:{webCallSession:s1,callState:'incoming'},
 MutationObserver:class{observe(){}disconnect(){}},
 setInterval:cb=>{timers.push(cb);return 1;},clearInterval(){}};
const doc={body:{},defaultView:win,querySelector(sel){
 if(sel==='#zdrm-webphone-phonenumber-input.incoming')return {value:'+521234567890'};
 if(sel==='.zdrm-webphone-call-ico.zdrm-ringing')return {};
 if(sel==='.zdrm-webphone-callername')return {textContent:'Contacto'};
 return null;
}};
const root={};vm.runInNewContext(src,{window:root,module:{exports:{}},console});
const observer=root.IMPE_ZADARMA_SDK_RING_OBSERVER.create(doc,{
 onRinging:c=>{seen.ring.push(c);return true;},
 onStopped:()=>{},onIdle:e=>seen.idle.push(e),
 onConnected:()=>{seen.connected++;}
});
assert.equal(seen.ring.length,1);
assert.ok(observer);
assert.equal(seen.idle.length,0);
// Desapareció de la instancia del SDK antes de que transcurrieran 250 ms.
win.zdrmWebPhone.webCallSession=null;
s1.emit('ended');
assert.equal(seen.idle.length,1);
assert.equal(seen.idle[0].sessionEnded,true);
assert.equal(seen.idle[0].sessionId,'sip-a');
// Una invitación nueva puede entrar sin conservar el estado del anterior.
win.zdrmWebPhone.webCallSession=s2;
observer.checkNow();
assert.equal(seen.ring.length,2);
// El cierre antiguo repetido no afecta la nueva llamada.
s1.emit('ended');
assert.equal(seen.idle.length,1);
s2.emit('failed');
assert.equal(seen.idle.length,2);
assert.equal(seen.idle[1].sessionId,'sip-b');
observer.stop();
console.log('OK: END y FAILED SIP observados; diálogos anteriores aislados');
