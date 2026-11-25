// Formulario PHP instalado, Bootstrap y JS reales. Todas las respuestas son sintéticas.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),deps=path.resolve(root,'../browser-legacy-deps/node_modules');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||path.join(deps,'playwright'));
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const template=read('tests/fixtures/errores_resultado_analista_form_20261125.php.txt');
const form=cp.execFileSync(process.env.PHP_BINARY||'C:/xampp/php/php.exe',[],{input:`<?php define('BASE_URL','http://resultado.test/');$_SESSION=['csrf_token'=>'csrf-sintetico'];$esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');$estadosEntrantes=[['id'=>9,'nombre'=>'Territorio sintético']]; ?>${template}`,encoding:'utf8'});
const bootstrap=fs.readFileSync(path.join(deps,'bootstrap/dist/js/bootstrap.bundle.min.js'),'utf8');
const css=fs.readFileSync(path.join(deps,'bootstrap/dist/css/bootstrap.min.css'),'utf8')+read('public/css/global.css')+read('public/css/dashboard.css');
const source=read(process.env.ANALISTA_SOURCE||'public/javascript/seguimiento_entrantes_analista.js');
const html=`<!doctype html><meta charset="utf-8"><style>${css}</style><main data-linkage-incoming data-current-user="30" data-save-url="http://resultado.test/save"><button data-call-id="out_12345678901234567890123456789012" data-answer="1" data-source="PBX" data-phone="5550000000" data-duration="10">Registrar</button>${form}</main><script>${bootstrap}</script><script>${source}</script>`;
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BINARY||'C:/Program Files/Google/Chrome/Application/chrome.exe'});try{
 for(const scenario of ['falta_resultado','correo_invalido','sin_confirmaciones','servidor_rechaza','guardar_valido','solo_telefono','no_interesado','sdk_sin_confirmar'].filter(s=>!process.env.ANALISTA_SCENARIO||s===process.env.ANALISTA_SCENARIO)){
  const page=await browser.newPage(),posts=[],errors=[];page.setDefaultTimeout(5000);page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://resultado.test/**',route=>{
   if(route.request().method()==='POST'){posts.push(route.request().postData());return route.fulfill({status:scenario==='servidor_rechaza'?422:200,json:scenario==='servidor_rechaza'?{ok:false,mensaje:'Fallo sintético: revisa el resultado de esta llamada.'}:{ok:true,resultado:{}}});}
   return route.fulfill({contentType:'text/html',body:html});
  });
  await page.goto('http://resultado.test/');
  if(scenario==='sdk_sin_confirmar')await page.locator('[data-call-id]').evaluate(e=>{e.dataset.source='SDK_WEBRTC';e.dataset.sdkReference='sdk-sintetico-12345678';});
  await page.locator('[data-call-id]').click();
  const modal=page.locator('#modalLlamadaEntranteAnalista');await modal.waitFor({state:'visible'});
  await page.locator('[name=decision]').selectOption('nueva');
  await page.locator('[name=nombre_entidad]').fill('Institución sintética');
  await page.locator('[name=estado_id]').selectOption('9');
  await page.locator('[name=correo_capturado]').fill(scenario==='correo_invalido'?'correo-incompleto':'contacto@example.test');
  if(scenario!=='falta_resultado')await page.locator('[name=resultado]').selectOption(scenario==='no_interesado'?'NO_INTERESADO':'CONTACTO_CORRECTO');
  if(!['falta_resultado','sin_confirmaciones','no_interesado'].includes(scenario)){
   await page.locator('[name=verificacion_telefono_confirmado]').check();
   if(scenario!=='solo_telefono')await page.locator('[name=verificacion_correo_confirmado]').check();
  }
  await page.locator('[data-incoming-submit]').click();
  if(scenario==='no_interesado'){assert.equal(await page.locator('[data-incoming-evidence]').isVisible(),false);assert.equal(await page.locator('[name=verificacion_telefono_confirmado]').isDisabled(),true);}
  if(['guardar_valido','sin_confirmaciones','solo_telefono','no_interesado'].includes(scenario)){
   await page.waitForTimeout(200);assert.equal(posts.length,1,'una sola solicitud al corregir los datos');
  }else{
   const error=page.locator('[data-incoming-error]');await error.waitFor({state:'visible'});
   const text=await error.innerText();
   if(scenario==='falta_resultado')assert.match(text,/Resultado/);
   if(scenario==='correo_invalido')assert.match(text,/Correo institucional/);
   assert.equal(await error.evaluate(e=>e.classList.contains('system-toast')&&e.classList.contains('system-toast-error')),true,'error con estilo toast institucional');
   assert.equal(await error.evaluate(e=>!!e.closest('.modal')),true,'toast visible dentro del modal superpuesto');
   if(scenario==='sdk_sin_confirmar')assert.match(text,/Revisa/);
   if(scenario==='servidor_rechaza')assert.match(text,/Fallo sintético/);
   assert.equal(posts.length,scenario==='servidor_rechaza'?1:0);
   assert.equal(await page.locator('[name=nombre_entidad]').inputValue(),'Institución sintética','captura conservada');
   assert.equal(await page.locator('[data-incoming-submit]').isDisabled(),false,'permite corregir y reintentar');
   if(scenario==='falta_resultado'){
    await page.waitForTimeout(2600);
    await page.locator('[data-incoming-submit]').click();
    await page.waitForTimeout(1900);
    assert.equal(await error.isVisible(),true,'Un error nuevo reinicia los 4200 ms');
    await error.waitFor({state:'hidden',timeout:3000});
    assert.equal(await page.locator('[name=nombre_entidad]').inputValue(),'Institución sintética','Ocultar el toast conserva la captura');
    await page.locator('[data-incoming-submit]').click();await error.waitFor({state:'visible'});
    await error.locator('button').click();assert.equal(await error.isVisible(),false,'Cierre manual inmediato');
   }
  }
  if(process.env.ANALISTA_SCREENSHOT)await page.screenshot({path:process.env.ANALISTA_SCREENSHOT});
  assert.deepEqual(errors,[]);console.log('PASS '+scenario);await page.close();
 }
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
