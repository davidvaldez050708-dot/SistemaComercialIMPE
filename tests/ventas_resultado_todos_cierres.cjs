const assert=require('node:assert/strict'),vm=require('node:vm');
const {resultHarness,element,storage,source,flush,deferred}=require('./ventas_captura_transicion.cjs');
function observerHarness(){
 let state=null,owner=null;const listeners={},intervals=[],local=storage(),session=storage(),dismissed=[];
 const add=(type,fn)=>{const old=listeners[type];listeners[type]=old?e=>{old(e);fn(e);}:fn;};
 const button=element(),close=element(),origin=element();origin.after=()=>{};
 const actions={querySelector:()=>close,insertBefore(){},append(){}};
 const classes=new Set();const panel={classList:{add:c=>classes.add(c),contains:c=>classes.has(c),toggle(c,on){if(on)classes.add(c);else classes.delete(c);}},querySelector:s=>s==='[data-incoming-register-finished]'?button:s==='.persistent-phone-origin'?origin:actions};
 const crm={src:'https://crm.example.test/index.php?controller=home',contentWindow:{postMessage(){},dispatchEvent(){},Event:class{}}};
 const phone={getCallState:()=>state,getPhoneStatus:()=>({active:Boolean(state?.active)}),isResultFormOpen:()=>Boolean(owner),
  beginCallResult(token){if(state?.active)return false;owner=token;return true;},endCallResult(token){if(owner===token)owner=null;},
  dismissFinishedCall(ref,token){dismissed.push({ref,token});if(!state?.active&&(state?.pbxCallId===ref||state?.callToken===token)){state={active:false,phase:'ready',callToken:'',context:{}};owner=null;}},
  confirmFinishedCall(){},bindTransferCandidate(){}
 };
 const window={IMPE_PHONE_SHELL_CONFIG:{userId:15,salesResults:true,extension:'151',salesHistoryUrl:'https://crm.example.test/historial',salesDetailUrl:'https://crm.example.test/detalle'},IMPE_TELEPHONY_SHELL:phone,addEventListener:add};
 const server=new Map(),json=data=>({ok:true,json:async()=>data});
 vm.runInNewContext(source('telefonia_ventas_shell.js'),{window,document:{getElementById:()=>crm,querySelector:s=>s==='[data-incoming-panel]'?panel:null,createElement:()=>{const e=element();e.append=()=>{};return e;},head:{appendChild(){}},body:{appendChild(){}}},localStorage:local,sessionStorage:session,URL,AbortController,setTimeout,clearTimeout,setInterval:fn=>intervals.push(fn),location:{href:'https://crm.example.test/shell',origin:'https://crm.example.test'},fetch:async u=>{const url=new URL(u);if(url.searchParams.get('pendientes'))return json({ok:true,pendientes:[]});const row=server.get(url.searchParams.get('pbx_call_id'));return row?json({ok:true,llamada:row}):{ok:false,status:409,json:async()=>({ok:false})};}});
 return{window,crm,phone,listeners,button,server,dismissed,classes,
  setState(next){state=next;listeners['impe:phone-state-change']?.({type:'impe:phone-state-change'});},
  replay(){listeners['impe:phone-state-change']?.({});},get owner(){return owner;}
 };
}
const row=(status,ref='out-call-151')=>({id:1,usuario_id:15,pbx_call_id:ref,extension:'151',direccion:'Saliente',origen_extension:'',fecha:'2026-10-28 09:00:00',numero:'5555555555',nombre:'Prueba',finalizada:true,conectada:status==='answered',estado_pbx:status,registro:null,segundos:status==='answered'?20:null,captura_prospecto:{notas:''}});
const live=(ref='out-call-151',token='sdk-session-151-one')=>({active:true,direction:'outgoing',phase:'in-progress',callToken:token,pbxCallId:ref,context:{type:'TELEPHONY_MANUAL'},destination:'5555555555'});
(async()=>{
 for(const status of ['answered','busy','cancel','no answer','failed']){
  const parent=observerHarness(),call=row(status),pending=deferred();parent.server.set(call.pbx_call_id,call);
  const child=await resultHarness(call,parent,null,null,{autostart:false,realModalEvents:true,fetch:async(u,opts)=>opts?.method==='POST'?{ok:true,json:async()=>({ok:true,registro:{}})}:pending.promise});
  const state=live();parent.setState(state);assert.equal(child.shown,false,'No abrir durante la llamada');
  parent.setState({...state,active:false,phase:'finished'});
  assert.equal(child.shown,true,'Debe mostrarse sin resolver ninguna petición: '+status);
  assert.equal(child.nodes['[data-sales-submit]'].disabled,true);assert.equal(parent.crm.src,'https://crm.example.test/index.php?controller=home');
  child.el.observacion.value='Captura durante la identificación';child.form.fire('input');
  pending.resolve({ok:true,json:async()=>({ok:true,llamada:call})});await flush();
  assert.equal(child.el.observacion.value,'Captura durante la identificación');assert.equal(child.nodes['[data-sales-submit]'].disabled,false,status);
  child.el.resultado.value=status==='answered'?'CONVERSACION_PERSONA':status==='cancel'?'CANCELADA_POR_ASESOR':status==='busy'?'OCUPADO':'BUZON_VOZ';child.form.fire('change');
  if(status==='cancel')assert.equal(child.el.observacion.required,true);
  await child.form.fire('submit');await flush();
  assert.equal(child.shown,false);assert(parent.dismissed.some(x=>x.ref===call.pbx_call_id));
  parent.setState({...state,active:false,phase:'finished'});await flush();assert.equal(child.shown,false,'No solicitar de nuevo después de guardar');
 }
 // Sin PBX todavía: captura inmediata, guardado bloqueado y vínculo tardío de la misma sesión.
 const parent=observerHarness(),call=row('cancel','out-late-151');parent.server.set(call.pbx_call_id,call);
 const child=await resultHarness(call,parent,null,null,{autostart:false,realModalEvents:true});
 const state=live('','sdk-session-late-151');parent.setState(state);parent.setState({...state,active:false,phase:'finished'});
 assert.equal(child.shown,true);assert.equal(child.el.pbx_call_id.value,'');assert.equal(child.nodes['[data-sales-submit]'].disabled,true);
 child.el.observacion.value='No perder este texto';child.form.fire('input');
 parent.setState({...state,active:false,phase:'finished',pbxCallId:call.pbx_call_id});await flush();
 assert.equal(child.el.pbx_call_id.value,call.pbx_call_id);assert.equal(child.el.observacion.value,'No perder este texto');assert.equal(child.nodes['[data-sales-submit]'].disabled,false);
 child.modal.fire('hidden.bs.modal');parent.replay();await flush();assert.equal(parent.owner,null,'Cerrar libera sólo el propietario del formulario');
 // Una sesión final recuperada al cargar no debe abrir resultados históricos automáticamente.
 const recovered=observerHarness();recovered.setState({...live('out-old-151'),active:false,phase:'finished'});
 const old=await resultHarness(row('answered','out-old-151'),recovered,null,null,{autostart:false});await flush();assert.equal(old.shown,false);
 // Un guardado anterior que termina durante otra atención sólo retira su propia referencia.
 const racing=observerHarness(),first=row('answered','out-first-151'),second=row('cancel','out-second-151'),post=deferred();
 racing.server.set(first.pbx_call_id,first);racing.server.set(second.pbx_call_id,second);
 const racingChild=await resultHarness(first,racing,null,null,{autostart:false,realModalEvents:true,fetch:async(u,opts)=>{
  if(opts?.method==='POST')return post.promise;
  const ref=new URL(u).searchParams.get('pbx_call_id');return {ok:true,json:async()=>({ok:true,llamada:ref===first.pbx_call_id?first:second})};
 }});
 let active=live(first.pbx_call_id,'sdk-first-151');racing.setState(active);racing.setState({...active,active:false,phase:'finished'});await flush();
 racingChild.el.resultado.value='CONVERSACION_PERSONA';const saving=racingChild.form.fire('submit');
 racingChild.window.postMessage({type:'IMPE_CALL_RESULT_INTERRUPT'},'https://crm.example.test');
 active=live(second.pbx_call_id,'sdk-second-151');racing.setState(active);racing.setState({...active,active:false,phase:'finished'});await flush();
 racingChild.el.observacion.value='Notas de la segunda llamada';racingChild.form.fire('input');
 post.resolve({ok:true,json:async()=>({ok:true,registro:{}})});await saving;await flush();
 assert.equal(racingChild.shown,true);assert.equal(racingChild.el.pbx_call_id.value,second.pbx_call_id);
 assert.equal(racingChild.el.observacion.value,'Notas de la segunda llamada');
 assert(racing.dismissed.some(x=>x.ref===first.pbx_call_id));assert(!racing.dismissed.some(x=>x.ref===second.pbx_call_id));
 // Incluso si la red ignora abort(), la confirmación vieja no reemplaza la atención nueva.
 const slow=observerHarness(),get=deferred();slow.server.set(first.pbx_call_id,first);slow.server.set(second.pbx_call_id,second);
 const slowChild=await resultHarness(first,slow,null,null,{autostart:false,realModalEvents:true,fetch:async u=>new URL(u).searchParams.get('pbx_call_id')===first.pbx_call_id?get.promise:{ok:true,json:async()=>({ok:true,llamada:second})}});
 active=live(first.pbx_call_id,'sdk-slow-first');slow.setState(active);slow.setState({...active,active:false,phase:'finished'});
 slowChild.window.postMessage({type:'IMPE_CALL_RESULT_INTERRUPT'},'https://crm.example.test');
 active=live(second.pbx_call_id,'sdk-slow-second');slow.setState(active);slow.setState({...active,active:false,phase:'finished'});await flush();
 slowChild.el.observacion.value='Captura de la atención nueva';slowChild.form.fire('input');
 get.resolve({ok:true,json:async()=>({ok:true,llamada:first})});await flush();
 assert.equal(slowChild.el.pbx_call_id.value,second.pbx_call_id);assert.equal(slowChild.el.observacion.value,'Captura de la atención nueva');assert.equal(slowChild.shown,true);
 console.log('PASS: formulario inmediato antes de red para contestada/ocupada/cancelada/sin respuesta/fallida, sin navegar ni abrir durante audio, captura preservada, PBX tardía exacta, cierre y retiro tras guardar sin reapertura histórica.');
})().catch(error=>{console.error(error);process.exitCode=1;});
