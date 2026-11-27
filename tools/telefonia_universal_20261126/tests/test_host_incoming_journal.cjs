'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {create}=require('../../../public/javascript/telefonia_http.js');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_host.js','utf8');
const part=(a,b)=>{const i=source.indexOf(a),j=source.indexOf(b,i);assert(i>=0&&j>i);return source.slice(i,j);};
const turn=()=>new Promise(setImmediate);
const storage=()=>{const m=new Map();return {get length(){return m.size;},key:i=>[...m.keys()][i]??null,getItem:k=>m.get(k)??null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k)};};
function session(id){const events=new Map();return {id,direction:'incoming',ended:false,established:false,
 isEnded(){return this.ended;},isEstablished(){return this.established;},on(name,fn){if(!events.has(name))events.set(name,[]);events.get(name).push(fn);},emit(name){for(const fn of events.get(name)||[])fn();}};}
function fixture(protocol=2,options={}){
 let hooks;const posted=[],user=options.userId||42,origin=options.origin||'http://fixture.test';
 const parent={IMPE_PHONE_SHELL_CONFIG:{userId:user,sdkJournalProtocol:protocol,analystIncomingResults:user===42},IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG:{protocol}};
 const window={IMPE_CURRENT_USER_ID:user,IMPE_CSRF_TOKEN:'fixture-csrf',parent,location:{href:origin+'/',origin},
  localStorage:options.storage||storage(),Headers,Response,AbortController,setTimeout,clearTimeout,navigator:{onLine:true},zdrmWebPhone:{},
  IMPE_ZADARMA_SDK_RING_OBSERVER:{create(doc,callbacks){hooks=callbacks;return {}; }},
  fetch:options.fetch||(async(url,options)=>{const event=JSON.parse(options.body);posted.push(event);return new Response(JSON.stringify({ok:true,sesion:{session_id:event.session_id,ultima_secuencia:event.secuencia}}));})};
 window.IMPE_PHONE_HTTP=create(window);
 const box={window,document:{},Date,WeakMap,WeakSet,config:{sdkJournalProtocol:2,sesionLocalUrl:'/prueba_telefonia/api/sesion_local.php'},ownsTransport:true,
  modoEmbebido:true,permiteEntrantes:true,widgetReady:true,sdkIncomingObserver:null,transferFlow:null,extension:options.extension||'102',answeredAtMs:0,muted:false,
  state:{userId:user,active:false,phase:'ready',callToken:'',context:{}},
  publish(p){Object.assign(box.state,p);},declinedInvitations:new Map(),currentDuration:()=>3,isSdkOnlyIncoming:()=>box.state.active&&box.state.context?.type==='SDK_INCOMING',
  retirePbxCall(){},ocultarHostEntrante(){},marcarPestanaEntrante(){},pollIncoming:async()=>{},clearSdkOnlyIncoming(){},incomingBridge:null};
 vm.createContext(box);vm.runInContext(part('    let outgoingJournal = null;','    const fetchDialCheckpoint = async function')+
  part('    const startSdkIncomingObserver = function () {','    const initializeWidget = async function')+'\nstartSdkIncomingObserver();',box);
 return {box,window,parent,posted,hooks,drain:async()=>{await window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');await window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');}};
}
module.exports={fixture,session};
if(require.main===module)(async()=>{
 let checks=0;const f=fixture();f.hooks.onRinging({caller:'5550000000',sipSessionId:'sip-incoming-one'});
 const firstId=f.box.state.context.sdkSessionId;assert.match(firstId,/^sdk-/);assert.equal(f.box.state.context.sdkJournalProtocol,2);checks+=2;
 const first=session('sip-incoming-one');f.window.zdrmWebPhone.webCallSession=first;f.hooks.onSessionBound({sessionId:first.id});
 f.box.state.phase='in-progress';f.hooks.onSessionBound({sessionId:first.id});await f.drain();
 assert.deepEqual(f.posted.map(e=>e.evento),['ringing'],'PBX phase is not an SDK answer');checks++;
 first.established=true;first.emit('confirmed');f.hooks.onConnected({sessionId:first.id});await f.drain();
 assert.equal(f.posted.filter(e=>e.evento==='answered').length,1);checks++;
 assert.equal(f.box.state.context.sdkConnected,true);checks++;
 first.emit('ended');f.hooks.onIdle({sessionEnded:true,sessionId:first.id});await f.drain();
 assert.equal(f.box.state.active,false);assert.deepEqual(f.posted.map(e=>e.evento),['ringing','answered','ended']);checks+=2;
 const second=session('sip-incoming-two');first.ended=true;f.window.zdrmWebPhone.webCallSession=second;
 f.hooks.onRinging({caller:'5550000001',sipSessionId:second.id});f.hooks.onSessionBound({sessionId:second.id});
 const secondId=f.box.state.context.sdkSessionId;assert.notEqual(firstId,secondId);checks++;
 f.hooks.onIdle({sessionEnded:true,sessionId:first.id});assert.equal(f.box.state.active,true);assert.equal(f.box.state.context.sdkSessionId,secondId);checks+=2;
 second.emit('failed');f.hooks.onIdle({sessionEnded:true,sessionId:second.id});await f.drain();
 assert.deepEqual(f.posted.filter(e=>e.session_id===secondId).map(e=>e.evento),['ringing','failed']);checks++;
 const push=fixture();push.hooks.onRinging({caller:'',pushInvitation:true});push.hooks.onIdle({pushEnded:true,sessionId:''});await push.drain();
 assert.equal(push.box.state.active,false);assert.deepEqual(push.posted.map(e=>e.evento),['ringing','ended']);checks+=2;
 const old=fixture(1);old.hooks.onRinging({caller:'5550000000',sipSessionId:'sip-old-protocol'});
 old.parent.IMPE_PHONE_SHELL_CONFIG.sdkJournalProtocol=2;old.parent.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.protocol=2;
 const prior=session('sip-old-protocol');old.window.zdrmWebPhone.webCallSession=prior;old.hooks.onSessionBound({sessionId:prior.id});prior.established=true;prior.emit('confirmed');prior.emit('ended');await old.drain();
 assert.equal(old.posted.length,0,'A protocol1 attention cannot acquire a second host writer midway');checks++;
 const race=fixture();race.hooks.onRinging({caller:'5550000000',sipSessionId:'sip-race-old'});
 const a=session('sip-race-old');race.window.zdrmWebPhone.webCallSession=a;race.hooks.onSessionBound({sessionId:a.id});a.established=true;race.hooks.onConnected({sessionId:a.id});
 const priorToken=race.box.state.callToken;a.emit('ended');a.ended=true;
 const b=session('sip-race-new');race.window.zdrmWebPhone.webCallSession=b;
 assert.notEqual(race.hooks.onRinging({caller:'5550000001',sipSessionId:b.id}),false,'New INVITE cannot be blocked by the ended dialogue still visible in the old UI');checks++;
 race.hooks.onSessionBound({sessionId:b.id});race.hooks.onIdle({sessionEnded:true,sessionId:a.id});
 assert.notEqual(race.box.state.callToken,priorToken);assert.equal(race.box.state.active,true);checks+=2;
 const discovered=fixture(),live=session('sip-discovered-live');live.established=true;discovered.window.zdrmWebPhone.webCallSession=live;
 discovered.hooks.onRinging({caller:'5550000000',sipSessionId:live.id});discovered.hooks.onSessionBound({sessionId:live.id});await discovered.drain();
 assert.equal(discovered.box.state.phase,'in-progress');assert.deepEqual(discovered.posted.map(e=>e.evento),['ringing','answered']);checks+=2;
 console.log(`PASS ${checks} actual host incoming journal assertions: PUSH/direct SDK, exact answer/end/failure, delayed old callbacks and per-call protocol writer negotiation. Fake SDK/HTTP; not full-call acceptance.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
