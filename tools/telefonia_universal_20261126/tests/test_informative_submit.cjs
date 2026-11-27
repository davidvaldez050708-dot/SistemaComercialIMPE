'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/seguimiento_interacciones.js','utf8');
const from=source.indexOf('        const registrarInteraccionInformativa = async function (event) {'),to=source.indexOf("        selectorResultado.addEventListener('change'",from);assert(from>=0&&to>from);
function fixture(origin='ZADARMA'){
 let resolve,busy=false;const flight=new Promise(r=>resolve=r),sends=[],rendered=[],errors=[],hidden=[];
 const values={seguimiento_id:'7',llamada_pbx_id:'out_01234567890123456789012345678901',origen_llamada:origin,resultado:'BUZON_VOZ'};
 const button={disabled:false,textContent:'Guardar interacción'};
 const form={checkValidity:()=>true,reportValidity(){},classList:{add:n=>hidden.push(n)},reset(){values.seguimiento_id='';values.llamada_pbx_id='';},querySelector:s=>s.startsWith('button')?button:{get value(){return values[s.match(/name="([^"]+)/)[1]];}}};
 class Data{constructor(){this.values={...values};}get(k){return this.values[k];}set(k,v){this.values[k]=v;}[Symbol.iterator](){return Object.entries(this.values)[Symbol.iterator]();}}
 const box={window:{IMPE_CURRENT_USER_ID:42,IMPE_CALL_DRAFTS:{actualizarFicha(){}},IMPE_CALL_RESULTS:{busy:()=>busy,submit:async options=>{busy=true;sends.push(options);const result=await flight;busy=false;return result;}}},
  formulario:form,selectorResultado:{value:'BUZON_VOZ'},FormData:Data,validarRequisitosInteraccion:()=>true,urlInteraccionInformativa:'/native-informative',
  agregarActividadInformativa:v=>rendered.push(v),aplicarModoRuta(){},aplicarResultadoInteraccion(){},mostrarToastLocal:m=>errors.push(m),
  document:{dispatchEvent(){}},CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},
  fetch:async()=>{sends.push('legacy');return {ok:true,json:async()=>({ok:true,interaccion:{id:11}})};}};
 vm.createContext(box);vm.runInContext(source.slice(from,to)+'\ninvoke=registrarInteraccionInformativa;',box);
 return {form,values,button,sends,rendered,errors,hidden,resolve,submit:()=>box.invoke({preventDefault(){},stopImmediatePropagation(){}})};
}
(async()=>{
 let checks=0;const f=fixture(),pending=f.submit();assert.equal(f.sends.length,1);assert.equal(f.sends[0].context.adaptador,'INFORMATIVA');checks+=2;
 await f.submit();assert.equal(f.sends.length,1);checks++;
 f.resolve({ok:true,payload:{ok:true,interaccion:{id:19}}});await pending;
 assert.equal(f.rendered.length,1);assert.equal(f.button.disabled,false,'Own reset must not leave the Save button disabled');assert.equal(f.values.seguimiento_id,'');assert.equal(f.errors.length,0);checks+=4;
 const stale=fixture(),old=stale.submit();stale.values.seguimiento_id='8';stale.button.textContent='Nuevo formulario';
 stale.resolve({ok:true,payload:{ok:true,interaccion:{id:19}}});await old;
 assert.equal(stale.rendered.length,0);assert.equal(stale.hidden.length,0);assert.equal(stale.button.textContent,'Nuevo formulario');checks+=3;
 const legacy=fixture('MANUAL');await legacy.submit();assert.deepEqual(legacy.sends,['legacy']);assert.equal(legacy.rendered.length,1);checks+=2;
 console.log(`PASS ${checks} actual informative adapter checks: shared approval, double-click isolation, original response, controls restored before reset, stale form and other channels preserved. Mock HTTP.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
