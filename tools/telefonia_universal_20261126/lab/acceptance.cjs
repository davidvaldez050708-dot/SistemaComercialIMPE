'use strict';
// Real local HTTP + SQL + actual host journal/capture/native result services.
// SDK audio and signed-provider delivery are represented by synthetic adapters.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {fixture}=require('../tests/test_host_outgoing_journal.cjs');
const args=process.argv.slice(2),root=args[args.indexOf('--lab-root')+1],requested=Number(args[args.indexOf('--calls')+1]||3);
if(!root||!args.includes('--lab-root')||!Number.isInteger(requested)||requested<1||requested>1000)throw Error('Use --lab-root <private-root> --calls 1..1000');
const credentials=JSON.parse(fs.readFileSync(path.join(root,'credentials.json'),'utf8'));
const lab=JSON.parse(fs.readFileSync(path.join(root,'lab.json'),'utf8'));
if(!lab.synthetic||credentials.host!=='127.0.0.1'||credentials.port!==3308||credentials.database!=='impe_telefonia_lab')throw Error('Private synthetic fixture only');
const frozenManifest=fs.readFileSync(path.join(root,'sources.json'),'utf8'),frozen=JSON.parse(frozenManifest);
const verifySources=()=>{
 if(fs.readFileSync(path.join(root,'sources.json'),'utf8')!==frozenManifest)throw Error('Fixture source manifest changed during acceptance');
 for(const [name,expected] of Object.entries(frozen.files)){
  const resolved=path.resolve(root,'site',name),base=path.resolve(root,'site')+path.sep;
  if(!resolved.startsWith(base)||fs.lstatSync(resolved).isSymbolicLink()||crypto.createHash('sha256').update(fs.readFileSync(resolved)).digest('hex')!==expected)
   throw Error('Frozen runtime changed; acceptance is invalid');
 }
 // The imported host fixture executes actual repository JS in a VM. It must
 // match the frozen HTTP runtime too, rather than silently testing two versions.
 for(const name of ['telefonia_host.js','telefonia_http.js','telefonia_atencion.js']){
  const target='public/javascript/'+name;
  if(crypto.createHash('sha256').update(fs.readFileSync(path.join(__dirname,'../../../',target))).digest('hex')!==frozen.files[target])
   throw Error('VM host code differs from the frozen HTTP runtime');
 }
};
verifySources();
const source=fs.readFileSync(path.join(root,'site/public/javascript/telefonia_atencion.js'),'utf8');
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const roles=[['VENTAS',41,'101',33180],['ANALISTA',42,'102',33181],['RECEPCION',43,'100',33182]];
const run=crypto.randomBytes(8).toString('hex'),results=[],started=process.hrtime.bigint();
async function actor(config,count){
 const [role,user,extension,port]=config,origin='http://127.0.0.1:'+port;
 let cookie='',csrf='',requests=0,dropObservation=false,dropResult=false;
 const fetchOwned=async(input,options={})=>{
  const url=new URL(String(input),origin);if(url.origin!==origin)throw Error('Non-loopback request blocked');
  const headers=new Headers(options.headers||{});if(cookie)headers.set('Cookie',cookie);
  const dropping=dropResult&&options.method==='POST'&&(url.pathname==='/index.php'||url.pathname.endsWith('/resultado_recepcion_marketing.php'));
  if(dropping){headers.set('X-Fixture-Drop-Reply','1');dropResult=false;}
  const response=await fetch(url,{...options,headers});++requests;
  if(dropping&&response.status===503){await login();if(f)f.window.IMPE_CSRF_TOKEN=csrf;}

  if(dropObservation&&options.body&&String(options.body).includes('"evento":"ended"')){dropObservation=false;await response.arrayBuffer();throw Error('Synthetic lost SDK ACK');}
  return response;
 };
 const login=async()=>{
  const r=await fetchOwned('/fixture/login?role='+role,{headers:{'X-Fixture-Key':credentials.fixture_key}}),d=await r.json();
  assert.equal(r.status,200,'Fixture authentication HTTP status');assert.equal(d.ok,true,'Fresh account/role permission checks');
  cookie=r.headers.get('set-cookie').split(';')[0];csrf=d.csrf;
 };
 await login();
 const f=fixture({origin,userId:user,extension,csrf,fetch:fetchOwned});
 f.window.crypto=crypto.webcrypto;
 Object.assign(f.box,{localStorage:f.window.localStorage,sessionStorage:f.window.localStorage,URL,
  document:{baseURI:origin+'/',readyState:'loading',addEventListener(){}}});
 vm.runInContext(source,f.box);
 const drafts=f.window.IMPE_CALL_DRAFTS;
 const postEvent=async event=>{
  const r=await fetchOwned('/fixture/event',{method:'POST',headers:{'X-Fixture-Key':credentials.fixture_key,'Content-Type':'application/json'},body:JSON.stringify(event)});
  const data=await r.json();assert.equal(data.ok,true,'Synthetic provider event persisted by actual EventStore');
 };
 const flush=async()=>{
  const begin=Date.now();
  while(true){
   await f.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
   const storage=f.window.localStorage;let pending=false;
   for(let i=0;i<storage.length;i++)if(storage.key(i).startsWith('impe.phone.sdk.outbox.'))pending=true;
   if(!pending)return;if(Date.now()-begin>15000)throw Error('SDK outbox did not settle');await sleep(50);
  }
 };
 for(let n=0;n<count;++n){
  const seq=results.length+1,phone='555'+String(1000000+(user*10000)+n),pbx='out_lab_'+run+'_'+user+'_'+n;
  const before=process.hrtime.bigint();
  await f.box.invoke({destination:phone,context:{type:'LABORATORIO'}});
  const token=f.box.state.callToken;assert.match(token,/^sdk-/);f.pulse();await flush();
  const physical=f.window.zdrmWebPhone.webCallSession;
  await postEvent({event:'NOTIFY_OUT_START',pbx_call_id:pbx,internal:extension,destination:phone,caller_id:phone});
  physical.established=true;physical.emit('confirmed');f.pulse();await flush();
  await postEvent({event:'NOTIFY_ANSWER',pbx_call_id:pbx,internal:extension,destination:phone,caller_id:phone});
  await drafts.save(user,[token],{persona:'Persona sintética',correo:'fixture@example.invalid',notas:'Borrador de laboratorio'},csrf,{adaptador:role});
  await sleep(1100); // Actual elapsed SDK conversation; not a fake clock.
  if(n%7===3)dropObservation=true;
  physical.ended=true;physical.emit('ended');f.pulse();await flush();
  assert.equal(f.box.state.active,false,'Physical SDK END releases host');
  await postEvent({event:'NOTIFY_OUT_END',pbx_call_id:pbx,internal:extension,destination:phone,caller_id:phone,disposition:'answered',duration:1});
  const status=await fetchOwned('/prueba_telefonia/api/zadarma_estado_llamada.php?'+new URLSearchParams({session_id:token,destination:phone,since:String(Math.floor(Date.now()/1000)),after_id:'0',final:'1'}));
  const state=await status.json();assert.equal(status.status,200,'Actual outgoing status endpoint');
  assert.equal(state.call?.pbx_call_id,pbx,'Persisted intention resolves exact own PBX reference');
  const common={csrf_token:csrf,pbx_call_id:pbx};let values,url;
  if(role==='VENTAS'){
   values={...common,extension,catalogo_version:'4',resultado:'BUZON_VOZ',resultados_seleccionados:'["BUZON_VOZ"]',observacion:'Resultado sintético'};
   url='/index.php?controller=telefonia&action=guardarResultadoVenta';
  }else if(role==='ANALISTA'){
   values={...common,decision:'nueva',nombre_entidad:'Laboratorio '+run+' '+n,tipo_entidad:'INSTITUCION',estado_id:'1',municipio_id:'',resultado:'CONTACTO_CORRECTO',persona_atendio:'Persona sintética',telefono_capturado:phone,correo_capturado:'fixture@example.invalid',verificacion_telefono_confirmado:'1',verificacion_correo_confirmado:'1',observacion:'Resultado sintético'};
   url='/index.php?controller=seguimientoVinculacion&action=registrarLlamadaEntrante';
  }else{values={...common,resultado:'CONTACTO',nota:'Resultado sintético'};url='/prueba_telefonia/api/resultado_recepcion_marketing.php';}
  dropResult=n%5===2;
  const submitted=await f.window.IMPE_CALL_RESULTS.submit({form:{querySelectorAll:()=>[]},user,ids:[token,pbx],
   context:{adaptador:role},values,captureValues:values,endpoint:url});
  if(!submitted.ok)throw Error(role+' shared controller: '+submitted.error?.message);
  const approved=submitted.capture,saved=submitted.payload;
  if(n%5===2)assert.equal(saved.recibo_recuperado,true,'Shared controller recovers actual native receipt after lost ACK and fresh login');
  approved.values.csrf_token=csrf;
  if(role==='ANALISTA')assert.equal(saved.resultado.verificacion_confirmada,true,'Native explicit phone/email and own answered call qualify');
  const again=await fetchOwned(url,{method:'POST',body:new URLSearchParams(approved.values)});assert.equal(again.status,200,'Idempotent result replay');
  const check=await fetchOwned('/fixture/inspect?pbx='+pbx,{headers:{'X-Fixture-Key':credentials.fixture_key}}),record=await check.json();
  const table={VENTAS:'telefonia_ventas_resultados',ANALISTA:'telefonia_analista_entrantes',RECEPCION:'telefonia_recepcion_resultados'}[role];
  assert.equal(record.counts[table],1,'Exactly one native record for one complete cycle');
  await drafts.remove(user,approved.ids,csrf,approved.revision);
  results.push({role,pbx,session:token,lost_sdk_ack:n%7===3,lost_result_ack:n%5===2,elapsed_ms:Number(process.hrtime.bigint()-before)/1e6});
  if(results.length%25===0)console.log('COMPLETE_SYNTHETIC_HTTP_CYCLES='+results.length+'/'+requested);
 }
 return {role,count,requests};
}
(async()=>{
 const workers=await Promise.all(roles.map((role,i)=>actor(role,Math.floor(requested/3)+(i<requested%3?1:0))));
 verifySources();
 const report={synthetic:true,run,source_sha256:frozen.sha256,source_files_verified:Object.keys(frozen.files).length,
  complete_http_cycles:results.length,requested,elapsed_monotonic_seconds:Number(process.hrtime.bigint()-started)/1e9,workers,results,
  scope:'Actual host SDK observations, HTTP auth/CSRF, PBX persistence, drafts and native atomic results. Synthetic SDK/provider. Transfers/audio/concurrency faults require separate acceptance scenarios.',
  universal_acceptance_completed:false,eight_hours:false};
 fs.writeFileSync(path.join(root,'http-acceptance-'+run+'.json'),JSON.stringify(report,null,2));
 console.log('PASS COMPLETE_SYNTHETIC_HTTP_CYCLES='+results.length+'; three native role adapters; NOT full universal acceptance until transfer/audio scenarios pass.');
})().catch(e=>{fs.writeFileSync(path.join(root,'http-acceptance-failure-'+run+'.json'),JSON.stringify({error:e.stack,completed:results.length},null,2));console.error(e.message);process.exitCode=1;});
