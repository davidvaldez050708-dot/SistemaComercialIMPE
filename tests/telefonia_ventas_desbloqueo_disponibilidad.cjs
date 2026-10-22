'use strict';
// Reproduce el bloqueo mostrado: recepción pide disponibilidad cuando la
// extensión 150 tiene el resultado anterior sin END de Zadarma.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('public/javascript/telefonia_ventas_shell.js','utf8');
const ref='out_104f634c799a7fd246dfe5bad6e572b0fe32415e';
function fixture(opts={}){
 const events={},state={current:opts.state||null},records=opts.records||[];
 const local=new Map();
 if(opts.pending)local.set('impe:sales-pending:8',JSON.stringify(opts.pending));
 const storage={getItem:k=>local.get(k)||null,setItem:(k,v)=>local.set(k,v),removeItem:k=>local.delete(k)};
 const el=()=>({hidden:true,disabled:false,dataset:{},textContent:'',append(){},prepend(){},after(){},setAttribute(){},addEventListener:(name,fn)=>{events[name]=fn}});
 const button=el(),actionBar={querySelector:()=>null,insertBefore(){},append(){}};
 const panel={querySelector:name=>name==='[data-incoming-register-finished]'?button:name==='.persistent-phone-actions'?actionBar:el()};
 const frame={src:'',contentWindow:{postMessage(){},dispatchEvent(){},Event:class{}}};
 const phone={getCallState:()=>state.current,getPhoneStatus:()=>({active:Boolean(state.current?.active)}),isResultFormOpen:()=>false,
  dismissFinishedCall(){},confirmFinishedCall(){}};
 const window={IMPE_PHONE_SHELL_CONFIG:{salesResults:true,userId:8,extension:'150',
  salesDetailUrl:'https://test/detail',salesHistoryUrl:'https://test/history'},
  IMPE_TELEPHONY_SHELL:phone,addEventListener:(type,fn)=>{events['window-'+type]=fn}};
 const document={getElementById:()=>frame,querySelector:()=>panel,createElement:el};
 const timers=[];
 vm.runInNewContext(source,{window,document,localStorage:storage,sessionStorage:storage,URL,
  location:{href:'https://test/shell',origin:'https://test'},setInterval:fn=>timers.push(fn),
  fetch:async url=>({ok:true,json:async()=>new URL(url).searchParams.has('pendientes')
    ?{ok:true,pendientes:records}:{ok:true,llamada:null}})});
 return {window,frame,button,local,state,events,tick:()=>timers.forEach(fn=>fn())};
}
const pending={ref,extension:'150',at:Date.now(),callToken:'sdk-ventas-150'};
const finished={active:false,phase:'incoming-finished',direction:'incoming',pbxCallId:ref,
 callToken:'sdk-ventas-150',context:{type:'INCOMING'}};
(async()=>{
 const current=fixture({state:finished,pending:[pending]});
 assert.equal(current.window.IMPE_SALES_ATTENTION.hasPending(),true,
  'La última atención finalizada necesita resultado, aunque PBX no envíe END');
 assert.equal(await current.window.IMPE_SALES_ATTENTION.openPending(),true,
  'El botón de Disponibilidad navega hacia el resultado real');
 assert.match(current.frame.src,/pbx_call_id=out_104f/);
 assert.match(current.frame.src,/extension=150/);
 assert.match(current.frame.src,/esperando_pbx=1/);
 current.events['window-message']({origin:'https://test',source:current.frame.contentWindow,
  data:{type:'IMPE_SALES_RESULT_SAVED',ref,extension:'150'}});
 assert.equal(current.window.IMPE_SALES_ATTENTION.hasPending(),false,
  'Al guardar debe liberar la siguiente disponibilidad inmediatamente');
 const stale=fixture({state:{active:false,phase:'ready'},pending:[pending]});
 assert.equal(stale.window.IMPE_SALES_ATTENTION.hasPending(),false,
  'Una llamada antigua no debe bloquear transferencias nuevas');
 const newCall=fixture({state:{...finished,pbxCallId:'out_other_current_123456'},
  pending:[pending]});
 assert.equal(newCall.window.IMPE_SALES_ATTENTION.hasPending(),false,
  'El resultado viejo no se adjudica a otra llamada');
 const wrong=fixture({state:finished,records:[{pbx_call_id:ref,extension:'151',finalizada:false,
  conectada:true,solicitud_id:5,origen_extension:'100'}]});
 const opened=await wrong.window.IMPE_SALES_ATTENTION.openPending();
 assert.equal(opened,false,'Nunca abrir el resultado de una extensión diferente');
 assert.equal(wrong.frame.src,'');
 console.log('OK: disponibilidad 100 a 150 abre resultado previo, libera al guardar y aísla sesiones y extensiones');
})().catch(e=>{console.error(e);process.exitCode=1;});
