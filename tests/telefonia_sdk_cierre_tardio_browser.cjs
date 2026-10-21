const fs=require('node:fs'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{const b=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'msedge'});try{
 const p=await b.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));let closed=false,requests=0;
 const html=cp.execFileSync('php',['tests/fixtures/telefonia_ventas_shell.php'],{encoding:'utf8'});
 await p.route('http://ventas.test/**',r=>{const u=new URL(r.request().url());
  if(u.pathname.startsWith('/public/css/'))return r.fulfill({contentType:'text/css',body:fs.readFileSync('.'+u.pathname,'utf8')});
  if(u.pathname.startsWith('/public/javascript/'))return r.fulfill({contentType:'application/javascript',body:fs.readFileSync('.'+u.pathname,'utf8')});
  if(u.searchParams.get('action')==='detalleVenta'){
   if(u.searchParams.has('pendientes'))return r.fulfill({json:{ok:true,pendientes:[]}});
   assert(!/^sdk-/.test(u.searchParams.get('pbx_call_id')||''));requests++;
   if(u.searchParams.has('solicitud_id')){assert.equal(u.searchParams.get('solicitud_id'),'91');assert.equal(u.searchParams.get('sdk'),'sdk-sales-late-1234567890');}
   return r.fulfill({json:{ok:true,llamada:closed?{pbx_call_id:'pbx-sales-late-001',extension:'150',nombre:'Susan',finalizada:true,conectada:true,segundos:null}:null}});
  }
  return r.fulfill({contentType:'text/html',body:u.pathname==='/'?html:'<html>Transporte y CRM simulados</html>'});
 });
 await p.goto('http://ventas.test/');await p.waitForFunction(()=>window.IMPE_SALES_ATTENTION);
 await p.evaluate(()=>{window.IMPE_AVAILABILITY={pending:()=>null,acceptedTransfer:()=>({id:91,nombre:'Susan',referencia:'pbx-sales-late-001',numero:'+525551112233',at:window.offerAt,origen_extension:'100'})};window.offerAt=Date.now();});
 const push=active=>p.evaluate(active=>{const s={userId:8,hostReady:true,updatedAt:Date.now(),active,phase:active?'in-progress':'incoming-finished',direction:'incoming',destination:'+525551112233',duration:22,pbxCallId:'sdk-sales-late-1234567890',callToken:'sdk-sales-late-1234567890',requestedAt:window.offerAt/1000,answeredAtMs:window.offerAt,context:{type:'SDK_INCOMING',sdkConnected:true}};localStorage.setItem('impe:telephony:state:8',JSON.stringify(s));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(s)}));},active);
 await push(true);await p.waitForTimeout(1800);assert(requests>0);await push(false);
 await p.waitForFunction(()=>!IMPE_TELEPHONY_SHELL.getCallState().active&&document.querySelector('[data-incoming-register-finished]').disabled);
 closed=true;await p.waitForFunction(()=>!document.querySelector('[data-incoming-register-finished]').disabled,{},{timeout:15000});
 const button=p.locator('[data-incoming-register-finished]');assert.equal(await button.innerText(),'Registrar resultado');
 assert.match(await p.locator('[data-incoming-panel]').textContent(),/Guardada en Mis llamadas/);
 for(const width of [1366,390]){await p.setViewportSize({width,height:800});assert.equal(await p.locator('[data-incoming-panel] .persistent-phone-actions button:visible').count(),1);if(process.env.IMPE_PHONE_SHOTS)await p.locator('[data-incoming-panel]').screenshot({path:process.env.IMPE_PHONE_SHOTS+'/ventas-final-'+width+'.png'});}
 await button.click();await p.waitForFunction(()=>document.querySelector('#impeCrmFrame').src.includes('pbx_call_id=pbx-sales-late-001'));
 assert.deepEqual(errors,[]);console.log('Edge: SDK local → ANSWER/OUT_END tardíos → botón y componente compartido conciliados, referencia exacta sin F5: OK');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
