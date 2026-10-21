'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync('public/javascript/telefonia_ventas_shell.js','utf8');
async function fixture(){
 let now=Date.now(),state=null,records=[],detail=null,interval,confirmed=[],requests=[];
 class Clock extends Date{static now(){return now;}}
 const el=()=>({hidden:false,dataset:{},textContent:'',append(){},after(){},prepend(){},setAttribute(){},addEventListener(k,f){this[k]=f;}});
 const button=el(),actions={querySelector:()=>el(),insertBefore(){},append(){}},panel={querySelector:s=>s==='[data-incoming-register-finished]'?button:s==='.persistent-phone-actions'?actions:el()};
 const crm={src:'',contentWindow:{dispatchEvent(){},postMessage(){},Event:class{}}},storage={getItem:()=>null,setItem(){},removeItem(){}};
 const phone={getCallState:()=>state,getPhoneStatus:()=>({active:!!state?.active}),isResultFormOpen:()=>false,dismissFinishedCall(){},confirmFinishedCall:(...args)=>{confirmed.push(args);return true;}};
 const window={IMPE_PHONE_SHELL_CONFIG:{salesResults:true,userId:8,extension:'150',salesDetailUrl:'/detail',salesHistoryUrl:'/history'},IMPE_TELEPHONY_SHELL:phone,addEventListener(){}};
 vm.runInNewContext(code,{window,document:{getElementById:()=>crm,querySelector:()=>panel,createElement:el},localStorage:storage,sessionStorage:storage,Date:Clock,setInterval:f=>interval=f,URL,location:{href:'https://test/shell',origin:'https://test'},fetch:async url=>{const u=new URL(url);requests.push(u);return {ok:true,json:async()=>u.searchParams.has('pendientes')?{ok:true,pendientes:records}:{ok:true,llamada:detail}};}});
 const tick=async()=>{now+=11000;interval();for(let n=0;n<4;n++)await new Promise(r=>setImmediate(r));};
 return {button,crm,confirmed,requests,tick,setState:s=>state=s,setRecords:r=>records=r,setDetail:d=>detail=d};
}
const sdk='sdk-original-session-123456',ref='pbx-current-transfer-001';
const state=(active,extra={})=>({active,direction:'incoming',phase:active?'in-progress':'incoming-finished',callToken:sdk+'-external',pbxCallId:'',context:{type:'SDK_INCOMING',sdkSessionId:sdk},...extra});
const facts=(extra={})=>({pbx_call_id:ref,extension:'150',finalizada:true,conectada:true,sdk_tokens:[sdk,sdk+'-external'],...extra});
(async()=>{
 const f=await fixture();f.setState(state(true));await f.tick();f.setState(state(false));f.setRecords([facts()]);f.setDetail(facts());await f.tick();await f.tick();
 assert.equal(f.button.disabled,false,'Pendiente autorizado con SDK exacto debe incorporarse al modal finalizado');
 assert.equal(f.confirmed.at(-1)[0],ref);f.button.click();assert.match(f.crm.src,/pbx_call_id=pbx-current-transfer-001/);
 const g=await fixture();g.setDetail(facts());g.setState(state(true,{context:{type:'SDK_INCOMING',sdkSessionId:sdk,transferReference:sdk},finalMetadata:{pbx_call_id:ref}}));await g.tick();g.setState(state(false,{context:{sdkSessionId:sdk,transferReference:sdk},finalMetadata:{pbx_call_id:ref}}));await g.tick();assert.equal(g.button.disabled,false,'Una referencia SDK no debe ocultar la PBX final verificada');
 const early=await fixture();early.setDetail(facts());early.setState(state(true));await early.tick();await early.tick();early.setDetail(null);early.setState(state(false));await early.tick();assert(!early.button.disabled,'La confirmación previa al fin SIP no se descarta ni exige otro fetch');
 for(const bad of [facts({sdk_tokens:['sdk-older-session-123456']}),facts({finalizada:false}),facts({extension:'151'}),facts({sdk_tokens:[]})]){
  const h=await fixture();h.setState(state(true));await h.tick();h.setState(state(false));h.setRecords([bad]);h.setDetail(bad);await h.tick();await h.tick();assert(h.button.disabled,'No usar último pendiente, extensión ajena o cierre ausente');assert.equal(h.confirmed.length,0);
 }
 const wrong=await fixture();wrong.setState(state(true,{pbxCallId:ref}));await wrong.tick();wrong.setState(state(false,{pbxCallId:ref}));wrong.setDetail(facts({pbx_call_id:'pbx-other-call-001'}));await wrong.tick();assert(wrong.button.disabled);assert.equal(wrong.confirmed.length,0,'Detalle de otra referencia no debe confirmar la actual');
 // Una segunda llamada al mismo teléfono tiene una sesión distinta.
 f.setState(state(true,{callToken:'sdk-next-session-123456',context:{type:'SDK_INCOMING'},destination:'+525551112233'}));await f.tick();f.setState(state(false,{callToken:'sdk-next-session-123456',context:{},destination:'+525551112233'}));await f.tick();assert(f.button.disabled,'Mismo teléfono no hereda la confirmación anterior');
 console.log('OK: recuperación SDK exacta, PBX final prioritaria, referencias ajenas rechazadas y llamadas consecutivas aisladas');
})().catch(e=>{console.error(e);process.exitCode=1;});
