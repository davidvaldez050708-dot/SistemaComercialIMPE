const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_host.js','utf8');
const start=source.indexOf('    const startCall = async function (payload) {');
const end=source.indexOf('\n    // JsSIP confirma',start);assert(start>0&&end>start);
const turn=()=>new Promise(setImmediate);
const deferred=()=>{let resolve,reject;return {promise:new Promise((a,b)=>{resolve=a;reject=b;}),resolve,reject};};
function fixture(){
 let calls=0,started=0,stopped=0,checkpoints=0;
 const box={state:{active:false,callToken:'',phase:'ready'},dialReservation:null,permiteSalientes:true,
  config:{},muted:false,answeredAtMs:0,lastCall:null,hangingUp:false,seguimientoPilot:null,
  window:{zdrmWebPhone:{regToCall(){calls++;}}},ensureWidget:async()=>{},fetchDialCheckpoint:async()=>{checkpoints++;return 123;},
  randomToken:()=> 'fixture-dial-token',normalizarDestinoWebrtc:x=>x,
  publish(p){Object.assign(box.state,p);},startPolling:()=>started++,stopPolling:()=>stopped++};
 vm.createContext(box);vm.runInContext(source.slice(start,end)+';invoke=startCall;',box);
 return {box,counts:()=>({calls,started,stopped,checkpoints})};
}
(async()=>{
 let checks=0;
 const double=fixture(),ready=deferred();double.box.ensureWidget=()=>ready.promise;
 const first=double.box.invoke({destination:'5550000000'});await double.box.invoke({destination:'5550000001'});
 assert.equal(double.counts().calls,0);checks++;
 ready.resolve();await first;
 assert.equal(double.counts().calls,1);assert.equal(double.counts().checkpoints,1);assert.equal(double.box.state.active,true);
 assert.equal(double.box.state.destination,'5550000000');assert.equal(double.box.dialReservation,null);checks+=5;
 for(const error of [false,true]){
  const f=fixture(),checkpoint=deferred();f.box.fetchDialCheckpoint=()=>checkpoint.promise;
  const outgoing=f.box.invoke({destination:'5550000000'});await turn();
  f.box.state={active:true,direction:'incoming',callToken:'incoming-current',phase:'incoming-ringing',status:'incoming-ringing'};
  if(error)checkpoint.reject(Error('Old checkpoint failed'));else checkpoint.resolve(234);
  await outgoing;
  assert.equal(f.box.state.callToken,'incoming-current');assert.equal(f.box.state.active,true);
  assert.equal(f.box.state.phase,'incoming-ringing');assert.equal(f.counts().calls,0);
  assert.equal(f.counts().stopped,0);assert.equal(f.box.dialReservation,null);checks+=6;
 }
 const partial=fixture();partial.box.window.zdrmWebPhone.regToCall=()=>{
  partial.box.window.zdrmWebPhone.webCallSession={direction:'outgoing',isEnded:()=>false};
  throw Error('SDK returned an error after creating its session');
 };
 await partial.box.invoke({destination:'5550000000'});
 assert.equal(partial.box.state.active,true);assert.equal(partial.box.state.phase,'recovering');
 assert.equal(partial.counts().started,1);assert.equal(partial.counts().stopped,0);checks+=4;
 const denied=fixture();denied.box.permiteSalientes=false;
 denied.box.state={active:true,direction:'incoming',callToken:'incoming',phase:'in-progress'};
 await denied.box.invoke({destination:'5550000000'});
 assert.equal(denied.box.state.phase,'in-progress');assert.equal(denied.box.state.active,true);checks+=2;
 console.log(`PASS ${checks} actual host dial race assertions: single preparation, incoming priority, stale success/error isolation, live SDK preserved after partial failure. No SDK/provider connection.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
