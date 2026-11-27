'use strict';
// Callback result transport/receipt integration, not a complete SDK call scenario.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {fixture}=require('../tests/test_host_outgoing_journal.cjs');
const root=process.argv[process.argv.indexOf('--lab-root')+1];
if(!process.argv.includes('--lab-root')||!root)throw Error('Use --lab-root <private synthetic root>');
const c=JSON.parse(fs.readFileSync(path.join(root,'credentials.json'),'utf8')),lab=JSON.parse(fs.readFileSync(path.join(root,'lab.json'),'utf8'));
if(!lab.synthetic||c.host!=='127.0.0.1'||c.port!==3308||c.database!=='impe_telefonia_lab')throw Error('Synthetic loopback only');
const origin='http://127.0.0.1:33181',endpoint='/index.php?controller=telefonia&action=devolucion';
(async()=>{
 let cookie='',csrf='',drop=false,resultPosts=0,checks=0;
 const request=async(input,options={})=>{
  const url=new URL(String(input),origin);if(url.origin!==origin)throw Error('External target forbidden');
  const headers=new Headers(options.headers||{});if(cookie)headers.set('Cookie',cookie);
  const isResult=url.pathname==='/index.php'&&options.method==='POST'&&new URLSearchParams(options.body).get('accion')==='RESULTADO';
  if(isResult){resultPosts++;if(drop){headers.set('X-Fixture-Drop-Reply','1');drop=false;}}
  return fetch(url,{...options,headers,signal:AbortSignal.timeout(15000)});
 };
 const login=await request('/fixture/login?role=ANALISTA',{headers:{'X-Fixture-Key':c.fixture_key}}),account=await login.json();
 assert.equal(account.ok,true);assert.equal(account.user,42);checks+=2;cookie=login.headers.get('set-cookie').split(';')[0];csrf=account.csrf;
 const pbx='out_lab_callback_'+crypto.randomBytes(8).toString('hex');
 const fixturePost=async(url,body)=>{const r=await request(url,{method:'POST',headers:{'X-Fixture-Key':c.fixture_key,'Content-Type':'application/json'},body:JSON.stringify(body)});const data=await r.json();assert.equal(data.ok,true);checks++;return data;};
 const {attempt}=await fixturePost('/fixture/callback',{pbx,reserve_intent:true});
 const f=fixture({origin,userId:42,extension:'102',csrf,fetch:request});f.window.crypto=crypto.webcrypto;
 Object.assign(f.box,{localStorage:f.window.localStorage,sessionStorage:f.window.localStorage,URL,document:{baseURI:origin+'/',readyState:'loading',addEventListener(){}}});
 vm.runInContext(fs.readFileSync(path.join(__dirname,'../../../public/javascript/telefonia_atencion.js'),'utf8'),f.box);
 await f.box.invoke({destination:'5550000101',context:{type:'DEVOLUCION',callbackAttempt:attempt}});
 const sdkId=f.box.state.callToken,physical=f.window.zdrmWebPhone.webCallSession;
 assert.match(sdkId,/^sdk-/);checks++;f.pulse();
 await f.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 const start=new Date().toISOString().slice(0,19).replace('T',' ');
 for(const [event,ago,extra]of [['NOTIFY_OUT_START',60,{}],['NOTIFY_ANSWER',50,{}],['NOTIFY_OUT_END',10,{disposition:'answered',duration:40}]]){
  await fixturePost('/fixture/event',{event,pbx_call_id:pbx,internal:'102',destination:'5550000101',caller_id:'5550000101',call_start:start,...extra});
 }
 physical.established=true;physical.emit('confirmed');f.pulse();
 await f.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 physical.ended=true;physical.emit('ended');f.pulse();
 await f.window.IMPE_PHONE_HTTP.flushSessions('/prueba_telefonia/api/sesion_local.php');
 assert.equal(f.box.state.active,false,'Local SDK end releases host before business result');checks++;
 const status=await request(endpoint+'&intento_id='+attempt),state=await status.json();assert.equal(status.status,200);assert.equal(state.intento.puede_registrar,true);checks+=2;
 assert.equal(state.intento.sdk_session_id,sdkId);assert.equal(state.intento.pbx_call_id,pbx);checks+=2;
 const values={csrf_token:csrf,accion:'RESULTADO',intento_id:String(attempt),pbx_call_id:pbx,resultado:'CONTACTO_CORRECTO',decision:'sin_identificar',observacion:'Nota sintética de devolución'};
 drop=true;
 const result=await f.window.IMPE_CALL_RESULTS.submit({form:{querySelectorAll:()=>[]},user:42,ids:[sdkId,pbx],context:{adaptador:'DEVOLUCION'},values,captureValues:values,endpoint});
 assert.equal(result.ok,true,result.error?.message);assert.equal(result.payload.recibo_recuperado,true);assert.equal(Number(result.payload.intento.id),attempt);assert.equal(resultPosts,1);checks+=4;
 const replay=await request(endpoint,{method:'POST',body:new URLSearchParams(result.capture.values)}),again=await replay.json();
 assert.equal(replay.status,200);assert.deepEqual(again.intento,result.payload.intento);checks+=2;
 const changed=await request(endpoint,{method:'POST',body:new URLSearchParams({...result.capture.values,observacion:'Alteración no aprobada'})});
 assert.equal(changed.status,409);checks++;
 const after=await request(endpoint+'&intento_id='+attempt),saved=await after.json();
 assert.equal(saved.intento.observacion,values.observacion);assert.equal(saved.intento.resultado,'CONTACTO_CORRECTO');checks+=2;
 const parallelPbx='out_lab_callback_parallel_'+crypto.randomBytes(8).toString('hex');
 const parallel=await fixturePost('/fixture/callback',{pbx:parallelPbx});
 for(const event of ['NOTIFY_OUT_START','NOTIFY_ANSWER','NOTIFY_OUT_END'])
  await fixturePost('/fixture/event',{event,pbx_call_id:parallelPbx,internal:'102',destination:'5550000101',caller_id:'5550000101',call_start:start,...(event==='NOTIFY_OUT_END'?{disposition:'answered',duration:20}:{})});
 const approved=f.window.IMPE_CALL_DRAFTS.prepareSubmission(42,[parallelPbx],{
  ...values,intento_id:String(parallel.attempt),pbx_call_id:parallelPbx
 },{adaptador:'DEVOLUCION'});
 const answers=await Promise.all([33180,33181].map(async port=>{
  const r=await fetch('http://127.0.0.1:'+port+endpoint,{method:'POST',headers:{Cookie:cookie},body:new URLSearchParams(approved.values),signal:AbortSignal.timeout(15000)});
  const data=await r.json();assert.equal(r.status,200,'Concurrent identical approved request returns committed receipt');return data;
 }));
 assert.deepEqual(answers[0],answers[1]);checks+=3;
 const evidence={synthetic:true,checks,scope:'Native callback HTTP/CSRF/result/atomic receipt, lost response, one explicit replay and changed-content rejection. Not a complete SDK call.',production_changed:false};
 fs.writeFileSync(path.join(root,'callback-http-validation.json'),JSON.stringify(evidence,null,2));
 console.log(`PASS ${checks} native callback HTTP receipt checks; synthetic loopback only; not complete-call acceptance.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
