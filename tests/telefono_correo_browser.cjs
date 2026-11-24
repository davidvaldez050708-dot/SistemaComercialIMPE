'use strict';
const fs=require('node:fs'),cp=require('node:child_process'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('../../browser-legacy-deps/node_modules/playwright');
const source=n=>{const p=(process.env.VALIDACION_STAGE||'tmp/validacion-casillas-stage/')+'public/javascript/'+n;return fs.readFileSync(fs.existsSync(p)?p:'tmp/telefono-correo-stage/public/javascript/'+n,'utf8');};
(async()=>{const browser=await chromium.launch({headless:true,channel:'chrome'});
try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',r=>r.fulfill({status:200,contentType:'text/html',body:'<base href="http://regla.test/">'}));await page.goto('http://regla.test/');
 await page.setContent(`<div id="offcanvasSeguimientoTrabajo"></div><span data-work-phone>5551234567</span><span data-work-email>—</span><span data-work-contact-name>Diana</span><form data-work-interaction-form><input name="seguimiento_id" value="1"><div class="row"><div class="col-12"><select name="canal"><option>LLAMADA</option></select><select name="resultado"><option value="">Selecciona</option><option value="CONTACTO_CORRECTO">Correcto</option><option value="CONTACTO_REFERIDO">Referido</option></select></div><div class="col-12"><select name="proxima_accion"><option value="">Sin siguiente accion</option></select></div><div class="col-12"><label for="work_next_action_date">Fecha</label><input name="proxima_accion_at" id="work_next_action_date"></div><div class="col-12"><textarea name="observacion"></textarea></div><input name="persona_atendio" value="Diana"><div data-call-verification-evidence><input type="checkbox" name="verificacion_telefono_confirmado" value="1"><input type="checkbox" name="verificacion_correo_confirmado" value="1"><input type="checkbox" name="verificacion_contacto_confirmado" value="1"><p data-call-verification-note></p></div></div></form>`);
 await page.evaluate(()=>{window.IMPE_CURRENT_USER_ID=30;window.enviados=[];});
 await page.addScriptTag({content:source('seguimiento_interacciones.js')});await page.evaluate(()=>document.dispatchEvent(new Event('DOMContentLoaded')));
 await page.selectOption('[name="resultado"]','CONTACTO_CORRECTO');
 await page.fill('[name="nuevo_correo_contacto"]','nuevo@example.org');
 assert.equal(await page.locator('[name="verificacion_correo_confirmado"]').isDisabled(),false,'se puede confirmar el correo nuevo');
 await page.evaluate(()=>document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.enviados.push(Object.fromEntries(new FormData(e.currentTarget)));}));
 const enviar=()=>page.evaluate(()=>document.querySelector('form').requestSubmit());
 await enviar();assert.equal(await page.evaluate(()=>enviados.length),0);
 await page.check('[name="verificacion_telefono_confirmado"]');await enviar();assert.equal(await page.evaluate(()=>enviados.length),0);
 await page.check('[name="verificacion_correo_confirmado"]');await enviar();assert.equal(await page.evaluate(()=>enviados.length),1,'se puede corregir una captura rechazada y guardar');
 assert.deepEqual(errors,[]);

 // Auricular/transferida: formulario real y su manejador real de submit.
 const file=path.resolve('app/views/telefonia/resultado_analista_form.php').replaceAll('\\','/');
 const html=cp.execFileSync('C:/xampp/php/php.exe',[],{input:`<?php define('BASE_URL','http://regla.test/');$esc=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};$_SESSION=['csrf_token'=>'prueba'];$estadosEntrantes=[];require '${file}';`,encoding:'utf8'});
 await page.goto('http://regla.test/');await page.setContent(`<section data-linkage-incoming data-current-user="30" data-save-url="/guardar"></section>${html}`);
 await page.evaluate(()=>{window.enviados=[];window.bootstrap={Modal:class{static getOrCreateInstance(){return new this();}show(){}hide(){}}};window.fetch=async(url,opt)=>{if(opt?.method==='POST')enviados.push(Object.fromEntries(opt.body));return {ok:false,json:async()=>({ok:false,mensaje:'Fallo simulado: captura conservada'})};};});
 await page.addScriptTag({content:source('seguimiento_entrantes_analista.js')});
 await page.evaluate(()=>{const f=document.querySelector('form');f.elements.decision.value='existente';f.elements.decision.dispatchEvent(new Event('change'));f.elements.seguimiento_id.add(new Option('Institucion propia','1'));f.elements.seguimiento_id.value='1';f.elements.persona_atendio.value='Diana';f.elements.pbx_call_id.value='out_regla_prueba';f.elements.resultado.value='CONTACTO_CORRECTO';f.elements.resultado.dispatchEvent(new Event('change'));});
 const enviarEntrante=async()=>{await page.evaluate(()=>document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));await page.waitForTimeout(40);};
 await enviarEntrante();assert.equal(await page.evaluate(()=>enviados.length),0);
 await page.check('[name="verificacion_telefono_confirmado"]');await enviarEntrante();assert.equal(await page.evaluate(()=>enviados.length),0);
 await page.check('[name="verificacion_correo_confirmado"]');await enviarEntrante();assert.equal(await page.evaluate(()=>enviados.length),1);
 assert.deepEqual(errors,[]);
 console.log('OK: ambos formularios bloquean cero/una casilla; ambas permiten enviar; correo nuevo habilita confirmacion y se conserva captura ante error.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
