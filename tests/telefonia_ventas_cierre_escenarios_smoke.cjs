/* Simula el observador de Ventas, sin PBX ni MySQL. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const observer=fs.readFileSync('public/javascript/telefonia_ventas_shell.js','utf8');
const shell=fs.readFileSync('public/javascript/telefonia_shell.js','utf8');
async function scenario(what,metadata){
 const handlers={};let tick,phoneState,opened='',dismissed=[],confirmed=[];
 const elements=[];
 const element=()=>{
  const node={hidden:false,handlers:{},dataset:{},textContent:'',src:'',
    append(){},after(){},addEventListener(n,fn){this.handlers[n]=fn;}};
  elements.push(node);return node;
 };
 const panel={querySelector:()=>element()};
 const crm={src:'',contentWindow:{postMessage(){}}};
 const phone={
   getCallState:()=>phoneState,
   getPhoneStatus:()=>({active:Boolean(phoneState?.active)}),
   isResultFormOpen:()=>false,
   dismissFinishedCall:ref=>{dismissed.push(ref);return true;},
   confirmSalesFinished:ref=>{confirmed.push(ref);return true;}
 };
 const storage=new Map();
 const sessionStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v)};
 const cfg={salesResults:true,userId:25,extension:'151',salesHistoryUrl:'https://crm.test/history',
   salesDetailUrl:'https://crm.test/details'};
 const window={
   IMPE_PHONE_SHELL_CONFIG:cfg,IMPE_TELEPHONY_SHELL:phone,
   addEventListener:()=>{}
 };
 const location={href:'https://crm.test/crm',origin:'https://crm.test'};
 const document={
   getElementById:()=>crm,
   querySelector:()=>panel,
   createElement:element,
   body:{append(){}}
 };
 let fetchCount=0;
 const ctx={
  window,document,sessionStorage,location,URL,Date,JSON,Math,console,
  setInterval:fn=>{tick=fn;},
  fetch:async()=>{fetchCount++;return {ok:true,json:async()=>({ok:true,llamada:metadata})};}
 };
 phoneState={active:true,direction:'outgoing',phase:'in-progress',pbxCallId:'pbxABC1511',
  callToken:'call-token-1234567',context:{type:'TELEPHONY_MANUAL'}};
 vm.runInNewContext(observer,ctx,{filename:'telefonia_ventas_shell.js'});
 assert.equal(typeof tick,'function','observador de Ventas inicializado');
 // Abrir durante llamada activa debe permanecer bloqueado.
 assert.equal(window.IMPE_SALES_ATTENTION.hasPending(),false);
 phoneState={...phoneState,active:false,phase:'finished',status:'completed',
   finalMetadata:{pbx_call_id:'pbxABC1511'}};
 tick();
 await new Promise(resolve=>setImmediate(resolve));
 await new Promise(resolve=>setImmediate(resolve));
 const primary=elements.find(e=>e.dataset.salesOpen==='1');
 const notice=elements.find(e=>e.className==='impe-analyst-call-result');
 assert.ok(primary&&notice,'controles de Ventas disponibles');
 const expectedReady=!!(metadata.finalizada&&metadata.conectada&&!metadata.registro);
 assert.equal(primary.hidden,!expectedReady,'botón incorrecto en escenario '+what);
 assert.equal(notice.hidden,!expectedReady,'aviso incorrecto en escenario '+what);
 assert.equal(window.IMPE_SALES_ATTENTION.hasPending(),expectedReady,'pendientes mal calculados '+what);
 assert.equal(confirmed.length,expectedReady?1:0,'confirmación PBX incorrecta '+what);
 assert.equal(dismissed.length, metadata.registro || (metadata.finalizada&&!metadata.conectada) ? 1:0,
  'no se limpió intento sin conversación: '+what);
 if(expectedReady){
  primary.handlers.click();
  assert.match(crm.src,/pbx_call_id=pbxABC1511/,'no enlaza resultado validado');
 }else{
  assert.equal(crm.src,'','se abrió resultado sin conexión');
 }
 assert.ok(fetchCount>0,'no se consultó el detalle PBX');
}
(async()=>{
 await scenario('cancelada antes de contestar',
  {finalizada:true,conectada:false,estado_pbx:'Cancelada',registro:null});
 await scenario('ocupada',{finalizada:true,conectada:false,estado_pbx:'Ocupada',registro:null});
 await scenario('sin respuesta',{finalizada:true,conectada:false,estado_pbx:'Sin respuesta',registro:null});
 await scenario('pendiente conciliación',{finalizada:false,conectada:false,registro:null});
 await scenario('conversación real',{finalizada:true,conectada:true,registro:null});
 await scenario('resultado guardado',{finalizada:true,conectada:true,registro:{id:5}});
 assert.ok(shell.includes('confirmSalesFinished: function (ref)'),'el shell no expone confirmación');
 assert.ok(shell.includes('commercialReady: !cfg.salesResults'),'el resumen no está protegido');
 assert.ok(shell.includes('recentFinishedOutgoing?.commercialReady'),'el panel no espera confirmación');
 assert.ok(shell.includes('(Boolean(cfg.salesResults) && finished && shownOutgoing)'),
   'botón Cerrar no se suprimió en salientes comerciales');
 console.log('PASS: 6 escenarios de cierre comercial y exclusión del resumen provisional.');
})().catch(error=>{console.error(error);process.exitCode=1;});
