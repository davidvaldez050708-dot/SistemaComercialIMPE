const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const read=p=>fs.readFileSync(require('node:path').join(__dirname,'../public/javascript/',p),'utf8');
(async()=>{
 const store=new Map(),intervals=[],urls=[];let ended=false;
 const win={IMPE_TELEPHONY_HOST:{userId:7,extension:'101',permiteEntrantes:true,permiteSalientes:true,permiteTransferir:true,entradaUrl:'http://test/entrada'},
  location:{search:'?display=embed'},setTimeout(){},clearTimeout(){},setInterval(fn){intervals.push(fn);},localStorage:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v)},
  zdrmWebPhone:{webCallSession:{isEnded:()=>ended}},IMPE_ZADARMA_WEBRTC_DTMF:{send:()=>true}};
 const doc={addEventListener(){},querySelector:()=>null,body:{classList:{add(){},remove(){}}}};
 win.Date=Date;win.navigator={};win.Proxy=Proxy;win.Reflect=Reflect;win.URL=URL;
 vm.runInNewContext(read('telefonia_conexion.js'),{window:win});
 const code=read('telefonia_host.js').replace("    publish({\n        phase: 'loading',", `window.test={set(v){state=Object.assign(emptyState(),v);},get(){return state;},fetchIncomingState,applyIncomingState,sendTransferDtmf};return;    publish({\n        phase: 'loading',`);
 vm.runInNewContext(code,{window:win,document:doc,navigator:win.navigator,localStorage:win.localStorage,URLSearchParams,Date,Map,Set,WeakSet,console,addEventListener(){},setInterval:win.setInterval,
  fetch:async u=>{urls.push(u);return {ok:true,json:async()=>({ok:true,call:null})}}});
 win.test.set({active:true,direction:'incoming',phase:'incoming-ringing',pbxCallId:'sdk-local-only',context:{type:'SDK_INCOMING'}});
 await win.test.fetchIncomingState();assert.equal(new URL(urls.at(-1)).searchParams.has('pbx_call_id'),false,'no buscar un identificador provisional en la centralita');
 win.test.set({active:true,direction:'incoming',phase:'incoming-ringing',pbxCallId:'pbx-real-123',context:{type:'INCOMING'}});
 await win.test.fetchIncomingState();assert.equal(new URL(urls.at(-1)).searchParams.get('pbx_call_id'),'pbx-real-123','consulta exacta con identificador definitivo');
 win.test.set({active:true,direction:'incoming',phase:'in-progress',pbxCallId:'pbx-real-123',context:{type:'INCOMING'}});
 await win.test.sendTransferDtmf('102',true);
 win.test.applyIncomingState({pbx_call_id:'pbx-real-123',estado:'transferred',transfer_to:'102'});
 assert.equal(win.test.get().active,true,'consulta con sesión viva no se cierra al empezar a timbrar el destinatario');
 win.test.applyIncomingState({pbx_call_id:'pbx-real-123',estado:'answered',caller_id:'5551234567',returned_from:'102'});
 assert.equal(win.test.get().context.transferRequest,null,'devolución confirmada limpia la consulta anterior');
 ended=true;win.test.applyIncomingState({pbx_call_id:'pbx-real-123',estado:'transferred',transfer_to:'102'});
 assert.equal(win.test.get().active,false,'entrega final con sesión cerrada termina el panel de origen');

 let audioTick,started=0,stopped=0,samplesLoud=false,connected=false;
 class AudioContext{
  constructor(){this.state='running';this.currentTime=0;this.destination={};}
  createGain(){return {gain:{setTargetAtTime(){}},connect(){},disconnect(){}};}
  createOscillator(){return {frequency:{},connect(){},disconnect(){},start(){started++;},stop(){stopped++;}};}
  createMediaStreamSource(){return {connect(){},disconnect(){}};}
  createAnalyser(){return {fftSize:256,getByteTimeDomainData(a){a.fill(samplesLoud?160:128)},disconnect(){}};}
 }
 const track={kind:'audio',readyState:'live'};
 const awin={AudioContext,setInterval(fn){audioTick=fn;return 1;},addEventListener(){},zdrmWebPhone:{webCallSession:{isEstablished:()=>connected,isEnded:()=>false,connection:{getReceivers:()=>[{track}]}}}};
 vm.runInNewContext(read('telefonia_audio.js'),{window:awin,document:{getElementById:()=>null},Date,Uint8Array,MediaStream:class{constructor(tracks){this.tracks=tracks;}getAudioTracks(){return this.tracks;}}});
 const audio=awin.IMPE_CALL_AUDIO;audio.unlock();
 audio.update({active:true,direction:'outgoing',phase:'dialing',callToken:'one'});assert.equal(started,0,'marcar no inventa un timbrado');
 audio.update({active:true,direction:'outgoing',phase:'ringing',callToken:'one'});assert.equal(started,2,'tono local cuando el destino está timbrando y no hay audio remoto');
 samplesLoud=true;audioTick();assert.equal(stopped,2,'el tono cede ante audio o locución de la operadora');
 samplesLoud=false;audio.update({active:true,direction:'outgoing',phase:'ringing',callToken:'two'});connected=true;audioTick();assert.equal(stopped,2,'el puente SIP establecido no interrumpe el tono mientras el destino sigue timbrando');
 audio.update({active:true,direction:'outgoing',phase:'in-progress',callToken:'two'});assert.equal(stopped,4,'se detiene al confirmar la conversación');
 audio.update({active:false,phase:'finished',callToken:'two'});assert.equal(started,stopped,'sin tonos residuales tras colgar');
 console.log('Motor: identidad provisional/definitiva, consulta, devolución, entrega, timbrado y audio remoto: OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
