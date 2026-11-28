'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const root=process.argv[process.argv.indexOf('--lab-root')+1];if(!process.argv.includes('--lab-root')||!root)throw Error('Private laboratory required');
const c=JSON.parse(fs.readFileSync(path.join(root,'credentials.json'),'utf8')),meta=JSON.parse(fs.readFileSync(path.join(root,'lab.json'),'utf8'));
if(!meta.synthetic||c.host!=='127.0.0.1'||c.port!==3308||c.database!=='impe_telefonia_lab')throw Error('Synthetic loopback only');
(async()=>{
 let checks=0;const origin='http://127.0.0.1:33182',ids=['17490000000000001234567890','17490000000000001234567891'];
 const login=async role=>{const r=await fetch(origin+'/fixture/login?role='+role,{headers:{'X-Fixture-Key':c.fixture_key}});assert.equal(r.status,200);const data=await r.json();return {cookie:r.headers.get('set-cookie').split(';')[0],csrf:data.csrf};};
 const cookies={RECEPCION:await login('RECEPCION'),VENTAS:await login('VENTAS'),ANALISTA:await login('ANALISTA')};
 const own=async(role,url,headers={})=>fetch(origin+url,{headers:{Cookie:cookies[role].cookie,...headers},signal:AbortSignal.timeout(15000)});
 const event=async(pbx,type,ext,extra={})=>{const r=await fetch(origin+'/fixture/event',{method:'POST',headers:{'X-Fixture-Key':c.fixture_key,'Content-Type':'application/json'},body:JSON.stringify({event:type,pbx_call_id:pbx,internal:ext,caller_id:'5550000101',destination:'5550000101',...(type==='NOTIFY_OUT_END'?{disposition:'answered',duration:1,is_recorded:'1'}:{}),...extra})});assert.equal((await r.json()).ok,true);};
 for(const [role,ext,api]of [['RECEPCION','100','grabacion_recepcion_marketing.php'],['VENTAS','101','grabacion_ventas.php']]){
  const pbx='out_lab_audio_'+crypto.randomBytes(8).toString('hex');
  for(const type of ['NOTIFY_OUT_START','NOTIFY_ANSWER','NOTIFY_OUT_END'])await event(pbx,type,ext);
  const url='/prueba_telefonia/api/'+api+'?'+new URLSearchParams({pbx_call_id:pbx,tipo:'saliente',extension:ext});
  if(role==='RECEPCION'){
   const r=await own(role,url+'&verificar=1'),data=await r.json();assert.equal(r.status,200);assert.equal(data.disponible,false);assert.equal(data.segmentos,0);assert.equal(data.estado,'TRAMO_PENDIENTE_VINCULACION');checks+=4;
  }
  for(const id of ids)await event(pbx,'NOTIFY_RECORD',ext,{call_id_with_rec:id});
  if(role==='VENTAS'){
   const blocked=await own(role,url+'&grabacion='+ids[0]);assert.equal(blocked.status,404,'Audio remains unavailable until the existing commercial result permits it');checks++;
   const saved=await fetch(origin+'/index.php?controller=telefonia&action=guardarResultadoVenta',{method:'POST',headers:{Cookie:cookies[role].cookie},
    body:new URLSearchParams({csrf_token:cookies[role].csrf,pbx_call_id:pbx,extension:ext,catalogo_version:'4',resultado:'CONVERSACION_PERSONA',resultados_seleccionados:'["CONVERSACION_PERSONA"]',observacion:'Conversación sintética para política de audio'})});
   const data=await saved.json();assert.equal(saved.status,200);assert.equal(data.ok,true);checks+=2;
  }
  const foreign=await own(role,url+'&grabacion=fixture-foreign-id');assert.equal(foreign.status,404);checks++;
  const implicit=await own(role,url);assert.equal(implicit.status,409,'Multiple records must not choose the first group link');checks++;
  const staleOrdinal=await own(role,url+'&segmento=0');assert.equal(staleOrdinal.status,409,'Unbound old numeric selection must not drift');checks++;
  const invalidRange=await own(role,url+'&grabacion='+ids[0],{Range:'bytes=invalid'});assert.equal(invalidRange.status,416);checks++;
  const ownUnavailable=await own(role,url+'&grabacion='+ids[0]);assert.equal(ownUnavailable.status,502,'Synthetic laboratory blocks real provider credentials/transport instead of inventing audio');checks++;
  const other=await own(role==='VENTAS'?'RECEPCION':'VENTAS',url+'&grabacion='+ids[0]);assert.equal(other.status,403);checks++;
  const unauth=await fetch(origin+url+'&grabacion='+ids[0]);assert.equal(unauth.status,401);checks++;
 }
 fs.writeFileSync(path.join(root,'audio-scope-http-validation.json'),JSON.stringify({synthetic:true,checks,native_http:true,provider_disabled:true,binary_audio_validated:false,complete_call_acceptance:false,production_changed:false},null,2));
 console.log(`PASS ${checks} native audio HTTP scope checks: roles/auth, exact owned recording membership, no implicit first link, stale ordinal and malformed Range rejected. Provider deliberately unavailable; no positive binary/audio or full acceptance claim.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
