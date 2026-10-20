const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/javascript/telefonia_host.js', 'utf8').replace(/\r\n/g, '\n');
const start = source.indexOf('    const syncMicrophone = function () {');
const end = source.indexOf('    const fetchWebRtcSession = ', start);
assert(start > 0 && end > start);
// Ejecutar las funciones compartidas reales, sin duplicar su implementación.
const factory = `(function(window,state){
 let muted=false,microphoneSession=null,microphoneCallToken='';
 const microphoneTracks=new Set();
 const publish=patch=>{Object.assign(state,patch);syncMicrophone();};
 ${source.slice(start,end)}
 return {state,setMuted,syncMicrophone,update:patch=>publish(patch)};
})`;
module.exports = factory;
if (require.main === module) {
 const track = kind => ({kind,readyState:'live',enabled:true});
 for (const role of ['Recepción','Analista','Marketing','Ventas']) {
  for (const direction of ['incoming','outgoing']) {
   const audio=track('audio'),second=track('audio'),remote=track('audio'),video=track('video');
   const senders=[{track:audio},{track:second},{track:video},{track:null}];
   const win={zdrmWebPhone:{webCallSession:{connection:{getSenders:()=>senders,getReceivers:()=>[{track:remote}]},isEnded:()=>false}}};
   const c=vm.runInNewContext(factory)(win,{active:true,phase:'in-progress',direction,callToken:'first'});
   c.setMuted(true);assert.equal(audio.enabled,false,role);assert.equal(second.enabled,false);assert.equal(c.state.muted,true);
   assert.equal(remote.enabled,true,'No silenciar recepción de audio');assert.equal(video.enabled,true);
   c.setMuted(false);assert.equal(audio.enabled,true);assert.equal(second.enabled,true);assert.equal(c.state.muted,false);
   c.setMuted(true);const replacement=track('audio');senders[0].track=replacement;c.syncMicrophone();assert.equal(replacement.enabled,false,'Renegociación conserva silencio');
   replacement.enabled=true;c.syncMicrophone();assert.equal(c.state.muted,false,'Estado externo real se refleja en el botón');
   c.setMuted(true);c.update({active:false});assert.equal(c.state.muted,false);assert.equal(replacement.enabled,true);assert.equal(second.enabled,true);
   c.update({active:true,callToken:'second'});c.setMuted(true);
   const next=track('audio');win.zdrmWebPhone.webCallSession={connection:{getSenders:()=>[{track:next}]},isEnded:()=>false};
   c.syncMicrophone();assert.equal(c.state.muted,false,'Una sesión nueva no hereda silencio');assert.equal(next.enabled,true);assert.equal(replacement.enabled,true);
   c.setMuted(true);c.update({callToken:'third'});assert.equal(c.state.muted,false,'Cambio de llamada con transporte reutilizado');assert.equal(next.enabled,true);
   next.readyState='ended';c.setMuted(true);assert.equal(c.state.muted,false);assert.match(c.state.message,/No hay un micrófono/);
  }
 }
 const c=vm.runInNewContext(factory)({}, {active:true,callToken:'none'});c.setMuted(true);assert.equal(c.state.muted,false);
 assert(!source.includes('__impePersistentWrapped'),'No interceptar getUserMedia global');
 console.log('Micrófono compartido: entrante/saliente, cuatro perfiles, envío/recepción, renegociación, estado externo y reinicio: OK');
}
