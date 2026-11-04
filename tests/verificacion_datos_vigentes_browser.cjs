const fs=require('fs'),cp=require('child_process'),vm=require('vm'),assert=require('assert/strict'),{chromium}=require('playwright');
const read=p=>fs.readFileSync(p,'utf8');
let view=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/denue_paginado_vista.php'],{encoding:'utf8'}).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'');
const js=read('public/javascript/seguimiento_interacciones.js');
const html='<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>.d-none{display:none!important}</style></head><body>'+view+'<script>'+js+'</script></body></html>';
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let sent=0;
await page.route('http://verif.test/**',r=>r.request().method()==='POST'?(sent++,r.fulfill({json:{ok:true,interaccion:{id:123}}})):r.request().url().includes('action=')?r.fulfill({json:{ok:true,resumen:{}}}):r.fulfill({contentType:'text/html',body:html}));
await page.goto('http://verif.test/');
await page.evaluate(()=>{document.querySelector('[data-work-verified-status]').textContent='Datos verificados';document.querySelector('[data-work-phone]').textContent='+527777123456';document.querySelector('[data-work-email]').textContent='actual@ejemplo.test';document.querySelector('[data-work-contact-name]').textContent='María Pérez';document.querySelector('[data-work-contact-role]').textContent='Dirección';});
for(const step of [4,8,12]){
 await page.evaluate(step=>{document.querySelector('#offcanvasSeguimientoTrabajo').dataset.flowStep=step;document.querySelector('[data-work-interaction-form] [name=canal]').value='LLAMADA';document.querySelector('[name=resultado]').value='CONTACTO_CORRECTO';document.dispatchEvent(new CustomEvent('impe:flow-updated'));},step);
 assert.ok(await page.locator('[data-call-verification-evidence]').isHidden(),'No ofrecer verificación en paso '+step);
 assert.equal(await page.locator('[data-work-interaction-form]').getAttribute('data-verificacion-aplicable'),'false');
 assert.equal(await page.locator('[name=verificacion_telefono_confirmado]').isDisabled(),true);
}
await page.evaluate(()=>{document.querySelector('[name=resultado]').value='CONTACTO_REFERIDO';document.querySelector('[name=resultado]').dispatchEvent(new Event('change'));document.querySelector('[name=nuevo_telefono_contacto]').value='777 712 3456';document.querySelector('[name=nuevo_telefono_contacto]').dispatchEvent(new Event('input'));});
assert.equal(await page.locator('[data-work-interaction-form]').getAttribute('data-verificacion-aplicable'),'false','Mismo teléfono no abre crédito');
await page.locator('[name=nuevo_correo_contacto]').evaluate(e=>{e.value='nuevo@ejemplo.test';e.dispatchEvent(new Event('input'));});
assert.equal(await page.locator('[data-work-interaction-form]').getAttribute('data-verificacion-aplicable'),'true','Dato distinto requiere evidencia propia');
await page.evaluate(()=>{document.querySelector('[data-work-verified-status]').textContent='Datos sin verificar';document.querySelector('[data-work-verify-contact]').textContent='Marcar información como verificada';document.querySelector('[name=resultado]').value='CONTACTO_CORRECTO';document.dispatchEvent(new CustomEvent('impe:flow-updated'));});
assert.equal(await page.locator('[data-call-verification-evidence]').evaluate(e=>e.classList.contains('d-none')),false,'Primera verificación disponible');
assert.deepEqual(errors,[]);
const phone=read('public/javascript/seguimiento_llamada_zadarma.js');const a=phone.indexOf('                    const feedback =');const b=phone.indexOf('                    pendingInteractionFeedback =',a);assert.ok(a>0&&b>a);
const message=vm.runInNewContext('(function(data,pendingInteractionFeedback){'+phone.slice(a,b)+'return finalMessage;})');
const normal=message({verificacion_efectiva:false,hubo_respuesta:true},{resultadoLabel:'Contacto correcto',tieneEvidencia:false,verificacionAplicable:false});
assert.equal(normal,'Llamada registrada · Contacto correcto.');assert.ok(!normal.includes('verificación'));
console.log('PASS UI real: pasos 4/8/12, casillas ocultas/deshabilitadas, dato distinto, primera validación y mensaje normal');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
