/* Carga real de scripts y botones, sin cabecera compartida ni base de datos. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),origin='http://impe.test/crm/';
const read=name=>fs.readFileSync(path.join(root,name),'utf8');
const native=read('app/views/convocatorias/index.php');
const start=native.indexOf("    const share = document.querySelector('[data-convocatoria-form-share]');");
const nativeShare=native.slice(start,native.indexOf('    const modalImagenElemento',start));
assert.ok(start>0&&!nativeShare.includes('<?'));
const long=origin+'index.php?controller=formularioPublico&action=registro';
const short=origin+'registro/el-primer-paso-hacia-tu-meta-comienza-aqui/Ab7K9mP2xQ4Z';
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});let count=0;
const check=(value,message)=>{assert.ok(value,message);console.log('OK '+message);count++;};
try{for(const variant of ['modular','nativo']){
 const context=await browser.newContext(),page=await context.newPage(),errors=[];let helperLoads=0,apiCalls=0,failHelper=false,delayApi=false,lastBody='',lastEndpoint='';
 page.on('pageerror',error=>errors.push(error.message));
 await context.route('http://impe.test/**',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(url.pathname.endsWith('/registro_enlaces_amigables.js')){
   helperLoads++;if(failHelper){await route.abort();return;}
   await route.fulfill({contentType:'application/javascript',body:read('public/javascript/registro_enlaces_amigables.js')});return;
  }
  if(url.searchParams.get('action')==='acortarRegistro'){
   apiCalls++;lastEndpoint=url.pathname;lastBody=request.postData()||'';
   if(delayApi)await new Promise(resolve=>setTimeout(resolve,150));
   await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,url:short})});return;
  }
  if(url.pathname.endsWith('/convocatorias_gestion_v5.js')){await route.fulfill({contentType:'application/javascript',body:read('public/javascript/convocatorias_gestion_v5.js')});return;}
  if(url.pathname.endsWith('/generador.js')){await route.fulfill({contentType:'application/javascript',body:variant==='modular'?read('public/javascript/convocatoria_crear_compartido.js'):"document.addEventListener('DOMContentLoaded',function(){\n"+nativeShare+'\n});'});return;}
  await route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="csrf-token" content="csrf-local"><script>window.bootstrap={Toast:{getOrCreateInstance:()=>({show(){}})}};</script><script src="${origin}public/javascript/convocatorias_gestion_v5.js?v=prueba"></script><script defer src="${origin}generador.js"></script>
   <input data-convocatoria-context-classification value="bachillerato|bachillerato-general"><input data-convocatoria-context-title value="Prueba">
   <section data-convocatoria-form-share data-registro-url="${long.replaceAll('&','&amp;')}" data-estado-id="1">
   <select data-convocatoria-form-type><option value="registro">Registro</option></select><button data-convocatoria-generate-link>Generar enlace</button><button data-convocatoria-generate-qr disabled>Generar QR</button>
   <div data-convocatoria-form-result class="d-none"><input data-convocatoria-form-url></div></section><input data-convocatoria-link-input>`});
 });
 await page.goto(origin);check(helperLoads===0,variant+': cabecera sin helper; carga diferida');
 failHelper=true;await page.locator('[data-convocatoria-generate-link]').click();await page.waitForSelector('.toast-body');
 check(apiCalls===0&&await page.locator('[data-convocatoria-form-url]').inputValue()==='',variant+': fallo de carga no muestra una liga incompleta');
 // Toast usa Bootstrap en el sistema; la fixture registra el mensaje sin depender del framework.
 failHelper=false;
 await page.evaluate(()=>{window.bootstrap={Toast:{getOrCreateInstance:()=>({show(){}})}};});
 await page.locator('[data-convocatoria-generate-link]').click();await page.waitForFunction(()=>document.querySelector('[data-convocatoria-form-url]').value.includes('/registro/el-primer-'));
 check(await page.locator('[data-convocatoria-form-url]').inputValue()===short,variant+': botón real recupera liga amigable');
 check(lastEndpoint==='/crm/index.php'&&lastBody.includes('csrf-local')&&lastBody.includes('convocatoria_ref'),variant+': endpoint en subdirectorio, CSRF y procedencia conservados');
 const loaded=helperLoads;await page.locator('[data-convocatoria-generate-link]').click();await page.waitForFunction(()=>!document.querySelector('[data-convocatoria-generate-qr]').disabled);
 check(helperLoads===loaded,variant+': reutiliza helper cargado');
 delayApi=true;await page.locator('[data-convocatoria-form-url]').fill('');await page.locator('[data-convocatoria-generate-link]').click();await page.locator('[data-convocatoria-context-classification]').fill('ingles|ingles');await page.waitForTimeout(250);
 check(await page.locator('[data-convocatoria-form-url]').inputValue()==='',variant+': cambio de clasificación descarta respuesta obsoleta');
 await page.reload();await page.evaluate(()=>{window.bootstrap={Toast:{getOrCreateInstance:()=>({show(){}})}};});
 const before=helperLoads;const urls=await page.evaluate(async url=>Promise.all([window.IMPEConvocatoriasV5.acortarRegistro(url),window.IMPEConvocatoriasV5.acortarRegistro(url)]),long);
 check(helperLoads===before+1&&urls.every(url=>url===short),variant+': solicitudes simultáneas comparten una carga');
 check(errors.length===0,variant+': sin excepciones JavaScript: '+errors.join('; '));
 await context.close();
}console.log('PASS '+count+' comprobaciones de carga y generadores');}finally{await browser.close();}})().catch(error=>{console.error(error.stack);process.exitCode=1;});
