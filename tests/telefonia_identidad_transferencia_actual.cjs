// Observador real con DOM/transporte mínimos: dos llamadas del mismo número.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(process.env.IMPE_IDENTIDAD_RECEIVER_SOURCE||'public/javascript/telefonia_analista_historial_pasivo.js','utf8');
function element(){return{hidden:false,textContent:'',innerHTML:'',children:[],style:{},dataset:{},classList:{add(){},remove(){},toggle(){}},setAttribute(){},removeAttribute(){},addEventListener(){},appendChild(n){this.children.push(n);},replaceChildren(){this.children=[];}};}
async function scenario(firstId,secondId){
 let stored='',now=1000000,pending=null;const events={},els=new Map(),storage=new Map(),requests=[],timers=[];
 const el=s=>{if(!els.has(s))els.set(s,element());return els.get(s);},panel=element(),actions=element();let card;
 panel.querySelector=s=>s==='.persistent-phone-actions'?actions:el(s);
 el('[data-incoming-number]').parentElement={insertAdjacentElement(pos,node){card=node;}};
 const doc={body:{classList:{toggle(){},contains(){return false;}}},head:{appendChild(){}},createElement:element,getElementById(){return null;},querySelector:s=>s==='[data-incoming-panel]'?panel:el(s)};
 const root={document:doc,console,location:{origin:'https://crm.test',href:'https://crm.test/'},
  IMPE_ANALYST_WEBRTC_JOURNAL:{enabled:true,endpoint:'/event',csrf:'csrf'},IMPE_PHONE_SHELL_CONFIG:{userId:8,analystIncomingResults:true},
  localStorage:{getItem(){return stored;}},sessionStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},
  setInterval(){},setTimeout(fn){timers.push(fn);},addEventListener(n,fn){(events[n]||=[]).push(fn);},MutationObserver:class{observe(){}},
  fetch:async(uri,options)=>{if(options?.method==='POST')return{ok:true,json:async()=>({ok:true})};
   const u=new URL(uri),ref=u.searchParams.get('referencia_llamada');requests.push(ref);
   assert.ok(ref,'no consulta únicamente por número');
   let context={referencia_llamada:ref,seguimiento_id:ref===firstId?21:0,institucion:ref===firstId?'AFIPSA':'UPEMOR',estado:ref===firstId?'Chiapas':'Morelos',confirmado_por:'Tania'};
   if(pending&&ref===firstId)return new Promise(resolve=>{pending=()=>resolve({ok:true,json:async()=>({ok:true,contexto:context})});});
   return{ok:true,json:async()=>({ok:true,contexto:context})};
  }};
 class Clock extends Date{static now(){return now;}}
 vm.runInNewContext(source,{window:root,document:doc,Date:Clock,URL,URLSearchParams,console});
 const send=(sdk,ref,active=true,type='SDK_INCOMING')=>{stored=JSON.stringify({userId:8,updatedAt:++now,active,direction:'incoming',destination:'+527777885173',callToken:sdk,pbxCallId:type==='INCOMING'?ref:'',context:{type,...(type==='INCOMING'?{sdkSessionId:sdk}:{transferReference:ref})},phase:active?'incoming-ringing':'incoming-finished'});for(const fn of events.storage||[])fn({key:'impe:telephony:state:8'});};
 const flush=()=>new Promise(r=>setImmediate(r));
 const title=()=>card.children[0]?.textContent||'';
 const firstSdk='sdk-first-1234567890',secondSdk='sdk-second-1234567890';
 send(firstSdk,firstId);await flush();assert.equal(title(),'AFIPSA · Chiapas');
 send(firstSdk,firstId,false);send(secondSdk,'');await flush();
 assert.equal(card.hidden,true,'llamada nueva del mismo número limpia la tarjeta anterior sin esperar a PBX');
 assert.equal(storage.has('impe:crm:transfer-context:8:'+secondSdk),false,'no hereda seguimiento por teléfono');
 send(secondSdk,secondId);await flush();assert.equal(title(),'UPEMOR · Morelos');
 const cache=JSON.parse(storage.get('impe:crm:transfer-context:8:'+secondSdk));assert.equal(cache.seguimiento_id,0);assert.equal(cache.referencia_llamada,secondId);
 send(secondSdk,secondId,true,'INCOMING');await flush();assert.equal(title(),'UPEMOR · Morelos','promoción SDK → PBX conserva la identidad actual');
 assert.equal(JSON.parse(storage.get('impe:crm:transfer-context:8:'+secondId)).seguimiento_id,0);
 // Respuesta AFIPSA en vuelo: llega después de iniciar la segunda llamada.
 send(secondSdk,secondId,false);pending=true;send(firstSdk,firstId);await flush();const resolveOld=pending;assert.equal(typeof resolveOld,'function');
 send(firstSdk,firstId,false);pending=null;send(secondSdk,secondId);await flush();resolveOld();await flush();
 assert.equal(title(),'UPEMOR · Morelos','respuesta tardía de otra transferencia no reemplaza la actual');
 assert.equal(JSON.parse(storage.get('impe:crm:transfer-context:8:'+secondSdk)).seguimiento_id,0);
 assert.ok(requests.includes(firstId)&&requests.includes(secondId));
}
(async()=>{await scenario('pbx-first-0001','pbx-second-0002');console.log('Identidad: mismo número, AFIPSA → No corresponde/UPEMOR, SDK → PBX y respuesta tardía: OK');})().catch(e=>{console.error(e);process.exitCode=1;});
