'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {create}=require('../../../public/javascript/telefonia_http.js');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_host.js','utf8');
const part=(from,to)=>{const start=source.indexOf(from),end=source.indexOf(to,start);assert(start>=0&&end>start);return source.slice(start,end);};
const turn=()=>new Promise(setImmediate);
const storage=()=>{const m=new Map();return {get length(){return m.size;},key:i=>[...m.keys()][i]??null,getItem:k=>m.get(k)??null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k)};};
function sdk(id){
 const events=new Map();return {id,direction:'outgoing',ended:false,established:false,on(name,fn){if(!events.has(name))events.set(name,[]);events.get(name).push(fn);},
  isEnded(){return this.ended;},isEstablished(){return this.established;},emit(name,event){for(const fn of events.get(name)||[])fn(event);}};
}
function fixture(){
 const posted=[],preparations=[],finishes=[];let count=0,pulse,denied=false,holdEnd=null,holdPrepare=null;
 const window={IMPE_CURRENT_USER_ID:41,IMPE_CSRF_TOKEN:'fixture-csrf',location:{href:'http://fixture.test/',origin:'http://fixture.test'},
  localStorage:storage(),Headers,Response,AbortController,setTimeout,clearTimeout,setInterval(fn){pulse=fn;},
  navigator:{onLine:true},zdrmWebPhone:{regToCall(){window.zdrmWebPhone.webCallSession=sdk('sip-fixture-'+count);}},
  fetch:async(url,options)=>{
   const body=JSON.parse(options.body);
   if(body.accion==='preparar_saliente'){
    preparations.push(body);count++;
    if(holdPrepare)await holdPrepare;
    if(denied)return new Response(JSON.stringify({ok:false,mensaje:'Denied by fixture'}),{status:403});
    return new Response(JSON.stringify({ok:true,sesion:{session_id:'sdk-fixture-outgoing-'+count,extension:'101',after_id:100+count,server_time:1800000000+count}}));
   }
   posted.push(body);
   if(holdEnd&&body.evento==='ended')await holdEnd;
   return new Response(JSON.stringify({ok:true,sesion:{session_id:body.session_id,ultima_secuencia:body.secuencia}}));
  }};
 window.IMPE_PHONE_HTTP=create(window);
 const box={window,URLSearchParams,Date,WeakMap,WeakSet,config:{sesionLocalUrl:'/prueba_telefonia/api/sesion_local.php'},extension:'101',ownsTransport:true,
  state:{active:false,phase:'ready',callToken:''},dialReservation:null,permiteSalientes:true,
  muted:false,answeredAtMs:0,lastCall:null,hangingUp:false,seguimientoPilot:null,transferFlow:null,
  hostRequest:(...args)=>window.IMPE_PHONE_HTTP.request(...args),ensureWidget:async()=>{},normalizarDestinoWebrtc:x=>x,startPolling(){},stopPolling(){},
  publish(p){Object.assign(box.state,p);},finishCall(){finishes.push(box.state.callToken);box.state.active=false;box.state.phase='finished';}};
 vm.createContext(box);
 vm.runInContext(part('    let outgoingJournal = null;','    const fetchCallState = async function')+
  part('    const startCall = async function (payload) {','    // JsSIP confirma')+
  part('    const observedOutgoingSessions = new WeakMap();','    const hangup = async function')+
  '\ninvoke=startCall;',box);
 return {box,window,posted,preparations,finishes,pulse:()=>pulse(),deny:()=>denied=true,hold:promise=>holdEnd=promise,pausePreparation:promise=>holdPrepare=promise};
}
(async()=>{
 let checks=0;const f=fixture();
 await f.box.invoke({destination:'5550000000',context:{type:'VINCULACION',seguimientoId:901}});
 assert.equal(f.preparations.length,1);assert.equal(f.preparations[0].accion,'preparar_saliente');checks+=2;
 assert.equal(f.box.state.callToken,'sdk-fixture-outgoing-1');assert.equal(f.box.state.requestedAt,1800000001);checks+=2;
 assert.equal(f.box.state.context.sdkSessionId,f.box.state.callToken);assert.equal(f.box.state.context.seguimientoId,901);checks+=2;
 const first=f.window.zdrmWebPhone.webCallSession;
 f.pulse();await turn();await turn();assert.equal(f.posted[0].evento,'dialed');checks++;
 first.emit('accepted');f.pulse();await turn();assert.equal(f.posted.some(e=>e.evento==='answered'),false);checks++;
 first.established=true;first.emit('confirmed');f.pulse();await turn();await turn();
 assert.equal(f.posted.filter(e=>e.evento==='answered').length,1);checks++;
 let release;f.hold(new Promise(resolve=>release=resolve));first.emit('ended');await turn();
 assert.equal(f.box.state.active,false);assert.equal(f.finishes.length,1);checks+=2;
 await f.box.invoke({destination:'5550000000'});const second=f.window.zdrmWebPhone.webCallSession;f.pulse();
 assert.notEqual(second,first);assert.equal(f.box.state.callToken,'sdk-fixture-outgoing-2');checks+=2;
 first.emit('failed');assert.equal(f.box.state.active,true);assert.equal(f.finishes.length,1);checks+=2;
 release();await turn();await turn();await f.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 assert.deepEqual(f.posted.filter(e=>e.session_id==='sdk-fixture-outgoing-1').map(e=>e.evento),['dialed','answered','ended']);checks++;
 assert.equal(f.posted.every(e=>e.sdk_id?.startsWith('sip-fixture-')),true);checks++;
 assert.equal(f.posted.every(e=>!('pbx_call_id' in e)),true);checks++;
 second.ended=true;f.pulse();await turn();await turn();assert.equal(f.box.state.active,false);checks++;
 const rejected=fixture();rejected.deny();await rejected.box.invoke({destination:'5550000000'});
 assert.equal(rejected.window.zdrmWebPhone.webCallSession,undefined);assert.equal(rejected.posted.length,0);checks+=2;
 const context={session_id:'sdk-fixture-immutable-recorder',direccion:'ENTRANTE',numero:'5550000000'};
 const recorder=f.window.IMPE_PHONE_HTTP.createSessionRecorder(context);context.numero='5559999999';recorder.bind('sip-fixture-immutable');recorder.start();recorder.answered();recorder.end();recorder.end();
 assert.equal(recorder.snapshot().secuencia,3);assert.throws(()=>recorder.bind('sip-other'),/no corresponde/);checks+=2;
 const raw=[];for(let i=0;i<f.window.localStorage.length;i++)raw.push(JSON.parse(f.window.localStorage.getItem(f.window.localStorage.key(i))));
 assert.equal(raw.filter(e=>e.session_id===context.session_id).every(e=>e.numero==='5550000000'),true);checks++;
 const fast=fixture();fast.window.zdrmWebPhone.regToCall=()=>{const session=sdk('sip-fixture-fast');session.ended=true;fast.window.zdrmWebPhone.webCallSession=session;};
 await fast.box.invoke({destination:'5550000000'});
 await fast.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 await fast.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 assert.deepEqual(fast.posted.map(e=>e.evento),['dialed','ended']);assert.equal(fast.box.state.active,false);checks+=2;
 const beforeSdk=fixture();beforeSdk.window.zdrmWebPhone.regToCall=()=>{throw Error('Synthetic failure before SDK dialog');};
 await beforeSdk.box.invoke({destination:'5550000000'});await beforeSdk.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 assert.deepEqual(beforeSdk.posted.map(e=>e.evento),['failed']);assert.equal(beforeSdk.posted[0].secuencia,1);checks+=2;
 const stale=fixture();stale.window.zdrmWebPhone.webCallSession=sdk('sip-fixture-before-intent');stale.window.zdrmWebPhone.regToCall=()=>{};
 await stale.box.invoke({destination:'5550000000'});stale.pulse();await turn();assert.equal(stale.posted.length,0);checks++;
 const priority=fixture();let continuePrepare;priority.pausePreparation(new Promise(resolve=>continuePrepare=resolve));
 const preparation=priority.box.invoke({destination:'5550000000'});await turn();
 priority.box.state={active:true,direction:'incoming',phase:'incoming-ringing',callToken:'sdk-fixture-priority-incoming'};
 continuePrepare();await preparation;await priority.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 assert.equal(priority.box.state.callToken,'sdk-fixture-priority-incoming');assert.equal(priority.box.state.active,true);checks+=2;
 assert.equal(priority.window.zdrmWebPhone.webCallSession,undefined);assert.deepEqual(priority.posted.map(e=>e.evento),['failed']);checks+=2;
 const terminal=f.window.IMPE_PHONE_HTTP.createSessionRecorder({session_id:'sdk-fixture-terminal-preparation',direccion:'SALIENTE'});
 terminal.end('failed');terminal.start();terminal.answered();terminal.end();
 assert.equal(terminal.snapshot().secuencia,1);assert.equal(terminal.snapshot().started,false);checks+=2;
 console.log(`PASS ${checks} actual host outgoing journal assertions: server preparation, exact SDK events, answer eligibility, lost ACK across next call, old callbacks, immutable context and denied preparation. Fake SDK/HTTP; not full-call acceptance.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
