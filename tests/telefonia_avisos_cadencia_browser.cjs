// Prueba de comportamiento de notificaciones sin navegador real ni servicios externos.
// Ejecutar: node tests/telefonia_avisos_cadencia_browser.cjs
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync(__dirname+'/../public/javascript/recordatorios.js','utf8');
let now=1_000_000, timed=[], toasts=[], callbacks={};
const saved=new Map(), badge={textContent:'',classList:{add(){},remove(){}}};
const contenido={setAttribute(){},set innerHTML(v){this.html=v}};
const container={appendChild(n){toasts.push({at:now,node:n});}};
function elem(){return {dataset:{},className:'',attributes:{},
  classList:{add(){},remove(){}},setAttribute(k,v){this.attributes[k]=v},
  addEventListener(){}, remove(){},set textContent(v){this.t=String(v);this.innerHTML=String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;')},
  get textContent(){return this.t},innerHTML:''};}
const root={getAttribute(k){return k==='data-reminder-endpoint'?'/fake-reminders':''},
  querySelector(k){return k==='[data-reminder-badge]'?badge:
      k==='[data-reminder-content]'?contenido:null;}};
const document={hidden:false,addEventListener(k,fn){callbacks[k]=fn},
  querySelector(k){return k==='[data-reminder-root]'?root:
    k==='[data-reminder-toast-container]'?container:null;},
  createElement(){return elem()},body:{appendChild(){}}};
const received=[
  {telefonia_id:21,leida:0,estado:'informativa',titulo:'Transferencia sin respuesta',
   mensaje:'Extensión 101 no contestó',url:'/mis-llamadas?aviso=21'},
  {telefonia_id:22,leida:0,estado:'informativa',titulo:'Llamada perdida',
   mensaje:'Se perdió la llamada',url:'/mis-llamadas?aviso=22'},
  {id:4,seguimiento_id:4,estado:'vencida',accion:'Contactar institución',
   nombre_entidad:'Institución 1',fecha:'2026-10-17 08:00:00'}
];
const win={IMPE_CURRENT_USER_ID:101,bootstrap:true,
  location:{href:'https://crm.example.test/'},
  sessionStorage:{getItem(k){return saved.get(k)||null},setItem(k,v){saved.set(k,v)}},
  setTimeout(fn,delay){timed.push({fn,at:now+delay});return timed.length},
  clearTimeout(){},setInterval(){},
  addEventListener(k,fn){callbacks['window:'+k]=fn}};
const sandbox={window:win,document,bootstrap:{Toast:class {constructor(){}show(){}hide(){}}},
 Date:class extends Date{static now(){return now}},
 fetch:async()=>({ok:true,json:async()=>({ok:true,recordatorios:received,avisos:[],total:3})}),
 URL,console,Event:class{},setTimeout:win.setTimeout,clearTimeout:win.clearTimeout};
vm.runInNewContext(src,sandbox);
assert(callbacks.DOMContentLoaded,'DOMContentLoaded');
callbacks.DOMContentLoaded();
const settle=async()=>{for(let i=0;i<9;i++)await Promise.resolve();};
async function advance(ms) {
 const end=now+ms;
 let count=0;
 while(true) {
   const due=timed.filter(t=>t.at<=end).sort((a,b)=>a.at-b.at)[0];
   if(!due)break;
   timed.splice(timed.indexOf(due),1);
   now=due.at;due.fn();await settle();
   if(++count>60)throw Error('Temporizadores en bucle');
 }
 now=end;
}
(async()=>{
 await settle();
 assert.equal(toasts.length,0,'No crear tres avisos simultáneos al iniciar');
 await advance(8*60*1000);
 assert.equal(toasts.length,1,'Un aviso al cumplirse 8 minutos');
 await advance(8*60*1000);
 assert.equal(toasts.length,2,'Segundo aviso 8 minutos después, nunca simultáneo');
 await advance(8*60*1000);
 assert.equal(toasts.length,3,'El seguimiento también participa de la rotación');
 const kinds=toasts.map(t=>t.node.innerHTML);
 assert(kinds.some(x=>x.includes('Transferencia sin respuesta')),'Aviso de transferencia presente');
 assert(kinds.some(x=>x.includes('Llamada perdida')),'Aviso de llamada perdida presente');
 assert(kinds.some(x=>x.includes('Acción vencida')),'Acción vencida presente');
 assert.equal(saved.get('recordatorios:ultimo-toast-vencido-id:usuario:101')?.startsWith('seguimiento:'),true);
 console.log('OK: un toast cada 8 minutos, rotación telefonía/Seguimiento y claves por usuario');
})().catch(e=>{console.error(e);process.exitCode=1});
