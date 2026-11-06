const assert = require('node:assert/strict');
const {spawn,execFileSync} = require('node:child_process');
const fs=require('node:fs'), path=require('node:path');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
const base='http://127.0.0.1:'+String(process.env.IMPE_TEST_HTTP_PORT||8119)+'/';
const name='impe_test_inscripcion_'+Date.now();
const env={...process.env,IMPE_DB_HOST:process.env.IMPE_DB_HOST||'127.0.0.1:33339',IMPE_DB_USER:process.env.IMPE_DB_USER||'root',IMPE_DB_PASSWORD:process.env.IMPE_DB_PASSWORD||'',IMPE_DB_NAME:name,IMPE_BASE_URL:base,APP_ENV:'testing'};
const run=(action)=>execFileSync(php,['tests/inscripcion_completa_db_fixture.php',action],{cwd:root,env,encoding:'utf8'});
const temp=path.join(root,'tmp');fs.mkdirSync(temp,{recursive:true});
const out=fs.openSync(path.join(temp,'inscripcion-php.log'),'w');
const prefix='GARR000101HDFRRSA';
const alphabet='0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
const digit=(10-[...prefix].reduce((n,c,i)=>n+alphabet.indexOf(c)*(18-i),0)%10)%10;
const valid={nombre:'Roberto',apellidos:'García Ruiz',fecha_nacimiento:'2000-01-01',genero:'H',curp:prefix+digit,nombre_padres:'ANA GARCIA / CARLOS RUIZ',correo:'sintetico@example.test',correo_confirmacion:'sintetico@example.test',movil:'5512345678',movil_confirmacion:'5512345678',calle:'Av. Principal',numero:'S/N',ciudad:'Cuernavaca',codigo_postal:'62350',municipio:'1',entidad_federativa:'1',empresa_labora:'Empresa de prueba',cargo_puesto:'',perfil_evaluacion:'Licenciatura en contaduría A286',fecha_inscripcion:'2026-10-20',confirmacion_identidad:'1',acepta_terminos:'1',acepta_privacidad:'1',programa_slug:'titulacion-ejecutivas'};
const file={name:'pago.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer << /Root 1 0 R >>\n%%EOF')};
(async()=>{
 let browser,server;
 try{
  console.log(run('crear').trim());
  const schemaBackup=fs.mkdtempSync(path.join(require('node:os').tmpdir(),'inscripcion-schema-'));
  const schema=(action)=>execFileSync(php,['tools/habilitar_inscripcion_completa.php','--site',root,'--backup-root',schemaBackup,'--schema',path.join(root,'database/migrations/2026_11_05_inscripcion_completa.sql'),action],{cwd:root,env,encoding:'utf8'});
  assert.equal(run('tabla_completa'),'0');
  assert.match(schema('--check'),/CHECK OK/);
  assert.equal(run('tabla_completa'),'0','check del esquema sólo lee');
  assert.equal(fs.readdirSync(schemaBackup).length,0,'check no escribe respaldo');
  assert.match(schema('--apply'),/APLICADO/);
  assert.equal(run('tabla_completa'),'1');
  assert.equal(Number(JSON.parse(run('conteo')).total),0,'preparación no importa personas');
  assert.equal(fs.readdirSync(schemaBackup).length,1,'respaldo privado de esquema');
  assert.match(schema('--apply'),/YA HABILITADO/);
  assert.match(schema('--check'),/ya preparada/);

  server=spawn(php,['-S',new URL(base).host,'-t',root],{cwd:root,env,stdio:['ignore',out,out]});
  for(let i=0;i<80;i++){try{await fetch(base+'public/css/formularios.css');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const bs=process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
  await page.route('https://**/*',r=>{
   const url=r.request().url();
   if(url.includes('bootstrap.min.css'))return r.fulfill({path:path.join(bs,'dist/css/bootstrap.min.css'),contentType:'text/css'});
   if(url.includes('bootstrap.bundle.min.js'))return r.fulfill({path:path.join(bs,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
   return r.abort();
  });
  const url=base+'ya-estas-inscrito/?programa=titulacion-ejecutivas';
  await page.goto(url);
  assert.equal(await page.locator('h1').textContent(),'Formulario de inscripción');
  assert.equal(await page.locator('.formulario-publico-hero img').count(),2,'mismos dos logotipos que Registro actual');
  assert.equal(await page.locator('.formulario-publico-hero-logo-hlc,.formulario-publico-hero-acuerdo').count(),0,'sin HLC ni bloque de texto A286');
  assert.equal((await page.locator('.formulario-publico-hero').innerText()).includes('Acuerdo'),false);
  assert.equal(await page.locator('[name=perfil_evaluacion] option').count(),11);
  assert.equal(await page.locator('[name=apertura]').count(),0);
  assert.equal(await page.locator('[name=comprobante]').count(),1);
  assert.equal(await page.locator('body').evaluate(el=>el.scrollWidth<=innerWidth+1),true);
  await page.locator('[name=entidad_federativa]').selectOption('1');
  await page.locator('[name=municipio] option[value="1"]').waitFor({state:'attached'});
  assert.equal(await page.locator('[name=municipio] option[value="2"]').count(),0);
  const tokens={csrf_inscripcion:await page.locator('[name=csrf_inscripcion]').inputValue(),solicitud_token:await page.locator('[name=solicitud_token]').inputValue()};
  const post=async(overrides={},upload=file)=>{
   const r=await page.request.post(base+'ya-estas-inscrito/?accion=guardar',{multipart:{...valid,...tokens,...overrides,comprobante:upload}});
   return {status:r.status(),data:await r.json()};
  };
  const before=run('conteo');
  for(const [overrides,status] of [[{curp:'INVALIDA'},422],[{fecha_nacimiento:'1900-01-01'},422],[{movil_confirmacion:'5511111111'},422],[{acepta_privacidad:'0'},422],[{municipio:'2'},422],[{csrf_inscripcion:'falso'},419],[{programa_slug:'ingles'},419]]){
   const r=await post(overrides);assert.equal(r.status,status,JSON.stringify(r));
  }
  assert.equal((await post({}, {name:'virus.pdf',mimeType:'application/pdf',buffer:Buffer.from('<?php echo 1;')})).status,422);
  assert.equal(run('conteo'),before,'invalid data never inserted');
  await page.locator('button[type=submit]').click();
  assert.match(await page.locator('.system-toast').last().textContent(),/Revisa/);
  for(const [key,value] of Object.entries(valid)){
   const el=page.locator('[name="'+key+'"]');
   const type=await el.getAttribute('type');
   if(type==='hidden')continue;
   if(type==='checkbox'){await el.check();continue;}
   if(await el.evaluate(e=>e.tagName==='SELECT')) {if(key!=='entidad_federativa')await el.selectOption(value);}
   else await el.fill(value);
  }
  await page.locator('[name=comprobante]').setInputFiles(file);
  const pending=page.waitForResponse(r=>r.url().includes('accion=guardar'));
  await page.locator('button[type=submit]').click();
  const response=await pending;const saved={status:response.status(),data:await response.json()};
  assert.equal(saved.status,200,JSON.stringify(saved));assert.equal(saved.data.ok,true);
  await page.locator('[data-inscripcion-estado]').filter({hasText:'Inscripción guardada correctamente'}).waitFor();
  assert.match(await page.locator('.system-toast').last().textContent(),/Inscripción registrada correctamente/);
  const retry=await post();assert.equal(retry.data.registro_id,saved.data.registro_id);assert.equal(Number(JSON.parse(run('conteo')).total),1);
  await page.goto(url);tokens.solicitud_token=await page.locator('[name=solicitud_token]').inputValue();
  assert.equal((await post()).status,409,'duplicate CURP/program protected with new token');

  // Experiencia laboral comparte el contrato A286 y aviso de privacidad, pero
  // mantiene sus ocho perfiles propios y un expediente independiente.
  const terminosEjecutivas = await page.locator('.inscripcion-documento').nth(0).textContent();
  const privacidadEjecutivas = await page.locator('.inscripcion-documento').nth(1).textContent();
  await page.goto(base+'ya-estas-inscrito/?programa=titulacion-experiencia-laboral');
  assert.equal(await page.locator('[name=perfil_evaluacion] option').count(),9,
      'ocho perfiles actuales y opción vacía; no se modifica el catálogo');
  assert.equal(await page.locator('button[type=submit]').isEnabled(),true,
      'Experiencia Laboral habilitada con términos y esquema');
  assert.equal((await page.locator('body').innerText()).includes('Formulario en preparación'),false);
  assert.equal((await page.locator('body').innerText()).includes('Pendiente de confirmar el documento'),false);
  assert.equal(await page.locator('.inscripcion-documento').nth(0).textContent(),terminosEjecutivas,
      'ambos programas de titulación muestran el mismo contrato A286');
  assert.equal(await page.locator('.inscripcion-documento').nth(1).textContent(),privacidadEjecutivas,
      'ambos programas usan el mismo aviso de privacidad');
  assert.match(terminosEjecutivas,/5,000\.00/,
      'se conserva el monto original, no se inventa uno nuevo');
  const tokenExperiencia = {
    csrf_inscripcion:await page.locator('[name=csrf_inscripcion]').inputValue(),
    solicitud_token:await page.locator('[name=solicitud_token]').inputValue()
  };
  const enviadaExperiencia = await page.request.post(
    base+'ya-estas-inscrito/?accion=guardar',
    {multipart:{...valid,...tokenExperiencia,
      programa_slug:'titulacion-experiencia-laboral',comprobante:file}}
  );
  assert.equal(enviadaExperiencia.status(),200,await enviadaExperiencia.text());
  assert.equal(Number(JSON.parse(run('conteo')).total),2,
      'una misma CURP de prueba se inscribe en dos programas distintos sin mezclar expedientes');

  // Bachillerato General (Examen Único) conserva su contrato de Educación
  // Media Superior y elimina completamente el selector de Perfil de evaluación.
  await page.goto(base+'ya-estas-inscrito/?programa=bachillerato-286');
  assert.match(await page.locator('.formulario-publico-hero-slogan').innerText(),/Examen Único/);
  assert.equal(await page.locator('[name=perfil_evaluacion]').count(),0);
  assert.equal((await page.locator('body').innerText()).includes('Perfil de evaluación'),false);
  assert.equal(await page.locator('button[type=submit]').isEnabled(),true);
  assert.equal((await page.locator('body').innerText()).includes('Formulario en preparación'),false);
  assert.equal(await page.locator('[name=apertura]').count(),0);
  const bachContrato = await page.locator('.inscripcion-documento').nth(0).textContent();
  const bachPrivacidad = await page.locator('.inscripcion-documento').nth(1).textContent();
  assert.match(bachContrato,/Certificado de Bachillerato/);
  assert.match(bachContrato,/Teopanzolco #98/);
  assert.match(bachPrivacidad,/Teopanzolco No\. 98/);
  assert.notEqual(bachContrato,terminosEjecutivas,
      'contrato Bachillerato distinto de Titulación profesional');
  assert.notEqual(bachPrivacidad,privacidadEjecutivas,
      'aviso Bachillerato original distinto al aviso de Titulación');
  const bachTokens = {
    csrf_inscripcion:await page.locator('[name=csrf_inscripcion]').inputValue(),
    solicitud_token:await page.locator('[name=solicitud_token]').inputValue()
  };
  const {perfil_evaluacion: noUsado,...sinPerfil} = valid;
  const postBach = async(overrides={}) => {
    const r=await page.request.post(base+'ya-estas-inscrito/?accion=guardar',
      {multipart:{...sinPerfil,...bachTokens,programa_slug:'bachillerato-286',...overrides,comprobante:file}});
    return {status:r.status(),data:await r.json()};
  };
  assert.equal((await postBach({perfil_evaluacion:'Licenciatura en derecho A286'})).status,422,
      'el servidor rechaza manipulación con perfil ajeno');
  const bachGuardada = await postBach();
  assert.equal(bachGuardada.status,200,JSON.stringify(bachGuardada));
  assert.equal(bachGuardada.data.ok,true);
  assert.equal(Number(JSON.parse(run('conteo')).total),3,
      'tercer expediente independiente por programa');
  assert.equal(JSON.parse(run('campos_bachillerato')).includes('perfil_evaluacion'),false,
      'el JSON guardado no conserva el campo eliminado');
  const privateDir=path.join(root,'../impe-inscripciones-private');
  const filesBefore=fs.readdirSync(privateDir).sort();run('fallo');
  const secondPrefix='GARR010101HDFRRSA';
  const secondDigit=(10-[...secondPrefix].reduce((n,c,i)=>n+alphabet.indexOf(c)*(18-i),0)%10)%10;
  const failure=await post({curp:secondPrefix+secondDigit,fecha_nacimiento:'2001-01-01'});
  assert.equal(failure.status,503,'fallo en detalle revierte inscripción');
  assert.equal(Number(JSON.parse(run('conteo')).total),3,'sin inscripción parcial');
  assert.deepEqual(fs.readdirSync(privateDir).sort(),filesBefore,'sin comprobante huérfano después de rollback');
  for(const slug of ['ingles','bachillerato-en-2-anos']){
   await page.goto(base+'ya-estas-inscrito/?programa='+slug);
   assert.equal(await page.locator('button[type=submit]').isDisabled(),true);
  }
  await page.goto(base+'tests/inscripcion_completa_fixture.php');
  assert.equal(await page.locator('h1').count(),0,'internal form uses dashboard heading, no public hero');
  assert.equal(await page.locator('.formulario-publico-hero').count(),0);
  assert.match(await page.locator('h2').textContent(),/Ejecutivas/);
  await page.screenshot({path:path.join(temp,'inscripcion-interna-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.locator('body').evaluate(el=>el.scrollWidth<=innerWidth+1),true,'mobile no horizontal overflow');
  await page.screenshot({path:path.join(temp,'inscripcion-interna-mobile.png'),fullPage:true});
  await page.goto(url);await page.screenshot({path:path.join(temp,'inscripcion-publica-mobile.png'),fullPage:true});
  assert.deepEqual(errors,[]);
  console.log('PASS: uploads multipart reales, CURP/consentimiento/CSRF/municipios/programa, deduplicación, cinco enlaces, CRM y móvil. Sólo datos sintéticos.');
 }finally{if(browser)await browser.close();if(server){server.kill();await new Promise(r=>server.once('exit',r));}console.log(run('limpiar').trim());fs.closeSync(out);}
})().catch(e=>{console.error(e);process.exitCode=1;});
