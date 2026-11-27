const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_host.js','utf8');
const start=source.indexOf('    const hangup = async function () {'),end=source.indexOf('\n    const clearFinished',start);
assert(start>0&&end>start);
const deferred=()=>{let resolve;return {promise:new Promise(r=>resolve=r),resolve};};
const turn=()=>new Promise(setImmediate);
(async()=>{
 let checks=0;
 for(const stage of ['sleep','response']){
  const wait=deferred();let queries=0,finishes=0,cancel=null;
  const box={state:{active:true,direction:'outgoing',callToken:'original',status:'in-progress',answeredAtMs:0},hangingUp:false,lastCall:null,
   window:{zdrmWebPhone:{webCallSession:{isEstablished:()=>true}}},
   publish(p){Object.assign(box.state,p);},endOutgoingWebrtc(early){cancel=early;},
   sleep:()=>stage==='sleep'?wait.promise:Promise.resolve(),
   fetchCallState:()=>{queries++;return wait.promise;},isFinal:()=>true,finishCall:()=>finishes++,console};
  vm.createContext(box);vm.runInContext(source.slice(start,end)+';pending=hangup();',box);
  await turn();assert.equal(cancel,false,'An established session is never treated as an early cancel');checks++;
  box.state={active:true,direction:'outgoing',callToken:'next',status:'in-progress'};box.hangingUp=false;
  wait.resolve(stage==='sleep'?null:{status:'completed',pbx_call_id:'original-pbx'});await box.pending;
  assert.equal(box.state.callToken,'next');assert.equal(box.state.status,'in-progress');
  assert.equal(box.hangingUp,false);assert.equal(finishes,0);assert.equal(box.lastCall,null);
  assert.equal(queries,stage==='sleep'?0:1);checks+=6;
 }
 console.log(`PASS ${checks} actual host hangup assertions: late sleep/HTTP cannot mutate the next call or its metadata, established SDK never early-cancelled. Synthetic only.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
