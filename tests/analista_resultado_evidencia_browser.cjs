const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'../../browser-legacy-deps/node_modules/playwright');
const source=fs.readFileSync(path.join(__dirname,'../public/javascript/seguimiento_llamada_zadarma.js'),'utf8');
const helper=source.slice(source.indexOf('        const reglasPorFormulario'),source.indexOf('        const usuarioId ='));
const styles=source.slice(source.indexOf("        if (!document.getElementById('impe-call-result-rules-style'))"),source.indexOf('        const vincularUrl ='));
assert.ok(helper.includes('function prepararResultadoConTelefonia'));
const ref='out_'+'a'.repeat(32),other='out_'+'b'.repeat(32);
const human=['CONTACTO_CORRECTO','CONTACTO_REFERIDO','CONTACTO_INCORRECTO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO'];
const metadata=reference=>({pbx_call_id:reference,seguimiento_id:41,call_token:'sesion-'+reference});
const rules=(reference,options,verified=false)=>({pbx_call_id:reference,opciones:options,permite_verificar:verified,etiqueta:verified?'Conexión confirmada':'Línea ocupada'});
const options=['','SIN_RESPUESTA','BUZON_VOZ','CONTACTO_CORRECTO','CONTACTO_REFERIDO','CONTACTO_INCORRECTO',
 'SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO','FUERA_SERVICIO','NUMERO_INCORRECTO','OTRO'];
