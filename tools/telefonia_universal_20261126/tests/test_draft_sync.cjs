const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_atencion.js','utf8');
const dataStore=()=>{const m=new Map();return {get length(){return m.size;},key:i=>[...m.keys()][i]??null,getItem:k=>m.get(k)??null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k)};};
const deferred=()=>{let resolve;return {promise:new Promise(r=>resolve=r),resolve};};
const turn=()=>new Promise(setImmediate);
const reply=(status=200)=>({ok:status===200,status,json:async()=>({ok:status===200,mensaje:'synthetic'})});
function fixture(persistent=dataStore()){
 let now=1800000000000,sends=0,respond=async()=>reply();
 const window={IMPE_CURRENT_USER_ID:42,navigator:{onLine:true},location:{href:'http://fixture.test/',origin:'http://fixture.test'},
  IMPE_PHONE_HTTP:{userId:42,request:(url,options)=>{sends++;return respond(url,options);}}};
 const box={window,document:{baseURI:'http://fixture.test/',readyState:'loading',addEventListener(){}},URL,WeakMap,Set,Map,
  Date:{now:()=>now},localStorage:persistent,sessionStorage:dataStore()};
 vm.runInNewContext(source,box);
 return {api:window.IMPE_CALL_DRAFTS,window,persistent,sent:()=>sends,respond:fn=>respond=fn,advance:ms=>now+=ms};
}
(async()=>{
 let checks=0;
 const single=fixture(),gate=deferred();single.api.localSave(42,['fixture-single'],{notas:'A'});single.respond(()=>gate.promise);
 const one=single.api.sync(42,'csrf'),two=single.api.sync(42,'csrf');await turn();
 assert.equal(one,two);assert.equal(single.sent(),1);checks+=2;
 single.api.localSave(42,['fixture-single'],{notas:'Newer edit'});gate.resolve(reply());await one;
 assert.equal(single.api.pending(42).length,1);assert.equal(single.api.pending(42)[0].valores.notas,'Newer edit');checks+=2;
 single.respond(async()=>reply());await single.api.sync(42,'csrf');assert.equal(single.api.pending(42).length,0);checks++;
 const net=fixture();net.api.localSave(42,['fixture-network'],{notas:'Keep'});net.respond(async()=>reply(503));
 await net.api.sync(42,'csrf');await net.api.sync(42,'csrf');assert.equal(net.sent(),1);assert.equal(net.api.pending(42).length,1);checks+=2;
 net.advance(2001);net.respond(async()=>reply());assert.equal((await net.api.sync(42,'csrf')).saved,1);checks++;
 const conflicts=fixture();for(let i=0;i<9;i++)conflicts.api.localSave(42,['fixture-conflict-'+i],{notas:String(i)});
 conflicts.respond(async(url,options)=>reply(JSON.parse(options.body).ids[0].endsWith('-8')?200:409));
 await conflicts.api.sync(42,'csrf');assert.equal(conflicts.sent(),8);checks++;
 assert.equal((await conflicts.api.sync(42,'csrf')).saved,1);assert.equal(conflicts.sent(),9);assert.equal(conflicts.api.pending(42).length,8);checks+=3;
 const auth=fixture();auth.api.localSave(42,['fixture-auth'],{notas:'Keep'});auth.respond(async()=>reply(403));
 await auth.api.sync(42,'old');await auth.api.sync(42,'old');assert.equal(auth.sent(),1);checks++;
 auth.respond(async()=>reply());assert.equal((await auth.api.sync(42,'fresh')).saved,1);assert.equal(auth.api.pending(42).length,0);checks+=2;
 const offline=fixture();offline.api.localSave(42,['fixture-offline'],{notas:'Keep'});offline.window.navigator.onLine=false;
 await offline.api.sync(42,'csrf');assert.equal(offline.sent(),0);checks++;
 offline.window.navigator.onLine=true;assert.equal((await offline.api.sync(42,'csrf')).saved,1);checks++;
 const parent=fixture(),child=fixture();child.window.parent=parent.window;
 parent.api.localSave(42,['fixture-parent'],{notas:'Parent owns delivery'});
 assert.equal((await child.api.sync(42,'csrf')).saved,1);assert.equal(child.sent(),0);assert.equal(parent.sent(),1);checks+=3;
 const foreign=fixture();foreign.window.parent=parent.window;foreign.window.IMPE_CURRENT_USER_ID=41;
 await assert.rejects(foreign.api.sync(42,'csrf'),/otra sesión/);assert.equal(foreign.sent(),0);checks+=2;
 const closing=fixture();const snapshot=closing.api.localSave(42,['fixture-close'],{notas:'Confirmed snapshot'});
 closing.respond(async()=>reply(503));await assert.rejects(closing.api.remove(42,['fixture-close'],'old',snapshot.saved_at));
 assert.equal(closing.api.pending(42)[0].pending_close.revision,snapshot.saved_at);checks++;
 const restarted=fixture(closing.persistent);let closeBody;
 restarted.respond(async(url,options)=>{closeBody=JSON.parse(options.body);return reply();});
 const closed=await restarted.api.sync(42,'fresh');
 assert.equal(closed.closed,1);assert.equal(closeBody.accion,'eliminar');assert.equal(closeBody.csrf_token,'fresh');
 assert.equal(restarted.api.pending(42).length,0);checks+=4;
 const changed=fixture();const prior=changed.api.localSave(42,['fixture-newedit'],{notas:'Before'});
 changed.respond(async()=>reply(503));await assert.rejects(changed.api.remove(42,['fixture-newedit'],'csrf',prior.saved_at));
 changed.api.localSave(42,['fixture-newedit'],{notas:'After native ACK'});
 let latestBody;changed.respond(async(url,options)=>{latestBody=JSON.parse(options.body);return reply();});
 await changed.api.sync(42,'csrf');assert.equal(latestBody.accion,undefined);assert.equal(latestBody.valores.notas,'After native ACK');checks+=2;
 console.log(`PASS ${checks} common draft synchronization assertions: single sender, bounded batch/backoff, conflict fairness, fresh authentication, parent delegation, offline preservation and late ACK. No SQL/provider requests.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
