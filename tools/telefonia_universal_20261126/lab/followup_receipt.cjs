'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {fixture}=require('../tests/test_host_outgoing_journal.cjs');
const root=process.argv[process.argv.indexOf('--lab-root')+1];if(!process.argv.includes('--lab-root')||!root)throw Error('Private laboratory root required');
const c=JSON.parse(fs.readFileSync(path.join(root,'credentials.json'),'utf8')),lab=JSON.parse(fs.readFileSync(path.join(root,'lab.json'),'utf8'));
if(!lab.synthetic||c.host!=='127.0.0.1'||c.port!==3308||c.database!=='impe_telefonia_lab')throw Error('Synthetic loopback only');
(async()=>{
 const informative=process.argv.includes('--informative'),pendingDuration=process.argv.includes('--pending-duration'),zeroDuration=process.argv.includes('--zero-duration'),adapter=informative?'INFORMATIVA':'SEGUIMIENTO';
 const action=informative?'registrarInformativa':'registrarInteraccionTrabajo';
 const origin='http://127.0.0.1:33181',endpoint='/index.php?controller='+(informative?'seguimientoInteraccion':'seguimientoVinculacion')+'&action='+action;
 let cookie='',drop=false,resultPosts=0,checks=0;
 const request=async(input,options={})=>{const url=new URL(String(input),origin);if(url.origin!==origin)throw Error('External target blocked');
  const headers=new Headers(options.headers||{});if(cookie)headers.set('Cookie',cookie);
  if(url.searchParams.get('action')===action&&options.method==='POST'){resultPosts++;if(drop){headers.set('X-Fixture-Drop-Reply','1');drop=false;}}
  return fetch(url,{...options,headers,signal:AbortSignal.timeout(15000)});};
 const login=await request('/fixture/login?role=ANALISTA',{headers:{'X-Fixture-Key':c.fixture_key}}),account=await login.json();assert.equal(account.user,42);checks++;cookie=login.headers.get('set-cookie').split(';')[0];
 const event=async(pbx,type,extra={})=>{const r=await request('/fixture/event',{method:'POST',headers:{'X-Fixture-Key':c.fixture_key,'Content-Type':'application/json'},body:JSON.stringify({event:type,pbx_call_id:pbx,internal:'102',destination:'5550000101',caller_id:'5550000101',...(type==='NOTIFY_OUT_END'?{disposition:'answered',duration:1}:{}),...extra})});assert.equal((await r.json()).ok,true);checks++;};
 const creation='out_lab_followup_create_'+crypto.randomBytes(6).toString('hex');
 for(const type of ['NOTIFY_OUT_START','NOTIFY_ANSWER','NOTIFY_OUT_END'])await event(creation,type);
 const setup=await request('/index.php?controller=seguimientoVinculacion&action=registrarLlamadaEntrante',{method:'POST',body:new URLSearchParams({csrf_token:account.csrf,pbx_call_id:creation,decision:'nueva',nombre_entidad:'Institución HTTP '+crypto.randomBytes(6).toString('hex'),tipo_entidad:'INSTITUCION',estado_id:'1',resultado:'CONTACTO_CORRECTO',persona_atendio:'Persona sintética',telefono_capturado:'5550000101',correo_capturado:'fixture@example.invalid'})});
 const created=await setup.json();assert.equal(created.ok,true,created.mensaje);const sid=created.resultado.seguimiento_id;checks++;
 if(informative){
  const advanced=await request('/fixture/followup-stage',{method:'POST',headers:{'X-Fixture-Key':c.fixture_key,'Content-Type':'application/json'},body:JSON.stringify({id:sid})});
  assert.equal((await advanced.json()).ok,true);checks++;
 }
 const f=fixture({origin,userId:42,extension:'102',csrf:account.csrf,fetch:request});f.window.crypto=crypto.webcrypto;
 Object.assign(f.box,{localStorage:f.window.localStorage,sessionStorage:f.window.localStorage,URL,document:{baseURI:origin+'/',readyState:'loading',addEventListener(){}}});
 vm.runInContext(fs.readFileSync(path.join(__dirname,'../../../public/javascript/telefonia_atencion.js'),'utf8'),f.box);
 const reference='out_'+crypto.randomBytes(16).toString('hex');
 await f.box.invoke({destination:'5550000101',context:{type:'VINCULACION',seguimientoId:sid}});f.pulse();
 await f.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 const session=f.box.state.callToken,physical=f.window.zdrmWebPhone.webCallSession;
 await event(reference,'NOTIFY_OUT_START');physical.established=true;physical.emit('confirmed');f.pulse();
 await f.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 await event(reference,'NOTIFY_ANSWER');await new Promise(resolve=>setTimeout(resolve,1100));
 physical.ended=true;physical.emit('ended');f.pulse();await f.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 await event(reference,'NOTIFY_OUT_END',pendingDuration?{duration:undefined}:zeroDuration?{duration:0}:{});
 const date=new Date(Date.now()-60000).toLocaleString('sv-SE',{timeZone:'America/Mexico_City'}).slice(0,16).replace(' ','T');
 const values={csrf_token:account.csrf,seguimiento_id:String(sid),llamada_pbx_id:reference,canal:'LLAMADA',origen_llamada:'ZADARMA',resultado:'BUZON_VOZ',fecha_inicio:date,observacion:'Resultado sintético de Seguimiento'};
 if(pendingDuration||zeroDuration)Object.assign(values,{resultado:'CONTACTO_CORRECTO',persona_atendio:'Persona sintética',verificacion_telefono_confirmado:'1',verificacion_correo_confirmado:'1'});
 drop=true;
 const saved=await f.window.IMPE_CALL_RESULTS.submit({form:{querySelectorAll:()=>[]},user:42,ids:[reference],context:{adaptador:adapter},values,captureValues:values,endpoint});
 assert.equal(saved.ok,true,saved.error?.message);assert.equal(saved.payload.recibo_recuperado,true);assert.equal(resultPosts,1);
 if(informative)assert.ok(saved.payload.interaccion.id>0);else assert.equal(saved.payload.seguimiento.id,sid);checks+=4;
 const replay=await request(endpoint,{method:'POST',body:new URLSearchParams(saved.capture.values)}),again=await replay.json();assert.equal(replay.status,200);
 assert.equal(informative?again.interaccion.id:again.interaccion_id,informative?saved.payload.interaccion.id:saved.payload.interaccion_id);checks+=2;
 const tampered=await request(endpoint,{method:'POST',body:new URLSearchParams({...saved.capture.values,observacion:'Contenido distinto'})});assert.equal(tampered.status,409);checks++;
 const inspect=await request('/fixture/inspect?pbx='+reference+'&followup='+sid,{headers:{'X-Fixture-Key':c.fixture_key}}),counts=await inspect.json();assert.equal(counts.counts.interacciones_vinculacion,2,'One setup call plus exactly one approved Seguimiento interaction');checks++;
 const recording='17490000000000001234567890';await event(reference,'NOTIFY_RECORD',{call_id_with_rec:recording});
 const linkBody={csrf_token:account.csrf,seguimiento_id:String(sid),interaccion_id:String(informative?saved.payload.interaccion.id:saved.payload.interaccion_id),pbx_call_id:reference,session_id:session,duration_client:'9000',lookup_attempt:'1'};
 const linked=await request('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{method:'POST',body:new URLSearchParams(linkBody)}),linkedData=await linked.json();
 assert.equal(linked.status,200,linkedData.mensaje);assert.equal(linkedData.duracion_segundos,pendingDuration?null:zeroDuration?0:1,'Client duration must never replace unknown or actual PBX seconds');
 assert.equal(linkedData.verificacion_efectiva,false,'Answered voicemail cannot become effective');assert.equal(linkedData.call_id_with_rec,recording);checks+=4;
 await event(reference,'NOTIFY_RECORD',{internal:'100',call_id_with_rec:recording});
 const shared=await request('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{method:'POST',body:new URLSearchParams(linkBody)}),sharedData=await shared.json();
 assert.equal(shared.status,200);assert.equal(sharedData.call_id_with_rec,'');assert.equal(sharedData.grabacion_disponible,false,'Shared/foreign recording must not be attached as the analyst own recording');checks+=3;
 const wrongSession=await request('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{method:'POST',body:new URLSearchParams({...linkBody,session_id:'sdk-other-unrelated-dialogue'})});assert.equal(wrongSession.status,409);checks++;
 const badCsrf=await request('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{method:'POST',body:new URLSearchParams({...linkBody,csrf_token:'invalid'})});assert.equal(badCsrf.status,403);checks++;
 const priorInteraction=await request('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{method:'POST',body:new URLSearchParams({...linkBody,interaccion_id:String(created.resultado.interaccion_id)})});
 assert.equal(priorInteraction.status,409,'Same actor cannot attach this call to another historical interaction');checks++;
 const afterReject=await request('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{method:'POST',body:new URLSearchParams(linkBody)});assert.equal(afterReject.status,200,'Rejected mutation rolls back and releases its locks');checks++;
 if(zeroDuration){
  assert.equal(linkedData.verificacion_pendiente,false);checks++;
  assert.equal(linkedData.verificacion_rechazada,true);checks++;
  const r=await request('/fixture/inspect?pbx='+reference+'&followup='+sid,{headers:{'X-Fixture-Key':c.fixture_key}}),zero=await r.json();
  assert.equal(zero.counts.evidencias_confirmadas,0);assert.equal(zero.counts.evidencias_rechazadas,2);checks+=2;
 }
 if(pendingDuration){
  assert.equal(linkedData.verificacion_pendiente,true);checks++;
  const read=async()=>{const r=await request('/fixture/inspect?pbx='+reference+'&followup='+sid,{headers:{'X-Fixture-Key':c.fixture_key}});return (await r.json()).counts;};
  const beforeLate=await read();assert.equal(beforeLate.evidencias_confirmadas,0);checks++;
  await event(reference,'NOTIFY_OUT_END',{duration:1});
  const late=await request('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{method:'POST',body:new URLSearchParams(linkBody)}),confirmed=await late.json();
  assert.equal(late.status,200);assert.equal(confirmed.duracion_segundos,1);assert.equal(confirmed.verificacion_efectiva,true);checks+=3;
  const afterLate=await read();assert.equal(afterLate.evidencias_confirmadas,2);assert.equal(Number(afterLate.efectivas_del_dia),Number(beforeLate.efectivas_del_dia)+1);checks+=2;
  await request('/prueba_telefonia/api/vincular_interaccion_zadarma.php',{method:'POST',body:new URLSearchParams(linkBody)});
  const repeated=await read();assert.equal(repeated.efectivas_del_dia,afterLate.efectivas_del_dia);assert.equal(repeated.interacciones_vinculacion,2);checks+=2;
 }
 fs.writeFileSync(path.join(root,(informative?'informative':'followup')+(pendingDuration?'-pending-duration':zeroDuration?'-zero-duration':'')+'-http-validation.json'),JSON.stringify({synthetic:true,checks,native_http:true,lost_ack_recovered:true,unknown_duration:pendingDuration,zero_duration:zeroDuration,complete_call_acceptance:false,production_changed:false},null,2));
 console.log(`PASS ${checks} native ${adapter} HTTP receipt checks: fresh CSRF, existing institutional pipeline, lost ACK, exact replay, immutable request and no duplicate interaction. Not complete-call acceptance.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
