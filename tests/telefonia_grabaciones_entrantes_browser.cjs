const fs=require('node:fs'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||undefined});try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const bootstrap=fs.readFileSync(process.env.BOOTSTRAP_MODULE_PATH+'/dist/js/bootstrap.bundle.min.js','utf8');
 const script=p=>fs.readFileSync('public/javascript/'+p,'utf8');
 let data,status=200,mode='',posts=0,postFails=false;
 const parts=[{indice:0,tramo:1,extension:'100',etiqueta:'Recepción · Ext. 100',segundos:25},{indice:1,tramo:2,extension:'150',etiqueta:'Asesor · Ext. 150',segundos:37}];
 await page.route('http://ventas.test/**',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(url.pathname.endsWith('/grabacion_ventas.php'))return route.fulfill({status,json:data});
  if(request.method()==='POST'){
   ++posts;assert.match(request.postData(),/csrf_token=fixture-csrf/);assert.match(request.postData(),/pbx_call_id=pbx-inbound-recording-001/);assert.match(request.postData(),/extension=150/);
   return route.fulfill({status:postFails?502:200,json:postFails?{ok:false}:{ok:true,llamada:{segundos:37}}});
  }
  if(url.pathname.startsWith('/public/'))return route.fulfill({contentType:'application/javascript',body:''});
  const html=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/ventas_grabaciones_vista.php',mode],{encoding:'utf8'});
  return route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset=utf-8>'+html+'<script>'+bootstrap+'</script><script>window.testToasts=[];window.IMPE_TELEPHONY_NOTIFY=(s)=>testToasts.push(s);</script><script>'+script('telefonia_segmentos_player.js')+'</script><script>'+script('telefonia_ventas_resultados.js')+'</script>'});
 });
 const load=async pending=>{mode=pending?'pending':'';await page.goto('http://ventas.test/');await page.locator('[data-sales-audio]').waitFor();};
 const toggle=()=>page.locator('[data-sales-audio]'),slot=()=>page.locator('[data-sales-audio-slot]');
 data={ok:true,disponible:true,estado:'DISPONIBLE',segmentos:2,segmentos_info:parts,segundos:37};
 await load(false);assert.match(await toggle().textContent(),/Consultar grabaciones/);assert.match(await page.locator('.linkage-call-recording-duration').textContent(),/asesor pendiente/);
 await toggle().click();await slot().locator('audio').last().waitFor({state:'attached'});assert.equal(await slot().locator('audio').count(),2);await page.waitForFunction(()=>document.querySelector('.linkage-call-recording-duration').textContent==='00:37');assert.equal(posts,1);assert.match(await slot().textContent(),/Recepción.*100.*Asesor.*150/s);
 const confirmedButton=await toggle().evaluate(el=>({classes:el.className,icons:[...el.querySelectorAll('i')].map(i=>i.className)}));
 await toggle().click();assert(await slot().isHidden());
 // Sin END del asesor: reproduce, pero no intenta fabricar el cierre ni escribir evidencia.
 await load(true);assert.deepEqual(await toggle().evaluate(el=>({classes:el.className,icons:[...el.querySelectorAll('i')].map(i=>i.className)})),confirmedButton,'Llamada antigua pendiente conserva el mismo diseño, icono y flecha');const oldPosts=posts;await toggle().click();await slot().locator('audio').last().waitFor({state:'attached'});assert.equal(posts,oldPosts);assert.match(await toggle().textContent(),/2 grabaciones/);assert.deepEqual(await toggle().evaluate(el=>({classes:el.className,icons:[...el.querySelectorAll('i')].map(i=>i.className)})),confirmedButton,'Verificar dos audios conserva el diseño y ambos iconos');
 // Un tramo listo conserva el índice de origen y no desaparece si falla la conciliación.
 postFails=true;data={...data,segmentos:1,estado:'DISPONIBLE_PARCIAL',segmentos_info:[parts[1]],segundos:37};
 await load(false);await toggle().click();await slot().locator('audio').waitFor({state:'attached'});assert.match(await slot().locator('audio').getAttribute('src'),/segmento=1/);assert.match(await toggle().textContent(),/Consultar grabación/);assert(await slot().isVisible());
 for(const state of ['GRABACION_EN_PREPARACION','ERROR_TEMPORAL','ACCESO_NO_AUTORIZADO']){
  status=state==='ACCESO_NO_AUTORIZADO'?403:200;data={ok:status===200,disponible:false,estado:state,segmentos:0};
  await load(true);await toggle().click();await page.waitForFunction(()=>testToasts.length>0);assert.equal(await slot().locator('audio').count(),0);assert(!await toggle().isDisabled());const toast=await page.evaluate(()=>testToasts.at(-1));assert.match(toast,state==='ACCESO_NO_AUTORIZADO'?/Acceso no autorizado/:state==='ERROR_TEMPORAL'?/Error temporal/:/preparación/);
 }
 assert.deepEqual(errors,[]);console.log('OK: vista real Ventas, dos tramos, parcial con índice estable, duración propia, POST con cierre, fallos independientes y reintentos.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
