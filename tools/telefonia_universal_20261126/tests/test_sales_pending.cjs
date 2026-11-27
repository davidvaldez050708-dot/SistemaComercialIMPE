const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_ventas_shell.js','utf8');
const turn=()=>new Promise(setImmediate);
const storage=()=>{const m=new Map();return {getItem:k=>m.get(k)??null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k)};};
function boot(local=storage(),legacy=storage()){
 const messages=[],errors=[],listeners=new Map();let state={active:false,phase:'ready'};
 function node(){return {hidden:false,dataset:{},classList:{add(){},contains(){return false;},toggle(){}},append(){},appendChild(){},after(){},prepend(){},insertBefore(){},
  setAttribute(){},removeAttribute(){},addEventListener(){},querySelector(){return node();}};}
 const panel=node(),frame={src:'',contentWindow:{postMessage:m=>messages.push(m),dispatchEvent(){},Event:class {}}};
 const window={IMPE_PHONE_SHELL_CONFIG:{userId:41,extension:'101',salesResults:true,salesDetailUrl:'/detail',salesHistoryUrl:'/history'},
  IMPE_TELEPHONY_SHELL:{getCallState:()=>state,getPhoneStatus:()=>({active:state.active}),isResultFormOpen:()=>false,dismissFinishedCall(){}},
  IMPE_PHONE_HTTP:{request:async()=>({ok:true,status:200,headers:{get:()=>null},json:async()=>({ok:true,pendientes:[]})})},
  IMPE_TELEPHONY_NOTIFY:(message)=>errors.push(message),addEventListener:(name,fn)=>listeners.set(name,fn)};
 const context={window,document:{getElementById:()=>frame,querySelector:()=>panel,createElement:()=>node(),head:node(),body:node()},
  URL,Date,Set,Map,AbortController,localStorage:local,sessionStorage:legacy,location:{origin:'http://fixture.test',href:'http://fixture.test/'},
  setInterval(){},setTimeout,clearTimeout};
 vm.runInNewContext(source,context);
 return {local,errors,window,state:next=>{state=next;listeners.get('impe:phone-state-change')();}};
}
(async()=>{
 const key='impe:sales-pending:41',local=storage(),old=Date.now()-365*86400000;
 const rows=Array.from({length:1000},(_,i)=>({ref:'fixture-sales-'+String(i).padStart(4,'0'),extension:'101',at:old+i,callToken:'sdk-sales-'+i}));
 local.setItem(key,JSON.stringify([...rows,rows[0],{ref:'fixture-other-assignment',extension:'102',at:old},null,{ref:'sdk-not-pbx',extension:'101'}]));
 const f=boot(local);await turn();let stored=JSON.parse(local.getItem(key));
 assert.equal(stored.length,1000);assert.equal(stored[0].ref,rows[0].ref);assert.equal(stored[999].ref,rows[999].ref);
 assert.equal(f.window.IMPE_SALES_ATTENTION.hasPending(),false,'Old pending inventory alone does not block a new call');
 f.state({active:true,phase:'in-progress',direction:'outgoing',callToken:'sdk-next-sales',pbxCallId:'fixture-sales-next'});
 f.state({active:false,phase:'finished',direction:'outgoing',callToken:'sdk-next-sales',pbxCallId:'fixture-sales-next'});
 stored=JSON.parse(local.getItem(key));assert.equal(stored.length,1001);assert.equal(stored[0].ref,rows[0].ref);
 const afterReload=boot(local);await turn();assert.equal(JSON.parse(local.getItem(key)).length,1001);assert.equal(afterReload.errors.length,0);
 const legacy=storage();legacy.setItem(key,JSON.stringify(rows));const migrated=boot(storage(),legacy);await turn();
 assert.equal(JSON.parse(migrated.local.getItem(key)).length,1000);assert.equal(legacy.getItem(key),null);
 console.log('PASS 10 sales pending assertions: 1,000 old notices retained, exact reference deduplication, assignment scope, consecutive call/reload, legacy storage migration. Synthetic notices, not full-call acceptance.');
})().catch(error=>{console.error(error);process.exitCode=1;});
