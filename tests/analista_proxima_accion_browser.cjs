const fs=require('fs'),cp=require('child_process'),assert=require('assert/strict'),{chromium}=require('playwright');
const view=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/denue_paginado_vista.php'],{encoding:'utf8'}).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'');
const js=fs.readFileSync('public/javascript/seguimiento_interacciones.js','utf8');
const html='<!doctype html><style>.d-none{display:none!important}</style>'+view+'<script>'+js+'</script>';
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
const page=await browser.newPage(),errors=[],posts=[];page.on('pageerror',e=>errors.push(e.message));
await page.route('http://agenda.test/**',r=>{
 if(r.request().method()==='POST'){posts.push(r.request().postData());return r.fulfill({json:{ok:true,interaccion:{id:321,notas:'Próxima acción: Volver a llamar'}}});}
 return r.fulfill(r.request().url().includes('action=')?{json:{ok:true,resumen:{}}}:{contentType:'text/html',body:html});
});
await page.goto('http://agenda.test/');
const select=async result=>page.locator('[data-work-interaction-form] [name=resultado]').evaluate((e,v)=>{e.value=v;e.dispatchEvent(new Event('change'));},result);
for(const verified of [false,true])for(const step of [2,4,8,12]){
 await page.evaluate(({verified,step})=>{
  document.getElementById('offcanvasSeguimientoTrabajo').dataset.flowStep=step;
  document.querySelector('[data-work-verified-status]').textContent=verified?'Datos verificados':'Datos sin verificar';
  document.querySelector('[data-work-verify-contact]').textContent=verified?'Información verificada':'Marcar información como verificada';
  const f=document.querySelector('[data-work-interaction-form]');f.classList.remove('d-none');f.querySelector('[name=canal]').value='LLAMADA';
  document.dispatchEvent(new CustomEvent('impe:flow-updated'));
 },{verified,step});
 await select('SOLICITO_LLAMAR_DESPUES');
 assert.equal(await page.locator('[name=proxima_accion]').inputValue(),'Volver a llamar');
 assert.ok(await page.locator('[name=proxima_accion]').isVisible(),'Próxima acción visible en paso '+step);
 assert.ok(await page.locator('[name=proxima_accion_at]').isVisible());
 assert.equal(await page.locator('[name=proxima_accion_at]').evaluate(e=>e.required&&!e.disabled),true);
 if(verified)assert.ok(await page.locator('[data-call-verification-evidence]').isHidden(),'No cambia regla de datos verificados');
 for(const result of ['SIN_RESPUESTA','BUZON_VOZ','OCUPADO','NUMERO_INCORRECTO','FUERA_SERVICIO']){
  await select(result);assert.equal(await page.locator('[name=proxima_accion_at]').evaluate(e=>e.required&&!e.disabled),true,result+' permite programar');
 }
 await select('OTRO');await page.locator('[name=proxima_accion]').selectOption('Revisar correo');
 assert.equal(await page.locator('[name=proxima_accion_at]').evaluate(e=>e.required&&!e.disabled),true);
 await select('NO_INTERESADO');assert.equal(await page.locator('[name=proxima_accion_at]').isDisabled(),true);assert.equal(await page.locator('[name=proxima_accion_at]').inputValue(),'');
}
await select('SOLICITO_LLAMAR_DESPUES');
await page.evaluate(()=>{
 const f=document.querySelector('[data-work-interaction-form]');f.querySelector('[name=seguimiento_id]').value='10';
 f.querySelector('[name=persona_atendio]').value='Persona de prueba';f.querySelector('[name=fecha_inicio]').value='2026-11-04T12:00';
 f.querySelector('[name=observacion]').value='Pidió volver a llamar';
 f.querySelector('[name=proxima_accion_at]').value='';
 f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
});
await page.waitForTimeout(100);assert.equal(posts.length,0,'No permite guardar callback sin fecha');
await page.locator('[name=proxima_accion_at]').fill('2099-01-02T11:30');
await page.locator('[data-work-interaction-form]').evaluate(f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
await page.waitForTimeout(150);assert.equal(posts.length,1,'Guarda programación avanzada');
assert.ok(posts[0].includes('proxima_accion')&&posts[0].includes('Volver a llamar')&&posts[0].includes('2099-01-02T11:30'),'Envía acción y fecha juntas al servidor');
assert.deepEqual(errors,[]);
console.log('PASS panel real: verificados/sin verificar, pasos 2/4/8/12, resultados, acción y fecha requeridas, no interesado y POST avanzado completo');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
