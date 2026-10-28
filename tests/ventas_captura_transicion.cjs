const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=name=>fs.readFileSync(path.join(__dirname,'../public/javascript/',name),'utf8');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve};};
const element=()=>({value:'',hidden:false,disabled:false,dataset:{},listeners:{},textContent:'',innerHTML:'Guardar',validationMessage:'',setCustomValidity(message){this.validationMessage=message;},setAttribute(k,v){this[k]=v;},removeAttribute(k){delete this[k];},prepend(){},focus(){},dispatchEvent(){},addEventListener(k,fn){this.listeners[k]=fn;},fire(k,event={}){return this.listeners[k]?.({preventDefault(){},...event});}});
const fields=names=>{const list=names.map(name=>Object.assign(element(),{name}));for(const el of list)list[el.name]=el;list.namedItem=name=>list[name]||null;return list;};
const storage=()=>{const map=new Map();return{map,getItem:k=>map.get(k)||null,setItem:(k,v)=>map.set(k,v),removeItem:k=>map.delete(k)};};
function captureHarness(){
 let state={active:true,phase:'in-progress',callToken:'sdk-call-one-token',context:{sdkSessionId:'sdk-call-one-token'},pbxCallId:'pbx-call-one',destination:'5555555555'},resultOpen=false;
 const el=fields(['nombre','telefono','correo','programa','notas']),submit=element(),feedback=element(),close=element(),intervals=[],listeners={},sessionStorage=storage();
 const form=element();form.elements=el;form.reset=()=>{for(const e of el)e.value='';};form.querySelector=()=>submit;
 const dialog=element();dialog.hidden=true;dialog.querySelector=s=>s==='form'?form:s==='[data-sales-prospect-feedback]'?feedback:close;
 let button,readOverride=null,postOverride=null;const posts=[];
 const phone={getCallState:()=>state,getPhoneStatus:()=>({active:state.active}),isResultFormOpen:()=>resultOpen,beginCallResult:()=>{if(state.active)return false;resultOpen=true;return true;},endCallResult:()=>{resultOpen=false;}};
 const crm={contentWindow:{IMPE_TELEPHONY_NOTIFY(){}}};
 const window={IMPE_PHONE_SHELL_CONFIG:{salesResults:true,userId:8,extension:'150',salesProspectUrl:'/captura',routingCsrf:'token'},IMPE_TELEPHONY_SHELL:phone,addEventListener:(k,fn)=>listeners[k]=fn};
 const data={version:1,valores:{nombre:'Persona Prueba',telefono:'5555555555',correo:'prueba@example.test',programa:'Programa',notas:'Nota en servidor'}};
 const response=value=>({ok:true,json:async()=>({ok:true,captura:value})});
 vm.runInNewContext(source('telefonia_ventas_prospecto.js'),{window,document:{querySelector:s=>s==='[data-sales-prospect-dialog]'?dialog:s.includes('.persistent-phone-actions')?{querySelector:()=>null,append:b=>button=b}:null,getElementById:()=>crm,createElement:()=>element()},location:{href:'https://crm.example.test/index.php',origin:'https://crm.example.test'},sessionStorage,URL,URLSearchParams,AbortController,Event,setTimeout,clearTimeout,setInterval:fn=>intervals.push(fn),fetch:async(url,opts)=>{
  if(opts.method==='POST'){posts.push(opts.body);return postOverride?postOverride():response(data);}
  return readOverride?readOverride():response(data);
 }});
 return{window,phone,crm,dialog,form,el,feedback,button,posts,listeners,sessionStorage,data,response,
  state:()=>state,setState:s=>state=s,hang(){state={...state,active:false,phase:'finished'};},tick(){intervals.forEach(fn=>fn());},setRead:fn=>readOverride=fn,setPost:fn=>postOverride=fn,
  saved(ref='pbx-call-one',extension='150'){listeners.message({origin:'https://crm.example.test',source:crm.contentWindow,data:{type:'IMPE_SALES_RESULT_SAVED',ref,extension}});}
 };
}
async function resultHarness(call,parent=null,initialDraft=null,existingStorage=null,controls={}){
 const sessionStorage=existingStorage||storage(),el=fields(['csrf_token','pbx_call_id','extension','prospecto_nombre','correo','resultado','observacion']),nodes={},listeners={};
 for(const key of ['[data-sales-feedback]','[data-sales-submit]','[data-sales-fields]','[data-sales-form-number]','[data-sales-form-state]','[data-sales-capture-summary]'])nodes[key]=element();
 const form=element();form.elements=el;form.reset=()=>{for(const e of el)e.value='';};form.querySelector=s=>nodes[s]||null;form.querySelectorAll=()=>el;form.reportValidity=()=>el.every(e=>!e.validationMessage);
 let shown=false;
 const modal=element();modal.querySelector=()=>form;modal.classList={contains:()=>shown};
 const root=element();root.dataset={detailUrl:'/detalle',saveUrl:'/guardar',autoCall:controls.autostart===false?'':call.pbx_call_id,autoExtension:call.extension};root.querySelector=()=>null;
 const userId=parent?.window.IMPE_PHONE_SHELL_CONFIG?.userId||8;
 const window={IMPE_CURRENT_USER_ID:userId,addEventListener:(k,fn)=>listeners[k]=fn,IMPE_TELEPHONY_NOTIFY(){}};window.parent=parent?.window||window;
 window.postMessage=(data,origin)=>listeners.message?.({origin:origin||'https://crm.example.test',source:window.parent,data});window.dispatchEvent=()=>{};window.Event=class{};
 if(parent){parent.crm.contentWindow=window;parent.window.postMessage=data=>parent.listeners.message({origin:'https://crm.example.test',source:window,data});}
 if(initialDraft)sessionStorage.setItem('impe:sales-result-draft:8:'+call.pbx_call_id,JSON.stringify({at:Date.now(),values:initialDraft}));
 const bootstrap={Modal:{getOrCreateInstance:()=>({show(){shown=true;}}),getInstance:()=>({hide(){shown=false;if(controls.realModalEvents)modal.fire('hidden.bs.modal');}})}};window.bootstrap=bootstrap;
 vm.runInNewContext(source('telefonia_ventas_resultados.js'),{window,document:{querySelector:()=>root,getElementById:()=>modal},location:{href:'https://crm.example.test/index.php',origin:'https://crm.example.test',replace(){}},bootstrap,URL,URLSearchParams,AbortController,CustomEvent:class{},sessionStorage,setInterval:()=>1,clearInterval(){},setTimeout,clearTimeout,FormData:class{*[Symbol.iterator](){for(const e of el)yield[e.name,e.value];}},fetch:controls.fetch|| (async(url,opts)=>({ok:true,json:async()=>opts?.method==='POST'?{ok:true,registro:{}}:{ok:true,llamada:call}}))});
 await flush();return{el,nodes,form,modal,window,sessionStorage,get shown(){return shown;},async reopen(){listeners.message({origin:'https://crm.example.test',source:window.parent,data:{type:'IMPE_SALES_RESULT_OPEN',ref:call.pbx_call_id,extension:call.extension}});await flush();}};
}
const call=(notes='Nota guardada')=>({pbx_call_id:'pbx-call-one',extension:'150',fecha:'2026-10-27 10:23:35',finalizada:true,conectada:true,numero:'5555555555',nombre:'Persona',estado_pbx:'Conectada',registro:null,captura_prospecto:{nombre:'Servidor',notas:notes}});
module.exports={captureHarness,resultHarness,call,element,storage,source,flush,deferred};
if(require.main===module)(async()=>{
 const a=captureHarness();await a.button.fire('click');assert.equal(a.dialog.hidden,false);
 a.el.notas.value='Nota escrita durante la llamada';a.form.fire('input');a.hang();
 a.window.IMPE_SALES_PROSPECT_CAPTURE.closeForResult('pbx-call-one','150');
 assert.equal(a.dialog.hidden,true);assert(a.el.every(e=>e.disabled));assert.equal(a.posts.length,0);
 assert.equal(a.window.IMPE_SALES_PROSPECT_CAPTURE.forResult('otra-llamada','150'),null);
 assert.equal(a.window.IMPE_SALES_PROSPECT_CAPTURE.forResult('pbx-call-one','151'),null);
 const result=await resultHarness(call('Nota antigua del servidor'),a,{observacion:'Texto que ya escribió el asesor'});
 assert.equal(result.el.observacion.value,'Nota escrita durante la llamada\n\nTexto que ya escribió el asesor');
 await result.reopen();assert.equal(result.el.observacion.value.split('Nota escrita durante la llamada').length,2);
 result.el.observacion.value='Texto revisado por el asesor';result.form.fire('input');await result.reopen();assert.equal(result.el.observacion.value,'Texto revisado por el asesor');
 const reloaded=await resultHarness(call('Nota antigua del servidor'),a,null,result.sessionStorage);assert.equal(reloaded.el.observacion.value,'Texto revisado por el asesor');
 await reloaded.form.fire('submit');assert.equal(a.sessionStorage.map.size,0);assert.equal(a.window.IMPE_SALES_PROSPECT_CAPTURE.forResult('pbx-call-one','150'),null);
 const standalone=await resultHarness(call());assert.equal(standalone.el.observacion.value,'Nota guardada');
 const long=await resultHarness(call('n'.repeat(200)),null,{observacion:'x'.repeat(900)});
 assert.equal(long.el.observacion.value.length,900);assert(long.nodes['[data-sales-feedback]'].textContent.includes('1000'));assert(long.nodes['[data-sales-capture-summary]'].textContent.includes('n'.repeat(200)));
 assert.equal(long.form.reportValidity(),false);long.el.observacion.value='Ambos textos integrados por el asesor';long.form.fire('input',{target:long.el.observacion});assert.equal(long.form.reportValidity(),true);await long.reopen();assert.equal(long.el.observacion.value,'Ambos textos integrados por el asesor');
 const saved=await resultHarness({...call('Otra captura'),registro:{observacion:'Observación definitiva',resultado:'CONVERSACION_PERSONA'}});
 assert.equal(saved.el.observacion.value,'Observación definitiva');assert.equal(saved.el.observacion.disabled,true);
 const late=captureHarness(),pending=deferred();late.setRead(()=>pending.promise);
 const opening=late.button.fire('click');late.hang();late.tick();pending.resolve(late.response(late.data));await opening;assert.equal(late.dialog.hidden,true);
 const saving=captureHarness(),post=deferred();await saving.button.fire('click');saving.el.notas.value='Primera versión';saving.form.fire('input');saving.setPost(()=>post.promise);
 const savingPromise=saving.form.fire('submit');saving.el.notas.value='Cambio mientras guarda';saving.form.fire('input');saving.hang();saving.tick();post.resolve(saving.response(saving.data));await savingPromise;
 assert.equal(saving.posts[0].get('notas'),'Primera versión');assert.equal(saving.window.IMPE_SALES_PROSPECT_CAPTURE.forResult('pbx-call-one','150').notas,'Cambio mientras guarda');
 const delayed=captureHarness();delayed.setState({...delayed.state(),pbxCallId:''});await delayed.button.fire('click');delayed.el.notas.value='Borrador con sesión exacta';delayed.form.fire('input');delayed.setState({...delayed.state(),pbxCallId:'pbx-late-reference',active:false,phase:'finished'});delayed.tick();
 assert.equal(delayed.window.IMPE_SALES_PROSPECT_CAPTURE.forResult('pbx-late-reference','150').notas,'Borrador con sesión exacta');
 delayed.saved('pbx-late-reference');delayed.setState({active:true,phase:'in-progress',callToken:'sdk-second-call-token',context:{sdkSessionId:'sdk-second-call-token'},pbxCallId:'pbx-call-two',destination:'5555555555'});delayed.data.valores.notas='';await delayed.button.fire('click');assert.equal(delayed.el.notas.value,'');
 console.log('PASS: cierre al colgar/registrar, respuesta tardía sin reapertura, notas locales y guardadas, fusión sin duplicados ni truncado, edición preservada, resultado definitivo, guardado en vuelo, PBX tardía por sesión exacta y llamadas consecutivas al mismo número sin mezcla.');
})().catch(error=>{console.error(error);process.exitCode=1;});
