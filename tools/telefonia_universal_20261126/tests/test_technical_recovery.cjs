'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/seguimiento_llamada_zadarma.js','utf8').replace(/\r\n/g,'\n');
const piece=(a,b)=>{const i=source.indexOf(a),j=source.indexOf(b,i);assert(i>=0&&j>i);return source.slice(i,j);};
const token='sdk-fixture-pending-0001',other='sdk-fixture-other-0002',key='fixture-technical-42';
const job={metadata:{call_token:token,sdk_session_id:token,session_protocol:2,seguimiento_id:7,pbx_call_id:'out_01234567890123456789012345678901',to:'5550000101'},interactionId:13,feedback:{tieneEvidencia:true,verificacionAplicable:true}};
const stored=new Map([[key,JSON.stringify({[token]:job,[other]:{interactionId:14,metadata:{call_token:other,seguimiento_id:8}}})]]);
function fixture(status=200){
 const posts=[],clears=[],events=[],toasts=[];let pending=true;
 const box={queue:JSON.parse(stored.get(key)),queueKey:key,localStorage:{getItem:k=>stored.get(k)??null,setItem:(k,v)=>stored.set(k,v)},
  Date,Math,Number,String,Object,JSON,FormData,URLSearchParams,Set,linkingMetadata:false,pendingMetadata:null,pendingInteractionId:0,awaitingInteractionSave:false,pendingInteractionFeedback:null,lastFinishedToken:'',
  securedTokens:new Set(),usuarioId:42,activeCall:true,newCallToken:'sdk-new-call-active-0003',
  window:{IMPE_PHONE_HTTP:{csrfToken:()=> 'fresh-csrf'},IMPE_CALL_DRAFTS:{remove:async(user,ids)=>{assert(!ids.includes(box.newCallToken));}}},
  vincularUrl:'/native-link',phone:{clearFinished:old=>clears.push(old)},offcanvas:{dataset:{flowSeguimientoId:'8'},querySelector:()=>({querySelector:()=>({value:'fresh-csrf'})})},
  leerVinculoPendiente:()=>null,limpiarVinculoPendiente(){},mostrarToast:m=>toasts.push(m),sleep:async()=>{},
  document:{dispatchEvent:event=>events.push(event.type)},CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},
  fetchTechnical:async(url,options)=>{posts.push({url,session:options.body.get('session_id'),csrf:options.body.get('csrf_token')});return {ok:status===200,status,json:async()=>status===200?({ok:true,interaccion_id:13,hubo_respuesta:true,verificacion_pendiente:pending,verificacion_efectiva:!pending}):({ok:false,mensaje:'Pending synthetic evidence'})};}};
 vm.createContext(box);vm.runInContext(piece('        const writeQueue = (token, job) => {','        const secureResult = (job, announce) => {')+
  piece('        const vincularMetadata =',"        document.addEventListener(\n            'submit',")+'\ninvoke=vincularMetadata;',box);
 return {box,posts,clears,events,toasts,confirm:()=>pending=false};
}
(async()=>{
 let checks=0;const first=fixture();await first.box.invoke(0,first.box.queue[token]);
 let saved=JSON.parse(stored.get(key));assert.equal(saved[token].evidencePending,true);assert.equal(saved[token].interactionId,13);assert.equal(saved[other].interactionId,14);checks+=3;
 assert(saved[token].retryAt>=Date.now()+59000);assert.equal(first.posts.length,1);assert.equal(first.posts[0].session,token);assert.equal(first.posts[0].csrf,'fresh-csrf');checks+=4;
 assert.equal(first.box.activeCall,true);assert.equal(first.box.newCallToken,'sdk-new-call-active-0003');assert.deepEqual(first.clears,[token]);checks+=3;
 assert.equal(first.events.includes('impe:telephony-call-linked'),false);checks++;
 const reload=fixture();await reload.box.invoke(0,reload.box.queue[token]);
 assert.equal(reload.toasts.length,0,'Background pending reply must not repeat result-saved toasts');assert.equal(JSON.parse(stored.get(key))[token].interactionId,13);checks+=2;
 reload.confirm();await reload.box.invoke(0,reload.box.queue[token]);saved=JSON.parse(stored.get(key));
 assert.equal(Object.hasOwn(saved,token),false);assert.equal(saved[other].interactionId,14);assert.deepEqual(reload.events,['impe:telephony-call-linked']);assert.equal(reload.box.activeCall,true);checks+=4;
 stored.set(key,JSON.stringify({[token]:job,[other]:saved[other]}));
 const delayed=fixture(409);await delayed.box.invoke(0,delayed.box.queue[token]);
 assert.equal(delayed.posts.length,1,'Modern journal uses the durable queue, not an immediate request waterfall');assert.equal(delayed.box.linkingMetadata,false);assert.equal(JSON.parse(stored.get(key))[token].interactionId,13);assert.equal(delayed.box.activeCall,true);checks+=4;
 const recovered={interactionId:13,metadata:{call_token:'receipt-13',native_receipt:true,pbx_call_id:job.metadata.pbx_call_id,seguimiento_id:7}};
 stored.set(key,JSON.stringify({'receipt-13':recovered}));const exact=fixture(409);
 await exact.box.invoke(0,recovered);assert.equal(exact.posts.length,1);assert.equal(exact.posts[0].session,null);checks+=2;
 assert.equal(JSON.parse(stored.get(key))['receipt-13'].interactionId,13);assert.equal(exact.box.activeCall,true);checks+=2;
 console.log(`PASS ${checks} actual technical reconciliation checks: native result remains released, pending evidence retained across reload with backoff, exact SDK/CSRF, late completion isolated from active call and unrelated jobs. Mock HTTP; no native result POST replay.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
