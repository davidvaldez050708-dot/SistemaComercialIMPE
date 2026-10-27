'use strict';
/* Prueba aislada del observador de Ventas (sin SIP, Zadarma, BD o DOM real). */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const src=fs.readFileSync('public/javascript/telefonia_ventas_shell.js','utf8');
async function test(kind) {
 class URLMock {
  constructor(value){this.s=value;this.origin='https://crm.test';this.searchParams={set:(k,v)=>{this.s+='&'+k+'='+v;}};}
  get href(){return this.s;}toString(){return this.s;}
 }
 const make=tag=>{
  const cls=new Set();
  return{tag,className:'',dataset:{},textContent:'',hidden:false,src:'',lastChild:null,
   classList:{contains:x=>cls.has(x),toggle:(x,on)=>{if(on)cls.add(x);else cls.delete(x);return on;}},
   setAttribute(){},after(){},append(...args){this.lastChild=args[args.length-1];},
   appendChild(x){this.lastChild=x;},prepend(){},addEventListener(name,fn){this['on'+name]=fn;},
   insertBefore(){},dispatchEvent(){},querySelector(){return null;}};
 };
 const panel=make('panel'),origin=make('origin'),actions=make('actions'),
  button=make('button'),close=make('close'),crm=make('iframe'),body=make('body'),head=make('head');
 panel.querySelector=x=>x==='.persistent-phone-origin'?origin:
  x==='.persistent-phone-actions'?actions:x==='[data-incoming-register-finished]'?button:null;
 actions.querySelector=x=>x==='[data-incoming-close-finished]'?close:null;
 const win={IMPE_PHONE_SHELL_CONFIG:{salesResults:true,userId:23,extension:'151',
  salesDetailUrl:'https://crm.test/detail',salesHistoryUrl:'https://crm.test/hist'},
  addEventListener(){},IMPE_AVAILABILITY:null};
 const storage=new Map();
 const localStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v)};
 const sessionStorage={getItem(){return null;},removeItem(){},setItem(){}};
 let state={active:true,direction:'outgoing',phase:'in-progress',
  callToken:'callabcdef1234567',pbxCallId:'PBXABC1234567',context:{}};
 const confirms=[],dismisses=[];
 let interval;
 let now=Date.now();
 class FakeDate extends Date{static now(){return now;}}
 win.IMPE_TELEPHONY_SHELL={getCallState:()=>state,
  getPhoneStatus:()=>({active:state.active}),isResultFormOpen:()=>false,
  confirmFinishedCall:(...args)=>confirms.push(args),
  dismissFinishedCall:(...args)=>dismisses.push(args)};
 const doc={createElement:make,getElementById:()=>crm,querySelector:()=>panel,head,body};
 const facts={pbx_call_id:'PBXABC1234567',extension:'151',finalizada:kind!=='unconfirmed',
  conectada:kind==='connected',registro:kind==='saved'?{id:28}:null,sdk_tokens:['callabcdef1234567']};
 const fetch=async url=>({ok:true,json:async()=>String(url).includes('pendientes=')
  ?{ok:true,pendientes:[]}:{ok:true,llamada:facts}});
 const sandbox={window:win,document:doc,sessionStorage,localStorage,URL:URLMock,
  location:{href:'https://crm.test/shell',origin:'https://crm.test'},Date:FakeDate,Math,
  Set,Array,JSON,String,Boolean,Number,
  MutationObserver:class {observe(){}},AbortController:class {
   constructor(){this.signal={};}abort(){}
  },fetch,console,setInterval:fn=>{interval=fn;return 1;},
  setTimeout:()=>1,clearTimeout:()=>{}};
 vm.runInNewContext(src,sandbox,{filename:'telefonia_ventas_shell.js'});
 state={...state,active:false,phase:'finished'};
 interval();
 for(let i=0;i<20;i++)await Promise.resolve();
 interval();
 for(let i=0;i<20;i++)await Promise.resolve();
 const gate=panel.classList.contains('impe-sales-pbx-pending-v1');
 const noticeHidden=body.lastChild.hidden;
 if(kind==='connected'){
  assert.equal(gate,false,'La conversación confirmada debe abrir panel bueno');
  assert.equal(noticeHidden,true,'No mostrar aviso después de confirmar');
  assert.equal(button.hidden,false,'Registrar resultado visible en conversación confirmada');
  assert.equal(confirms.length,1,'Se necesita confirmFinishedCall con identidad exacta');
  assert.equal(dismisses.length,0,'No descartar conversación');
 }else if(kind==='cancel'||kind==='saved'){
  assert.equal(gate,true,'No mostrar panel provisional tras cancelación o resultado ya guardado');
  assert.equal(noticeHidden,true,'No insistir después de descartar');
  assert.equal(button.hidden,true,'No pedir resultado');
  assert.equal(dismisses.length,1,'Debe cerrarse el resumen');
 }else{
  assert.equal(gate,true,'Mientras la PBX concilia, ocultar modal grande');
  assert.equal(noticeHidden,false,'Solo mostrar aviso discreto');
  assert.equal(button.hidden,true,'No permitir formulario provisional');
  assert.equal(confirms.length,0,'Sin confirmación no abrir panel bueno');
  now+=9000;
  interval();
  assert.equal(body.lastChild.hidden,true,'El aviso debe desaparecer tras la transición breve');
  assert.equal(panel.classList.contains('impe-sales-pbx-pending-v1'),true,
   'Nunca revelar el modal provisional aunque desaparezca el aviso');
 }
}
(async()=>{
 await test('connected');
 await test('cancel');
 await test('unconfirmed');
 await test('saved');
 assert.ok(src.includes("state.direction==='outgoing'"),'No debe modificar entrantes');
 assert.ok(src.includes('IMPE_SALES_PROSPECT_CAPTURE'),'Preservar captura comercial');
 assert.ok(src.includes('bindTransferCandidate'),'Preservar transferencia');
 console.log('PASS: 4 escenarios, sin SIP/WebRTC ni consultas reales.');
})().catch(error=>{console.error(error);process.exitCode=1;});