const html=`<!doctype html><meta charset="utf-8"><style>body{margin:16px;background:#f5f7fb;font-family:Arial,sans-serif}#offcanvasSeguimientoTrabajo{max-width:480px;padding:18px;background:#fff;border:1px solid #e0e6f0;border-radius:14px}</style><div id="offcanvasSeguimientoTrabajo"><form data-work-interaction-form>
 <div class="row"></div><select name="resultado">${options.map(o=>`<option value="${o}">${o||'Selecciona'}</option>`).join('')}</select>
 <input name="origen_llamada" value="ZADARMA"><input name="seguimiento_id" value="41">
 <input name="persona_atendio" value="Borrador conservado"><input name="verificacion_telefono_confirmado" type="checkbox" checked>
 <button type="submit">Guardar</button></form></div><script>
 window.toasts=[];window.saved=[];const offcanvas=document.getElementById('offcanvasSeguimientoTrabajo');
 const mostrarToast=(message,error)=>window.toasts.push({message,error});
 const form=offcanvas.querySelector('form');
 form.addEventListener('change',()=>{form.elements.verificacion_telefono_confirmado.disabled=!${JSON.stringify(human)}.includes(form.elements.resultado.value);});
 form.addEventListener('submit',event=>{event.preventDefault();window.saved.push(Object.fromEntries(new FormData(form)));});
 ${styles}
 ${helper}
 window.prepare=metadata=>prepararResultadoConTelefonia(form,metadata);
 </script>`;
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  async function setup(){
   const page=await browser.newPage({viewport:{width:520,height:720}});page.setDefaultTimeout(5000);const errors=[],pending=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('http://analista-reglas.test/**',async route=>{
    if(new URL(route.request().url()).pathname==='/')return route.fulfill({body:html,contentType:'text/html'});
    assert.equal(route.request().method(),'GET');
    assert.equal(new URL(route.request().url()).searchParams.get('seguimiento_id'),'41');
    const response=await new Promise(resolve=>pending.push({resolve,url:route.request().url()}));
    await route.fulfill(response);
   });
   await page.goto('http://analista-reglas.test/');
   const prepare=async r=>{await page.evaluate(m=>window.prepare(m),metadata(r));await page.waitForFunction(()=>document.querySelector('[data-call-result-rules]'));};
   const ack=async (rule,status=200)=>{
    for(let n=0;!pending.length&&n<40;n++)await page.waitForTimeout(10);
    assert.ok(pending.length);pending.shift().resolve({status,json:status===200?{ok:true,reglas:rule}:{ok:false,mensaje:'Telefonía pendiente'}});
   };
   return {page,errors,pending,prepare,ack};
  }
  for(const [code,allowed] of [['busy',['OCUPADO']],['no-answer',['SIN_RESPUESTA']],['canceled',['OTRO']],['failed',['FUERA_SERVICIO','NUMERO_INCORRECTO','OTRO']]]){
   const t=await setup();const p=t.page;await t.prepare(ref);
   assert.equal(await p.locator('button[type=submit]').isDisabled(),true);
   assert.equal(await p.locator('.impe-call-result-retry').isHidden(),true);
   await p.evaluate(()=>document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
   assert.equal(await p.evaluate(()=>window.saved.length),0);
   await t.ack(rules(ref,allowed));await p.waitForFunction(()=>!document.querySelector('button[type=submit]').disabled);
   assert.equal(await p.locator('.impe-call-result-retry').isHidden(),true);
   assert.deepEqual(await p.locator('.impe-call-result-card').evaluate(el=>({background:getComputedStyle(el).backgroundColor,radius:getComputedStyle(el).borderRadius})),{background:'rgb(245, 248, 253)',radius:'12px'});
   if(code==='busy'){
    const output=path.join(__dirname,'../tmp/analista-aviso-confirmado.png');
    await p.locator('[data-call-result-rules]').screenshot({path:output});
    await p.setViewportSize({width:390,height:720});
    assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
   }
   for(const result of human)assert.equal(await p.locator(`option[value="${result}"]`).isDisabled(),true);
   assert.equal(await p.locator('[name=verificacion_telefono_confirmado]').isChecked(),false);
   assert.equal(await p.locator('[name=verificacion_telefono_confirmado]').isDisabled(),true);
   // Manipular el valor tampoco permite pasar el guard de captura.
   await p.evaluate(()=>{document.querySelector('[name=resultado]').value='CONTACTO_CORRECTO';document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));});
   assert.equal(await p.evaluate(()=>window.saved.length),0);
   await p.selectOption('[name=resultado]',allowed[0]);await p.locator('button[type=submit]').click();
   const saved=await p.evaluate(()=>window.saved);
   assert.equal(saved.length,1);assert.equal(saved[0].llamada_pbx_id,ref);assert.equal(saved[0].resultado,allowed[0]);
   assert.equal(saved[0].persona_atendio,'Borrador conservado');assert.ok(!('verificacion_telefono_confirmado' in saved[0]));
   await p.evaluate(()=>document.dispatchEvent(new CustomEvent('impe:interaction-exact-id-ready',{detail:{seguimientoId:41,interaccionId:25}})));
   assert.equal(await p.locator('[name=llamada_pbx_id]').count(),0);
   assert.equal(await p.locator('option[value=CONTACTO_CORRECTO]').isDisabled(),false);
   assert.deepEqual(t.errors,[]);await p.close();console.log('PASS: '+code+' limita opciones y verificaciones antes del POST.');
  }
  let t=await setup(),p=t.page;await t.prepare(ref);await t.ack(rules(ref,[...human,'BUZON_VOZ','OTRO'],true));
  await p.waitForFunction(()=>!document.querySelector('button[type=submit]').disabled);
  await p.selectOption('[name=resultado]','CONTACTO_CORRECTO');await p.locator('[name=verificacion_telefono_confirmado]').check();
  await p.selectOption('[name=resultado]','BUZON_VOZ');
  assert.equal(await p.locator('[name=verificacion_telefono_confirmado]').isChecked(),false);
  assert.equal(await p.locator('[name=verificacion_telefono_confirmado]').isDisabled(),true);
  assert.deepEqual(t.errors,[]);await p.close();console.log('PASS: contestada permite distinguir persona/buzón; buzón desactiva verificaciones.');

  t=await setup();p=t.page;await t.prepare(ref);await t.ack(null,503);
  await p.waitForFunction(()=>document.querySelector('[data-call-result-rules] p').textContent==='Telefonía pendiente');
  assert.equal(await p.locator('button[type=submit]').isDisabled(),true);
  assert.equal(await p.locator('[name=persona_atendio]').inputValue(),'Borrador conservado');
  assert.equal(await p.locator('.impe-call-result-retry').isVisible(),true);
  assert.equal(await p.locator('.impe-call-result-retry').evaluate(el=>getComputedStyle(el).borderRadius),'8px');
  await p.locator('[data-call-result-rules]').screenshot({path:path.join(__dirname,'../tmp/analista-aviso-pendiente.png')});
  await p.locator('.impe-call-result-retry').click();await t.ack(rules(ref,['SIN_RESPUESTA']));
  await p.waitForFunction(()=>!document.querySelector('button[type=submit]').disabled);
  assert.equal(await p.locator('.impe-call-result-retry').isHidden(),true);
  await p.close();console.log('PASS: error conserva borrador; botón institucional sólo visible al faltar confirmación; reconsulta la misma llamada.');

  t=await setup();p=t.page;await t.prepare(ref);await t.prepare(other);
  await t.ack(rules(ref,['OCUPADO']));await p.waitForTimeout(30);
  assert.equal(await p.locator('button[type=submit]').isDisabled(),true);
  await t.ack(rules(other,['SIN_RESPUESTA']));await p.waitForFunction(()=>!document.querySelector('button[type=submit]').disabled);
  assert.equal(await p.locator('[name=llamada_pbx_id]').inputValue(),other);
  assert.equal(await p.locator('[name=resultado]').inputValue(),'SIN_RESPUESTA');
  assert.deepEqual(t.errors,[]);await p.close();console.log('PASS: una respuesta tardía no cambia el formulario de otra llamada.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
