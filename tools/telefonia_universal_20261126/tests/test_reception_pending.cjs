const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const common=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_atencion.js','utf8');
const closure=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_cierre.js','utf8');
const storage=()=>{const m=new Map();return {get length(){return m.size;},key:i=>[...m.keys()][i]??null,getItem:k=>m.get(k)??null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k)};};
const turn=()=>new Promise(setImmediate);
function boot(persistent=storage()){
 let now=1800000000000,calls=[];
 const window={IMPE_CURRENT_USER_ID:43,IMPE_PHONE_SHELL_CONFIG:{userId:43,extension:'100',callNoticesUrl:'/notices',receptionResults:true,routingCsrf:'fixture'},
  location:{href:'http://fixture.test/',origin:'http://fixture.test'},setInterval(){return 1;},addEventListener(){},
  IMPE_PHONE_HTTP:{csrfToken:()=> 'fixture',request:async(url,options)=>({ok:true,status:200,json:async()=>options?.method==='POST'?{ok:true}:{ok:true,extension:'100',llamadas:calls}})}};
 const box={window,document:{baseURI:'http://fixture.test/',readyState:'loading',addEventListener(){},querySelector(){return null;}},
  Date:{now:()=>now},URL,Set,Map,WeakMap,localStorage:persistent,sessionStorage:storage()};
 vm.createContext(box);vm.runInContext(common,box);vm.runInContext(closure,box);
 return {window,persistent,drafts:window.IMPE_CALL_DRAFTS,close:window.IMPE_CALL_CLOSURE,
  rows:items=>calls=items,advance:ms=>now+=ms,now:()=>now};
}
(async()=>{
 let checks=0;
 const f=boot();await turn();
 for(const id of ['sdk-fixture-first-call','sdk-fixture-second-call']){
  f.close.onState({userId:43,active:true,phase:'in-progress',direction:'incoming',callToken:id,context:{}});
  f.close.onState({userId:43,active:false,phase:'incoming-finished',direction:'incoming',callToken:id,context:{}});
 }
 await turn();let captures=f.drafts.list(43);
 assert.equal(captures.length,2);assert.equal(captures.every(d=>d.contexto.cierre_local.conciliado===false),true);checks+=2;
 assert.equal(f.close.hasPending(),false,'A delayed PBX identification alone cannot block the next call');checks++;
 // A same-number historical PBX row is not an alias of either local attention.
 f.rows([{id:'fixture-unrelated-pbx',aliases:[],numero:'5550000000',finalizada:true,requiere_resultado:true,fin_ts:f.now()-86400000}]);
 f.close.showPending();await turn();assert.equal(f.close.hasPending(),false);assert.equal(f.drafts.list(43).length,2);checks+=2;
 f.rows(['first','second'].map(name=>({id:'fixture-pbx-'+name,aliases:['sdk-fixture-'+name+'-call'],finalizada:true,requiere_resultado:true,fin_ts:f.now()})));
 f.close.showPending();await turn();
 assert.equal(f.close.hasPending(),true);assert.equal(f.drafts.list(43).filter(d=>d.contexto.cierre_local.conciliado).length,2);checks+=2;
 const stored=JSON.parse(f.persistent.getItem('impe:call-closure:43:100'));
 assert.equal(stored.pending.length,2);assert.equal(stored.waiting,undefined);checks+=2;
 const afterReload=boot(f.persistent);await turn();assert.equal(afterReload.drafts.list(43).length,2);checks++;
 const legacyStore=storage();legacyStore.setItem('impe:call-closure:43:100',JSON.stringify({pending:[],seen:[],forms:{'fixture-legacy-call':{nota:'Preserve legacy note'}},waiting:{id:'fixture-legacy-call',at:1799999999000,transfer:false}}));
 const legacy=boot(legacyStore);await turn();
 assert.equal(legacy.drafts.list(43)[0].valores.nota,'Preserve legacy note');assert.equal(JSON.parse(legacyStore.getItem('impe:call-closure:43:100')).waiting,undefined);checks+=2;
 // An empty notice is closed only after the exact authenticated row says no
 // result is needed. Its close retry uses the same durable capture mechanism.
 const empty=boot();await turn();empty.close.onState({userId:43,active:true,phase:'in-progress',callToken:'fixture-empty-call',context:{}});
 empty.close.onState({userId:43,active:false,phase:'incoming-finished',callToken:'fixture-empty-call',context:{}});await turn();
 empty.rows([{id:'fixture-empty-call',aliases:[],finalizada:true,requiere_resultado:false,fin_ts:empty.now()}]);empty.close.showPending();await turn();
 assert.equal(empty.drafts.list(43).length,0);checks++;
 console.log(`PASS ${checks} Reception pending-capture assertions with actual common storage and closure adapter: consecutive unresolved calls retained, exact alias resolution, no phone matching, reload/legacy migration and empty-notice cleanup. No SDK/SQL/provider calls.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
