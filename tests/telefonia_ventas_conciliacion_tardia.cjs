'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 let now=Date.now(),confirmed=false,state=null,offer=null,finish=[],requests=[],interval,delay=null;
 class Clock extends Date {static now(){return now;}}
 const el=()=>({hidden:false,textContent:'',dataset:{},append(){},after(){},prepend(){},setAttribute(){},addEventListener(k,v){this[k]=v;}});
 const button=el(),actions={querySelector:()=>el(),insertBefore(){},append(){}},panel={querySelector:s=>s==='[data-incoming-register-finished]'?button:s==='.persistent-phone-actions'?actions:el()};
 const crm={src:'',contentWindow:{postMessage(){},dispatchEvent(){},Event:class{}}};
 const phone={getCallState:()=>state,getPhoneStatus:()=>({active:!!state?.active}),isResultFormOpen:()=>false,bindTransferCandidate(){},confirmFinishedCall:(ref,facts,token)=>finish.push({ref,token}),dismissFinishedCall(){}};
 const window={IMPE_PHONE_SHELL_CONFIG:{userId:8,extension:'150',salesResults:true,salesDetailUrl:'/detail',salesHistoryUrl:'/history'},IMPE_TELEPHONY_SHELL:phone,IMPE_AVAILABILITY:{acceptedTransfer:()=>offer},addEventListener(){}};
 const document={getElementById:()=>crm,querySelector:()=>panel,createElement:el},storage={getItem:()=>null,setItem(){},removeItem(){}};
 vm.runInNewContext(fs.readFileSync('public/javascript/telefonia_ventas_shell.js','utf8'),{window,document,localStorage:storage,sessionStorage:storage,Date:Clock,URL,location:{href:'https://test/shell',origin:'https://test'},setInterval:f=>interval=f,fetch:async u=>{
  u=new URL(u);if(u.searchParams.has('pendientes'))return {ok:true,json:async()=>({ok:true,pendientes:[]})};
  requests.push(u);const ref=u.searchParams.get('pbx_call_id')||'pbx-'+u.searchParams.get('solicitud_id');
  if(delay)await delay;
  return {ok:true,json:async()=>({ok:true,llamada:confirmed?{pbx_call_id:ref,extension:'150',nombre:'actual',finalizada:true,conectada:true,sdk_tokens:[u.searchParams.get('sdk')].filter(Boolean)}:null})};
 }});
 const tick=async()=>{now+=11000;interval();await new Promise(r=>setImmediate(r));};
 const start=id=>{offer={id,nombre:'Persona '+id,referencia:'pbx-'+id,numero:'+525551112233',at:now,origen_extension:'100'};state={active:true,phase:'in-progress',direction:'incoming',destination:offer.numero,requestedAt:now/1000,callToken:'sdk-session-current-'+id,pbxCallId:'sdk-session-current-'+id,context:{type:'SDK_INCOMING'}};};
 start(91);await tick();assert(requests.some(u=>u.searchParams.get('solicitud_id')==='91'),'SDK local se consulta por solicitud exacta, nunca como PBX');
 state={...state,active:false,phase:'incoming-finished'};await tick();assert(button.disabled);assert.equal(finish.length,0);
 confirmed=true;await tick();assert(!button.disabled,'PBX tardía habilita resultado sin F5');assert.equal(finish.at(-1).ref,'pbx-91');
 button.click?.();button.click?.(); // La acción real se comprueba en Edge.
 start(92);confirmed=false;let release;delay=new Promise(r=>release=r);await tick();
 start(93);await tick();confirmed=true;release();delay=null;await new Promise(r=>setImmediate(r));
 assert.notEqual(finish.at(-1).ref,'pbx-92','Respuesta antigua no confirma llamada nueva');
 await tick();state={...state,active:false,phase:'incoming-finished'};await tick();assert(!button.disabled);assert.equal(finish.at(-1).ref,'pbx-93');
 assert(requests.every(u=>!/^sdk-/.test(u.searchParams.get('pbx_call_id')||'')));
 console.log('OK: SDK reservado, cierre PBX tardío/previo, reintentos sin F5 y respuesta antigua aislada');
})().catch(e=>{console.error(e);process.exitCode=1;});
