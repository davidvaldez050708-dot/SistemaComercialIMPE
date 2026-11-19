const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const read=p=>fs.readFileSync(p,'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 try {
  const page=await browser.newPage({viewport:{width:1000,height:900}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',r=>r.fulfill({contentType:'text/html',body:`<main id="offcanvasSeguimientoTrabajo" data-flow-seguimiento-id="1" data-flow-step="1">
   <button data-work-toggle-interaction>Abrir</button><form data-work-interaction-form><div class="row">
   <input type="hidden" name="seguimiento_id" value="1"><div class="col-12"><select name="canal"><option value="LLAMADA">Llamada</option></select></div>
   <div class="col-12"><select name="resultado"><option value="">Elige</option><option>OCUPADO</option><option>SIN_RESPUESTA</option><option>NUMERO_INCORRECTO</option><option>NO_INTERESADO</option><option>OTRO</option></select></div>
   <div class="col-12"><input name="persona_atendio" value="Contacto sintético"></div>
   <div class="col-12"><select name="proxima_accion"><option value="">Sin próxima acción</option><option>Volver a llamar</option><option>Enviar WhatsApp</option><option>Revisar correo</option><option>Preparar oficio</option><option>Investigar nuevo contacto</option></select></div>
   <div class="col-12"><label for="work_next_action_date">Fecha próxima acción</label><input id="work_next_action_date" type="datetime-local" name="proxima_accion_at"></div>
   <div class="col-12"><textarea name="observacion"></textarea></div></div><button type="submit">Guardar interacción</button></form></main>`}));
  await page.goto('https://crm.test/');
  await page.evaluate(()=>{
   window.IMPE_CURRENT_USER_ID=44;window.IMPE_CURRENT_ROLE_ID=4;
   window.IMPE_TELEPHONY_PERSISTENT={subscribe(){},getState:()=>null,openContext(){}};
   window.bootstrap={Offcanvas:{getOrCreateInstance:()=>({show(){}})}};
   sessionStorage.setItem('impe:analista:registro-obligatorio:44',JSON.stringify({user:44,token:'call-fixture-123',seguimientoId:1,finished:true,interactionId:0,values:{}}));
  });
  for(const p of ['seguimiento_interacciones.js','seguimiento_llamada_registro_obligatorio.js'])await page.addScriptTag({content:read('public/javascript/'+p)});
  await page.evaluate(()=>document.dispatchEvent(new Event('DOMContentLoaded')));
  await page.waitForTimeout(350);
  const result=page.locator('[name="resultado"]'),action=page.locator('[name="proxima_accion"]'),date=page.locator('[name="proxima_accion_at"]');
  async function setDate(value){await date.fill(value);await page.locator('[name="observacion"]').press('Space');}
  async function choose(field,value){
   const index=await field.evaluate((el,v)=>Array.from(el.options).findIndex(o=>o.value===v),value);
   assert(index>=0);await field.focus();await field.press('Home');
   for(let i=0;i<index;i++)await field.press('ArrowDown');
   await field.press('Enter');
  }
  await choose(result,'OCUPADO');assert.equal(await action.inputValue(),'Volver a llamar');
  await choose(action,'Enviar WhatsApp');await setDate('2030-11-20T10:30');
  for(let i=0;i<3;i++){
   await page.evaluate(()=>document.dispatchEvent(new CustomEvent('impe:flow-updated')));
   await page.waitForTimeout(50);
   assert.equal(await action.inputValue(),'Enviar WhatsApp','la restauración conserva la elección manual');
   assert.equal(await date.inputValue(),'2030-11-20T10:30','la fecha elegida no se pierde');
  }
  assert.equal(await date.getAttribute('required'),'');
  await page.evaluate(()=>document.querySelector('[name="resultado"]').dispatchEvent(new Event('change',{bubbles:true})));
  assert.equal(await action.inputValue(),'Enviar WhatsApp','un aviso repetido del mismo resultado no vuelve a sugerir');
  await choose(result,'SIN_RESPUESTA');assert.equal(await action.inputValue(),'Volver a llamar','un resultado realmente nuevo sí actualiza la sugerencia');
  await choose(action,'Revisar correo');await setDate('2030-11-21T11:45');
  await page.evaluate(()=>document.querySelector('form').reset());await page.waitForTimeout(80);
  assert.equal(await action.inputValue(),'Revisar correo','se recupera el borrador después de reset');
  assert.equal(await date.inputValue(),'2030-11-21T11:45');
  await choose(action,'');
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('impe:flow-updated')));await page.waitForTimeout(60);
  assert.equal(await action.inputValue(),'');assert.equal(await date.inputValue(),'');assert.equal(await date.isDisabled(),true);
  await choose(result,'OCUPADO');await choose(action,'Enviar WhatsApp');await setDate('2030-11-22T09:15');
  await page.evaluate(()=>{
   document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.saved=Object.fromEntries(new FormData(e.target))});
   document.querySelector('form').requestSubmit();
  });
  assert.equal(await page.evaluate(()=>saved.proxima_accion),'Enviar WhatsApp');
  assert.equal(await page.evaluate(()=>saved.proxima_accion_at),'2030-11-22T09:15');
  await page.evaluate(()=>{
   document.querySelector('#offcanvasSeguimientoTrabajo').dataset.flowStep='9';
   document.dispatchEvent(new CustomEvent('impe:flow-updated'));
   window.fetch=async(url,opt)=>{window.advancedSaved=Object.fromEntries(opt.body);return {ok:false,json:async()=>({ok:false,mensaje:'Prueba sin escritura'})}};
  });
  await page.waitForTimeout(60);
  assert.equal(await action.inputValue(),'Enviar WhatsApp');
  await page.evaluate(()=>document.querySelector('form').requestSubmit());
  await page.waitForFunction(()=>Boolean(window.advancedSaved));
  assert.equal(await page.evaluate(()=>advancedSaved.proxima_accion),'Enviar WhatsApp');
  assert.equal(await page.evaluate(()=>advancedSaved.proxima_accion_at),'2030-11-22T09:15');
  assert.deepEqual(errors,[]);
  console.log('CHECK_OK navegador: ocupado sugiere, acción/fecha editables, tres restauraciones, evento repetido, nuevo resultado, reset, sin acción y datos enviados; sin servicios externos.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
