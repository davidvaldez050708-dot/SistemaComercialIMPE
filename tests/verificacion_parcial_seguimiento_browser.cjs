// Formulario de Seguimiento: mismo guardado parcial que el auricular, sin red ni PBX.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),deps=path.resolve(root,'../browser-legacy-deps/node_modules');
const {chromium}=require(path.join(deps,'playwright'));
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://parcial.test/**',r=>r.fulfill({contentType:'text/html',body:'<base href="http://parcial.test/">'}));await page.goto('http://parcial.test/');
 await page.setContent('<div class="toast-container"></div>'+read('tests/fixtures/verificacion_parcial_seguimiento.html'));
 await page.addScriptTag({path:path.join(deps,'bootstrap/dist/js/bootstrap.bundle.min.js')});
 await page.addScriptTag({content:read('public/javascript/seguimiento_interacciones.js')});
 await page.evaluate(()=>{window.sent=[];document.dispatchEvent(new Event('DOMContentLoaded'));document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();sent.push(Object.fromEntries(new FormData(e.currentTarget)));});});
 await page.selectOption('[name=resultado]','CONTACTO_CORRECTO');await page.fill('[name=nuevo_correo_contacto]','nuevo@example.test');
 const submit=()=>page.evaluate(()=>document.querySelector('form').requestSubmit());
 await submit();assert.equal(await page.evaluate(()=>sent.length),1,'Guardar sin confirmaciones');
 await page.check('[name=verificacion_telefono_confirmado]');await submit();assert.equal(await page.evaluate(()=>sent.length),2,'Guardar teléfono parcial');
 await page.check('[name=verificacion_correo_confirmado]');await submit();assert.equal(await page.evaluate(()=>sent.length),3,'Guardar el par');
 assert.equal(await page.evaluate(()=>sent[1].verificacion_telefono_confirmado),'1');assert.equal(await page.evaluate(()=>sent[1].verificacion_correo_confirmado),undefined);
 await page.selectOption('[name=resultado]','NO_INTERESADO');
 assert.equal(await page.locator('[data-call-verification-evidence]').evaluate(e=>e.classList.contains('d-none')),true);
 for(const name of ['telefono','correo','contacto']){assert.equal(await page.locator(`[name=verificacion_${name}_confirmado]`).isChecked(),false);assert.equal(await page.locator(`[name=verificacion_${name}_confirmado]`).isDisabled(),true);}
 await page.selectOption('[name=resultado]','OCUPADO');assert.equal(await page.locator('[data-call-verification-evidence]').evaluate(e=>e.classList.contains('d-none')),true);
 await page.selectOption('[name=resultado]','CONTACTO_CORRECTO');
 await page.check('[name=verificacion_telefono_confirmado]');await page.fill('[name=persona_atendio]','');await submit();
 assert.equal(await page.evaluate(()=>sent.length),3,'Conservar validación de quién confirmó el dato');
 assert.ok(await page.locator('.toast.system-toast.system-toast-error').count());assert.deepEqual(errors,[]);
 console.log('PASS: Seguimiento guarda cero/una/dos casillas, oculta y limpia resultados no verificables, conserva quién confirmó y usa toast institucional.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
