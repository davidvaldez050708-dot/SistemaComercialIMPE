'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../app/views/seguimiento_vinculacion/estado.php','utf8');
const from=source.indexOf("    document.querySelector('[data-work-interaction-form]')?.addEventListener('submit', async function (event) {");
const to=source.indexOf("    botonConfirmarDescarte?.addEventListener",from);assert(from>=0&&to>from);
const wait=()=>{let resolve;return {promise:new Promise(r=>resolve=r),resolve};};
function fixture(origin='ZADARMA'){
 let handler,busy=false;const sends=[],rendered=[],errors=[],hidden=[],events=[];
 const values={seguimiento_id:'7',llamada_pbx_id:'out_01234567890123456789012345678901',origen_llamada:origin,resultado:'BUZON_VOZ'};
 const button={disabled:false,textContent:'Guardar interacción'};
 const form={classList:{add:n=>hidden.push(n)},querySelector:selector=>selector.startsWith('button')?button:{get value(){return values[selector.match(/name="([^"]+)/)[1]];}}};
 class Data {constructor(){this.values={...values};}get(k){return this.values[k];}[Symbol.iterator](){return Object.entries(this.values)[Symbol.iterator]();}}
 const link={committed:false,detail:{callToken:'sdk-fixture-old',seguimientoId:7},onCommitted({payload}){link.committed=true;link.id=payload.interaccion_id;}};
 const flight=wait(),box={window:{IMPE_CURRENT_USER_ID:42,IMPE_ZADARMA_LINKS:{submission:()=>link},IMPE_CALL_RESULTS:{busy:()=>busy,submit:async options=>{busy=true;sends.push(options);const result=await flight.promise;if(result.ok)options.onCommitted?.({payload:result.payload});return result;}}},
  document:{dispatchEvent:e=>{events.push(e);values.llamada_pbx_id='';},querySelector:()=>({addEventListener(name,fn){handler=fn;}})},CustomEvent:class{constructor(type,o){this.type=type;this.detail=o.detail;}},FormData:Data,mostrarAlertaTrabajo(){},mostrarErrorTrabajo:e=>errors.push(e),
  urlRegistrarInteraccionTrabajo:'/native-followup',renderizarResumenTrabajo:(...v)=>rendered.push(v),renderizarInteraccionesTrabajo(){},actualizarResumenSeguimiento(){},actualizarFilaSeguimiento(){},mostrarToastSistema(){},
  fetch:async()=>{sends.push('legacy');return {ok:true,json:async()=>({ok:true,seguimiento:{id:7},interacciones:[],resumen:{}})};}};
 vm.runInNewContext(source.slice(from,to),box);
 return {form,values,button,sends,rendered,errors,hidden,flight,link,events,submit:()=>handler({preventDefault(){},currentTarget:form})};
}
(async()=>{
 let checks=0;const f=fixture(),pending=f.submit();
 assert.equal(f.sends.length,1);assert.equal(f.sends[0].context.adaptador,'SEGUIMIENTO');assert.equal(f.sends[0].user,42);checks+=3;
 await f.submit();assert.equal(f.sends.length,1);checks++;
 f.flight.resolve({ok:true,payload:{ok:true,interaccion_id:19,seguimiento:{id:7},interacciones:[],resumen:{}}});await pending;
 assert.equal(f.rendered.length,1);assert.equal(f.button.disabled,false);assert.equal(f.errors.length,0);checks+=3;assert.equal(f.link.id,19);assert.equal(f.events[0].detail.interaccionId,19);assert.equal(f.values.llamada_pbx_id,'');checks+=3;
 const stale=fixture(),old=stale.submit();stale.values.seguimiento_id='8';stale.button.textContent='Nuevo formulario';
 stale.flight.resolve({ok:true,payload:{ok:true,interaccion_id:20,seguimiento:{id:7},interacciones:[],resumen:{}}});await old;
 assert.equal(stale.rendered.length,0);assert.equal(stale.hidden.length,0);assert.equal(stale.button.textContent,'Nuevo formulario');assert.equal(stale.link.id,20);assert.equal(stale.events.length,0);checks+=5;
 const legacy=fixture('MANUAL');await legacy.submit();assert.deepEqual(legacy.sends,['legacy']);assert.equal(legacy.rendered.length,1);checks+=2;
 console.log(`PASS ${checks} actual Seguimiento adapter checks: shared approved submit, double-click isolation, native response, stale institution protection and existing manual channel preserved. Mock HTTP.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
