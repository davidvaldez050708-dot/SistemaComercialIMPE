'use strict';
// Modelos de permisos/dispositivos y senders; no llaman a Zadarma ni capturan audio real.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/javascript/telefonia_audio_dispositivos.js','utf8');
const input=(deviceId,label,groupId=deviceId)=>({kind:'audioinput',deviceId,label,groupId});
const usb=input('usb','Logi USB Headset'), built=input('realtek','Varios micrófonos Realtek');
const fallback=input('default','Predeterminado - Logi USB Headset','usb');
const track=(deviceId,label)=>({kind:'audio',readyState:'live',enabled:true,label,
 getSettings:()=>({deviceId,echoCancellation:true}),getConstraints:()=>({deviceId:{exact:deviceId}}),stop(){this.readyState='ended';this.stopped=true;}});
async function setup(options={}){
 const store=options.store||new Map(),events={},listeners={},calls=[],timers=new Map();let serial=0;
 const env={state:{active:false,phase:'ready',callToken:''},session:null,list:options.list||[fallback,usb,built],permission:'granted',owner:true};
 const permissionHandle={get state(){return env.permission;},addEventListener(n,fn){listeners[n]=fn;},removeEventListener(n){delete listeners[n];}};
 const media={enumerateDevices:async()=>env.list,addEventListener(n,f){events[n]=f;},removeEventListener(n){delete events[n];},
  getUserMedia:async function(c){assert.equal(this,media);calls.push(c);if(env.capture)return env.capture(c);return {getTracks:()=>[]};}};
 const original=media.getUserMedia;
 const w={navigator:{mediaDevices:media,permissions:{query:async()=>permissionHandle}},localStorage:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)},
  setInterval:f=>{timers.set(++serial,f);return serial;},clearInterval:id=>timers.delete(id),addEventListener:(n,f)=>events[n]=f,removeEventListener:n=>delete events[n],
  parent:{},location:{search:''},document:{}};
 vm.runInNewContext(source,{window:w,URLSearchParams});
 const config={enabled:true,userId:33,extension:'155',...options.config};
 const manager=w.IMPE_AUDIO_DEVICES_FACTORY.create(w,config,()=>env.state,()=>env.session,()=>env.owner);
 if(manager)await manager.refresh();
 return {w,env,media,original,calls,manager,store,events,listeners,timers};
}
(async()=>{
 // Apagado: identidad de la captura intacta, sin lectores, permisos ni temporizadores.
 const off=await setup({config:{enabled:false}});assert.equal(off.manager,null);assert.equal(off.media.getUserMedia,off.original);assert.equal(off.timers.size,0);
 const other=await setup({config:{extension:'150'}});assert.equal(other.manager,null);assert.equal(other.media.getUserMedia,other.original);
 const good=await setup();assert.equal(good.calls.length,0,'Enumerar y observar nunca capturan');
 const sdk={audio:{deviceId:{exact:'usb'},echoCancellation:true,noiseSuppression:true,autoGainControl:true,sampleRate:48000},video:false};
 await good.media.getUserMedia(sdk);assert.equal(good.calls[0],sdk,'ID explícito SDK conservado incluso si CRM prefiere otro');
 good.manager.choose('realtek');await good.media.getUserMedia(sdk);assert.equal(good.calls[1],sdk);
 const advanced={audio:{advanced:[{deviceId:{exact:'usb'}}],noiseSuppression:true}};
 await good.media.getUserMedia(advanced);assert.equal(good.calls.at(-1),advanced,'Respetar también selección avanzada del SDK');
 good.manager.choose('');
 good.env.session={connection:{getSenders:()=>[{track:track('usb','Logi USB Headset')}]},isEnded:()=>false};
 good.env.state={active:true,phase:'in-progress',callToken:'a'};good.manager.observe();
 assert.equal(good.manager.snapshot().actual.deviceId,'usb');
 const normal={audio:true,video:false};await good.media.getUserMedia(normal);assert.equal(good.calls.at(-1),normal,'Diadema correcta intacta');
 good.env.session=null;good.env.state.active=false;good.manager.observe();await good.media.getUserMedia(normal);
 assert.equal(good.calls.at(-1),normal,'Respetar dispositivo previamente comprobado');

 // Selección manual antes de la siguiente captura; DSP y restricciones ajenas intactas.
 const manual=await setup();manual.manager.choose('usb');
 const constraints={audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true,channelCount:1},video:{width:640},peerIdentity:'test'};
 await manual.media.getUserMedia(constraints);
 const chosen=manual.calls.at(-1);assert.notEqual(chosen,constraints);
 assert.equal(chosen.audio.deviceId.exact,'usb');assert.equal(chosen.video,constraints.video);assert.equal(chosen.peerIdentity,'test');
 for(const p of ['echoCancellation','noiseSuppression','autoGainControl','channelCount'])assert.equal(chosen.audio[p],constraints.audio[p]);
 assert.equal(constraints.audio.deviceId,undefined,'No mutar opciones del proveedor');
 assert.equal(manual.manager.snapshot().actual,null,'La captura sola no acredita sender');
 const realtek=track('realtek','Realtek');
 manual.env.session={connection:{getSenders:()=>[{track:realtek}]}};manual.env.state={active:true,phase:'in-progress',callToken:'b'};manual.manager.observe();
 assert.equal(manual.manager.snapshot().actual.deviceId,'realtek');assert.equal(manual.manager.snapshot().pending,true);
 const actualUSB=track('usb','Logi USB Headset');manual.env.session.connection.getSenders=()=>[{track:actualUSB}];manual.manager.observe();
 assert.equal(manual.manager.snapshot().pending,false);actualUSB.enabled=false;manual.manager.observe();assert.equal(manual.manager.snapshot().actual.muted,true);
 // Ejecutar el control de silencio real del host, sobre la pista observada por el piloto.
 const host=fs.readFileSync('public/javascript/telefonia_host.js','utf8');
 const muteCode=host.slice(host.indexOf('    const syncMicrophone = function'),host.indexOf('    // Piloto de entrada:'));
 const muteEnv={window:{zdrmWebPhone:{webCallSession:manual.env.session}},state:manual.env.state,microphoneTracks:new Set(),microphoneSession:null,microphoneCallToken:'',muted:false,publish(){}};
 vm.runInNewContext(muteCode+';this.mute=setMuted;',muteEnv);
 muteEnv.mute(true);manual.manager.observe();assert.equal(actualUSB.enabled,false);assert.equal(manual.manager.snapshot().actual.muted,true);
 muteEnv.mute(false);manual.manager.observe();assert.equal(actualUSB.enabled,true);assert.equal(manual.manager.snapshot().actual.muted,false);

 // Cambio de preferencia y hardware durante llamada: ninguna captura adicional ni replaceTrack.
 const during=await setup();during.env.state={active:true,phase:'ringing',callToken:'live'};
 during.manager.choose('usb');await during.media.getUserMedia(normal);assert.equal(during.calls[0],normal,'No cambiar la llamada para la que se escogió en curso');
 during.env.list=[built];during.events.devicechange();await during.manager.refresh();
 assert.match(during.manager.snapshot().notice,/No modificaremos/);assert.equal(during.calls.length,1);
 assert.equal(await during.manager.authorize(),false,'Sin prueba auxiliar en llamada');
 during.env.state={active:false,phase:'finished',callToken:'live'};during.manager.observe();
 await during.media.getUserMedia(normal);assert.equal(during.calls.at(-1),normal,'Preferencia ausente no rompe teléfono');
 during.env.list=[fallback,usb,built];during.events.devicechange();await during.manager.refresh();
 during.env.state={active:true,phase:'dialing',callToken:'next'};
 await during.media.getUserMedia(normal);assert.equal(during.calls.at(-1).audio.deviceId.exact,'usb','Reconexión y llamada nueva recuperan preferencia');

 // Fallo de dispositivo que desaparece entre enumeración y captura: fallback una sola vez.
 const vanished=await setup();vanished.manager.choose('usb');vanished.env.capture=c=>{
  if(c.audio.deviceId){const e=new Error('device');e.name='OverconstrainedError';throw e;}return {getTracks:()=>[]};};
 await vanished.media.getUserMedia(normal);assert.equal(vanished.calls.length,2);assert.equal(vanished.calls[1],normal);
 vanished.env.capture=()=>{const e=new Error('permission');e.name='NotAllowedError';throw e;};
 await assert.rejects(()=>vanished.media.getUserMedia(normal),{name:'NotAllowedError'});assert.equal(vanished.calls.length,3,'No repetir permiso');

 // Persistencia por cuenta+extensión, sin guardar etiquetas, pistas ni audio.
 const reload=await setup({store:manual.store});assert.equal(reload.manager.snapshot().preferred,'usb');
 const another=await setup({store:manual.store,config:{userId:34}});assert.equal(another.manager.snapshot().preferred,'');
 const scopedKey=[...manual.store.keys()][0];assert.equal(scopedKey,'impe:audio-input:v1:33:155');
 assert.deepEqual(JSON.parse(manual.store.get(scopedKey)),{version:1,deviceId:'usb'});

 // Ambigüedad, etiquetas ocultas, predeterminado y única entrada externa.
 for(const list of [[usb,input('usb2','Otra USB')],[input('a',''),input('b','')],[fallback,usb,built]]){
  const e=await setup({list});await e.media.getUserMedia(normal);assert.equal(e.calls[0],normal);
 }
 const single=await setup({list:[usb]});await single.media.getUserMedia(normal);assert.equal(single.calls[0].audio.deviceId.exact,'usb');
 const unknown=await setup({list:[input('unknown','') ]});await unknown.media.getUserMedia(normal);assert.equal(unknown.calls[0],normal);

 // Comprobación voluntaria, parada inmediata de toda pista temporal y sin prueba al revocar.
 const voluntary=await setup();const temp=track('usb','USB');voluntary.env.capture=()=>({getTracks:()=>[temp]});
 assert.equal(await voluntary.manager.authorize(),true);assert.equal(temp.stopped,true);assert.equal(voluntary.manager.snapshot().actual,null);
 voluntary.env.permission='denied';voluntary.listeners.change();const before=voluntary.calls.length;
 assert.equal(await voluntary.manager.authorize(),false);assert.equal(voluntary.calls.length,before);
 voluntary.env.permission='granted';voluntary.listeners.change();assert.equal(voluntary.manager.snapshot().permission,'granted');
 voluntary.env.owner=false;assert.equal(await voluntary.manager.authorize(),false,'Host sin reserva no captura');
 const own={audio:true};voluntary.manager.choose('usb');await voluntary.media.getUserMedia(own);assert.equal(voluntary.calls.at(-1),own,'Sin reserva no impone selección');
 voluntary.manager.dispose();assert.equal(voluntary.media.getUserMedia,voluntary.original);assert.equal(voluntary.timers.size,0);
 assert.equal(voluntary.events.devicechange,undefined);
 console.log('PASS micrófonos: flag, SDK explícito, diadema existente, sender real, DSP intacto, selección diferida, reconexión, fallback, permisos, aislamiento y parada de pistas temporales.');
})().catch(e=>{console.error(e);process.exitCode=1;});
