const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const read=n=>fs.readFileSync('public/javascript/'+n,'utf8');
function env(){
 let now=100000,id=0,calls=0,response={status:503,wait:120,body:{ok:false,reintentar_en:120}};const tasks=new Map();
 const ctx={URL,URLSearchParams,AbortController,Error,DOMException,Date:{now:()=>now},Math:Object.assign(Object.create(Math),{random:()=>0}),
  location:{href:'http://sondeo.test/'},window:{addEventListener:()=>{}},setTimeout:(fn,ms)=>{tasks.set(++id,{fn,ms});return id;},clearTimeout:n=>tasks.delete(n),
  fetch:async()=>{calls++;return {status:response.status,ok:response.status===200,headers:new Headers({'Retry-After':String(response.wait)}),json:async()=>response.body};}};
 vm.createContext(ctx);return {ctx,tasks,calls:()=>calls,time:n=>now=n,response:r=>response=r};
}
const availability=read('telefonia_disponibilidad.js'),shell=read('telefonia_ventas_shell.js'),results=read('telefonia_ventas_resultados.js');
(async()=>{
 let t=env();vm.runInContext(`const cfg={availabilityUrl:'/availability',routingCsrf:'csrf'};`+
  availability.slice(availability.indexOf(' const request=async'),availability.indexOf(' const action=async'))+`;globalThis.req=request;`,t.ctx);
 const error=await t.ctx.req({accion:'POLL'}).catch(e=>e);assert.equal(error.retryAfterMs,120000);assert.equal(t.calls(),1);assert.equal(t.tasks.size,0);

 t=env();vm.runInContext(`const cfg={availabilityUrl:'/availability',routingCsrf:'csrf'};
 let polling=false,received=null,siguientePoll=0,fallosPoll=0,consultarDevoluciones=0,watched=null;
 const incoming={open:false},phone={getActiveCall:()=>null,getPhoneStatus:()=>({ready:true})},busy=()=>false;
 const $=()=>({textContent:''});`+
 availability.slice(availability.indexOf(' const request=async'),availability.indexOf(' const action=async'))+
 availability.slice(availability.indexOf(' const tick=async'),availability.indexOf(' const respond=async'))+`;globalThis.tick=tick;globalThis.next=()=>siguientePoll;`,t.ctx);
 await t.ctx.tick();assert.equal(t.calls(),1);await t.ctx.tick();assert.equal(t.calls(),1);
 t.time(219999);await t.ctx.tick();assert.equal(t.calls(),1);t.time(220001);await t.ctx.tick();assert.equal(t.calls(),2);
 console.log('PASS: disponibilidad respeta 120 s y evita consultas concurrentes/reintentos anticipados.');

 t=env();vm.runInContext(`let fetching=false,candidate=null,metadata=null,bound=null,revision=0;
 const cfg={salesDetailUrl:'/detail',extension:'150'},retired=new Set(),pending=[];
 const state={active:false,phase:'finished',callToken:'propia'};
 const phone={getCallState:()=>state},identities=()=>['propia'],pbx=r=>r.startsWith('out_'),reference=()=>'',overlaps=()=>true,adopt=()=>false,render=()=>{};`+
 shell.slice(shell.indexOf(' let lookupRetryAt='),shell.indexOf(' let lastLookup='))+`;globalThis.lookup=lookup;`,t.ctx);
 await t.ctx.lookup('out_'+ 'a'.repeat(32),0);assert.equal(t.calls(),1);
 await t.ctx.lookup('out_'+ 'a'.repeat(32),0);assert.equal(t.calls(),1);
 t.time(219999);await t.ctx.lookup('out_'+ 'a'.repeat(32),0);assert.equal(t.calls(),1);
 t.time(220001);await t.ctx.lookup('out_'+ 'a'.repeat(32),0);assert.equal(t.calls(),2);
 console.log('PASS: detalle de llamada conserva identidad y respeta Retry-After antes de repetir.');

 t=env();vm.runInContext(`let timer=null,cycle=1,disposed=false;const feedback={textContent:''};
 const modal={classList:{contains:()=>true}};let opens=0;const open=()=>opens++;`+
 results.slice(results.indexOf(' let detailRetryAt='),results.indexOf(' const draftKey='))+
 results.slice(results.indexOf(' const schedule='),results.indexOf(' const resultRules='))+
 `;globalThis.req=request;globalThis.schedule=schedule;globalThis.feedback=feedback;`,t.ctx);
 await t.ctx.req('/detail').catch(()=>{});assert.equal(t.calls(),1);
 await t.ctx.req('/detail').catch(()=>{});assert.equal(t.calls(),1);
 t.ctx.schedule('out_a','150',8,1);assert.equal(t.tasks.size,0);assert.match(t.ctx.feedback.textContent,/captura se conserva/);
 t.ctx.schedule('out_a','150',1,1);assert.equal([...t.tasks.values()][0].ms,120000);
 console.log('PASS: formulario no repite ante clics durante espera y limita a ocho reintentos sin perder captura.');
})().catch(e=>{console.error(e);process.exitCode=1;});
