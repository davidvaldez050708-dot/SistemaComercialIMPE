const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_atencion.js','utf8');
const storage=()=>{const m=new Map();return {get length(){return m.size;},key:i=>[...m.keys()][i]??null,getItem:k=>m.get(k)??null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k)};};
class Form {
 constructor(){this.dataset={};this.checkbox={name:'verificacion_telefono_confirmado',type:'checkbox',value:'1',checked:false,disabled:false};this.button={disabled:false};this.fields=[this.checkbox,this.button];this.elements={namedItem:name=>name===this.checkbox.name?this.checkbox:null};}
 querySelectorAll(selector){return selector.startsWith('input[type=')?[this.checkbox]:this.fields;}
 querySelector(){return null;}addEventListener(){}removeEventListener(){}
}
class Data {constructor(form){this.entries=form.checkbox.checked?[[form.checkbox.name,form.checkbox.value]]:[];}[Symbol.iterator](){return this.entries[Symbol.iterator]();}}
function fixture(){
 const listeners=new Map(),notices=[];let request=async()=>({ok:true,status:200,json:async()=>({ok:true})});
 const window={crypto:crypto.webcrypto,IMPE_CURRENT_USER_ID:42,IMPE_PHONE_HTTP:{csrfToken:()=> 'fresh-csrf',request:(...args)=>request(...args)}};
 const context={window,document:{baseURI:'http://fixture.test/',readyState:'loading',addEventListener:(name,fn)=>listeners.set(name,fn)},URL,URLSearchParams,WeakMap,Set,Map,Uint8Array,Date,FormData:Data,HTMLFormElement:Form,localStorage:storage(),sessionStorage:storage()};
 vm.runInNewContext(source,context);window.IMPE_TELEPHONY_NOTIFY=(...args)=>notices.push(args);
 return {window,context,listeners,notices,reply:fn=>request=fn};
}
const response=(status,payload)=>({ok:status<400,status,json:async()=>payload});
const deferred=()=>{let resolve;return {promise:new Promise(r=>resolve=r),resolve};};
(async()=>{
 const f=fixture(),form=new Form(),hold=deferred();let posts=0,committed=0,errors=0;
 const options={form,user:42,ids:['sdk-fixture-controller'],context:{adaptador:'ANALISTA'},values:{resultado:'NO_INTERESADO',csrf_token:'old-csrf'},endpoint:'/result',onCommitted(){committed++;assert.equal(form.button.disabled,false,'Controls restored before presentation hooks');},onError(){errors++;}};
 f.reply(async(input)=>{if(String(input)==='/result'){posts++;return hold.promise;}return response(200,{ok:true});});
 const sending=f.window.IMPE_CALL_RESULTS.submit(options);
 assert.equal(f.window.IMPE_CALL_RESULTS.busy(form),true);assert.equal(form.button.disabled,true);
 assert.equal((await f.window.IMPE_CALL_RESULTS.submit(options)).busy,true,'Concurrent submit is coalesced by the shared controller');assert.equal(posts,1);
 hold.resolve(response(200,{ok:true,resultado:{id:7}}));assert.equal((await sending).ok,true);assert.equal(committed,1);assert.equal(errors,0);
 assert.equal(f.window.IMPE_CALL_RESULTS.busy(form),false);
 const retry=fixture(),second=new Form();let meta,reads=0,attempts=0;
 retry.reply(async(input,settings)=>{
  if(String(input)==='/result'){
   attempts++;meta=JSON.parse(settings.body.get('captura_confirmacion'));
   assert.equal(settings.body.get('csrf_token'),'fresh-csrf','Shared transport refreshes CSRF');
   return response(503,{ok:false});
  }
  if(settings?.method==='POST')return response(200,{ok:true});
  reads++;return response(200,{ok:true,borrador:{aplicaciones:{[meta.request_id]:{request_id:meta.request_id,revision:meta.revision,adaptador:'ANALISTA',respuesta:{interaccion_id:17}}}}});
 });
 const recovered=await retry.window.IMPE_CALL_RESULTS.submit({...options,form:second,onCommitted(){},onError(){throw Error('Committed result is not a failed save');}});
 assert.equal(recovered.ok,true);assert.equal(recovered.payload.resultado.interaccion_id,17);assert.equal(attempts,1,'Lost ACK never triggers automatic POST replay');assert.equal(reads,1,'Single authoritative receipt lookup');
 const failed=fixture(),invalid=new Form();let deniedReads=0;
 failed.reply(async(input,settings)=>{if(String(input)==='/result')return response(422,{ok:false,mensaje:'Required field'});if(settings?.method!=='POST')deniedReads++;return response(200,{ok:true});});
 const denied=await failed.window.IMPE_CALL_RESULTS.submit({...options,form:invalid,onError(){assert.equal(invalid.button.disabled,false);},onCommitted(){throw Error('Invalid result cannot commit');}});
 assert.equal(denied.ok,false);assert.equal(deniedReads,0,'Validation failure does not poll the database');
 assert.equal(failed.window.IMPE_CALL_DRAFTS.localRead(42,'sdk-fixture-controller').valores.verificacion_telefono_confirmado,'0','Unchecked confirmation is explicitly retained without mutating checkbox value');
 const stale=fixture(),staleForm=new Form(),late=deferred();let current=true,changed=false;
 stale.reply(async(input)=>String(input)==='/result'?late.promise:response(200,{ok:true}));
 const old=stale.window.IMPE_CALL_RESULTS.submit({...options,form:staleForm,isCurrent:()=>current,onCommitted(){},onFinally(){changed=true;},onError(){changed=true;}});
 current=false;staleForm.button.disabled=true;late.resolve(response(503,{ok:false}));await old;
 assert.equal(staleForm.button.disabled,true);assert.equal(changed,false,'Late callbacks do not mutate another opened form');
 const restore=fixture(),restoreForm=new Form();restoreForm.checkbox.checked=true;
 restore.window.IMPE_CALL_DRAFTS.read=async()=>({verificacion_telefono_confirmado:'0'});
 await restore.listeners.get('impe:call-result-form-opened')({detail:{form:restoreForm,ids:['sdk-fixture-restore']}});
 assert.equal(restoreForm.checkbox.checked,false);assert.equal(restoreForm.checkbox.value,'1','Restoring an unchecked box never changes its submitted checked value');
 console.log('PASS shared result controller: single submit, durable approval, fresh CSRF, native receipt recovery without POST replay, validation retention, stale-form guards and checkbox semantics. Mock HTTP; SQL acceptance separate.');
})().catch(e=>{console.error(e);process.exitCode=1;});
