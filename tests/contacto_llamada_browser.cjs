'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require('../../browser-legacy-deps/node_modules/playwright');
const stage=process.env.CONTACTO_STAGE || (fs.existsSync('tmp/registro-unificado-stage/')?'tmp/registro-unificado-stage/':'');
const source=name=>fs.readFileSync((fs.existsSync(stage+name)?stage:'')+name,'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try {
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',r=>r.fulfill({status:200,contentType:'text/html',body:'<html><head><base href="http://crm.test/"></head><body></body></html>'}));
  await page.goto('http://crm.test/');
  await page.setContent(`<div id="offcanvasSeguimientoTrabajo"></div><strong data-work-email></strong><strong data-work-contact-name></strong><strong data-work-title></strong><button data-work-email-button disabled></button><form data-work-contact-form><input name="correo_verificado"></form>
   <form data-work-interaction-form><input name="seguimiento_id" value="1"><div class="row"><div class="col-12"><select name="canal"><option>LLAMADA</option></select><select name="resultado"><option value="">Selecciona resultado</option><option value="CONTACTO_CORRECTO">Contacto correcto</option><option value="OCUPADO">Ocupado</option><option value="CONTACTO_REFERIDO">Referido</option></select></div><div class="col-12"><select name="proxima_accion"><option value="">Sin proxima accion</option></select></div><div class="col-12"><label for="work_next_action_date">Fecha</label><input name="proxima_accion_at" id="work_next_action_date"></div><div class="col-12"><textarea name="observacion"></textarea></div><input name="persona_atendio" value="Nombre anterior"></div></form>`);
  await page.evaluate(()=>{window.IMPE_CURRENT_USER_ID=30;window.fetch=async()=>({ok:true,json:async()=>({ok:true,borrador:{saved_at:Date.now(),valores:{nombre:'Institucion prueba',persona:'Persona que atendio',correo:'nuevo@example.org',contacto_nombre:'Contacto institucional',contacto_cargo:'Vinculacion',notas:'Nota de prueba'}}})});});
  await page.addScriptTag({content:source('public/javascript/telefonia_atencion.js')});
  await page.addScriptTag({content:source('public/javascript/seguimiento_interacciones.js')});
  await page.evaluate(()=>document.dispatchEvent(new Event('DOMContentLoaded')));
  await page.waitForSelector('[name="nuevo_correo_contacto"]');
  assert.equal(await page.locator('[data-work-referred-contact-wrapper]').evaluate(e=>e.classList.contains('d-none')),false);
  assert.equal(await page.locator('[name="resultado"]').inputValue(),'');
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('impe:call-result-form-opened',{detail:{form:document.querySelector('form[data-work-interaction-form]'),ids:['out_sintetico_1']}})));
  await page.waitForFunction(()=>document.querySelector('[name="nuevo_correo_contacto"]').value==='nuevo@example.org');
  assert.equal(await page.locator('[name="nombre_entidad_actualizado"]').inputValue(),'Institucion prueba');
  assert.equal(await page.locator('[name="nuevo_contacto_nombre"]').inputValue(),'Persona que atendio');
  assert.equal(await page.locator('[name="nuevo_contacto_nombre"]').evaluate(e=>e.closest('.col-12').hidden),true);
  assert.equal(await page.locator('.linkage-referred-contact-box').count(),0);
  await page.selectOption('[name="resultado"]','CONTACTO_REFERIDO');
  assert.equal(await page.locator('[name="nuevo_contacto_nombre"]').inputValue(),'Contacto institucional');
  await page.fill('[name="nuevo_contacto_nombre"]','Persona referida');
  await page.selectOption('[name="resultado"]','CONTACTO_CORRECTO');
  assert.equal(await page.locator('[name="nuevo_contacto_nombre"]').inputValue(),'Persona que atendio');
  await page.selectOption('[name="resultado"]','CONTACTO_REFERIDO');
  assert.equal(await page.locator('[name="nuevo_contacto_nombre"]').inputValue(),'Persona referida');
  await page.selectOption('[name="resultado"]','CONTACTO_CORRECTO');
  assert.equal(await page.locator('[name="persona_atendio"]').inputValue(),'Persona que atendio');
  assert.equal(await page.locator('[name="nuevo_correo_contacto"]').isDisabled(),false);
  const manual=await page.evaluate(async()=>{
   let responder;window.fetch=()=>new Promise(resolve=>{responder=resolve;});
   const f=document.querySelector('[data-work-interaction-form]');
   document.dispatchEvent(new CustomEvent('impe:call-result-form-opened',{detail:{form:f,ids:['out_otro_borrador']}}));
   f.elements.persona_atendio.value='Editado mientras carga';f.elements.persona_atendio.dispatchEvent(new Event('input',{bubbles:true}));
   responder({ok:true,json:async()=>({ok:true,borrador:{saved_at:Date.now(),valores:{persona:'Nombre remoto'}}})});
   await new Promise(resolve=>setTimeout(resolve,20));return f.elements.persona_atendio.value;
  });
  assert.equal(manual,'Editado mientras carga');
  await page.selectOption('[name="resultado"]','OCUPADO');
  assert.equal(await page.locator('[name="nuevo_correo_contacto"]').inputValue(),'nuevo@example.org');
  assert.equal(await page.locator('[name="nuevo_correo_contacto"]').isDisabled(),true);
  await page.selectOption('[name="resultado"]','CONTACTO_CORRECTO');
  const data=await page.evaluate(()=>{const f=document.querySelector('[data-work-interaction-form]'),d=new FormData(f);window.IMPE_CALL_DRAFTS.actualizarFicha(d);return Object.fromEntries(d);});
  assert.equal(data.actualizar_datos_llamada,'1');assert.equal(data.nuevo_correo_contacto,'nuevo@example.org');
  assert.equal(await page.locator('[data-work-email]').innerText(),'nuevo@example.org');
  assert.equal(await page.locator('[data-work-email-button]').isDisabled(),false);
  assert.deepEqual(await page.evaluate(()=>{const d=window.IMPE_CALL_DRAFTS;return [d.resultValues({correo_capturado:'viejo@example.org',nuevo_correo_contacto:''}).nuevo_correo_contacto,d.captureValues({nuevo_correo_contacto:'revisado@example.org'}).correo];}),['','revisado@example.org']);
  assert.deepEqual(errors,[]);

  // Formulario real del auricular y transferencias, con la biblioteca de recuperación real.
  const php=`<?php define('BASE_URL','http://crm.test/');$esc=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};$_SESSION=['csrf_token'=>'sintetico'];$estadosEntrantes=[];$esDevolucion=false;require '${path.resolve(fs.existsSync(stage+'app/views/telefonia/resultado_analista_form.php')?stage+'app/views/telefonia/resultado_analista_form.php':'app/views/telefonia/resultado_analista_form.php').replaceAll('\\','/')}';`;
  const html=cp.execFileSync('C:/xampp/php/php.exe',[],{input:php,encoding:'utf8'});
  await page.goto('http://crm.test/');
  await page.setContent(`<section data-linkage-incoming data-current-user="30" data-search-url="/buscar" data-save-url="/guardar"></section>${html}`);
  await page.evaluate(()=>{window.IMPE_CURRENT_USER_ID=30;window.bootstrap={Modal:class{static getOrCreateInstance(){return new this();}constructor(){}show(){}hide(){}}};window.fetch=async()=>({ok:true,json:async()=>({ok:true,borrador:{saved_at:Date.now(),valores:{correo:'transferido@example.org',contacto_nombre:'Contacto transferido',nombre:'Institucion transferida'}}})});});
  await page.addScriptTag({content:source('public/javascript/telefonia_atencion.js')});
  await page.addScriptTag({content:source('public/javascript/seguimiento_entrantes_analista.js')});
  await page.evaluate(()=>{const f=document.querySelector('[data-incoming-form]');f.elements.decision.value='existente';f.elements.resultado.value='CONTACTO_CORRECTO';document.dispatchEvent(new CustomEvent('impe:call-result-form-opened',{detail:{form:f,ids:['out_transferido_sintetico']}}));});
  await page.waitForFunction(()=>document.querySelector('[name="nuevo_correo_contacto"]').value==='transferido@example.org');
  assert.equal(await page.locator('[name="nuevo_correo_contacto"]').isDisabled(),false);
  assert.equal(await page.locator('[name="nombre_entidad_actualizado"]').inputValue(),'Institucion transferida');
  assert.deepEqual(errors,[]);
  console.log('OK: ambos formularios recuperan contacto, conservan captura al cambiar resultado y actualizan correo visible tras guardar.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
