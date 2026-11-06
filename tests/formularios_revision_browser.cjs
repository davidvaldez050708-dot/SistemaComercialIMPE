const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {spawn}=require('node:child_process'),{chromium}=require('playwright');
const root=path.resolve(__dirname,'..'),base='http://127.0.0.1:8131/';
(async()=>{let server,browser;try{
 fs.mkdirSync(path.join(root,'tmp'),{recursive:true});
 const log=fs.openSync(path.join(root,'tmp/formularios-revision-http.log'),'w');
 server=spawn(process.env.PHP_BINARY||'php',['-d','opcache.jit_buffer_size=0','-S','127.0.0.1:8131','-t',root],{cwd:root,env:{...process.env,APP_ENV:'testing'},stdio:['ignore',log,log]});
 for(let i=0;i<80;i++){try{await fetch(base+'public/css/formularios.css');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const bs=process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
 await page.route('https://**/*',r=>r.request().url().includes('bootstrap.min.css')?r.fulfill({path:path.join(bs,'dist/css/bootstrap.min.css'),contentType:'text/css'}):r.request().url().includes('bootstrap.bundle.min.js')?r.fulfill({path:path.join(bs,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'}):r.abort());
 await page.route('**/*municipio*',r=>r.fulfill({json:{ok:true,municipios:[{id:1,nombre:'Cuernavaca'}]}}));
 let posts=0;
 await page.route('**/tests/envio-sintetico',async r=>{posts++;await new Promise(resolve=>setTimeout(resolve,100));return r.fulfill({json:{ok:true,mensaje:'Registro sintético recibido.'}});});
 async function llenar(){
  const vals={nombre:'Roberto',apellido:'García Ruiz',apellidos:'García Ruiz',fecha_nacimiento:'2000-01-01',correo:'sintetico@example.test',correo_confirmacion:'sintetico@example.test',movil:'5512345678',movil_confirmacion:'5512345678',codigo_postal:'62350',numero:'S/N',fecha_inscripcion:'2026-10-20',lugar_laboras:'Empresa Prueba',empresa_labora:'Empresa Prueba',nombre_padres:'Ana García y Carlos Ruiz',calle:'Avenida Principal',ciudad:'Cuernavaca',curp:'GARR000101HDFRRSA8'};
  for(const [name,value] of Object.entries(vals)){const el=page.locator('[name="'+name+'"]');if(await el.count()&&await el.isVisible()&&!(await el.isDisabled()))await el.fill(value);}
  for(const name of ['estado_id','entidad_federativa']){const el=page.locator('select[name="'+name+'"]');if(await el.count())await el.selectOption('1');}
  for(const name of ['municipio_id','municipio']){const el=page.locator('select[name="'+name+'"]');if(await el.count()){await el.locator('option[value="1"]').waitFor({state:'attached'});await el.selectOption('1');}}
  for(const name of ['perfil_interes','perfil_evaluacion','genero']){const el=page.locator('select[name="'+name+'"]');if(await el.count()&&!(await el.isDisabled())){const v=await el.locator('option').evaluateAll(es=>es.find(e=>e.value!=='').value);await el.selectOption(v);}}
  if(await page.locator('[name=contacto_disponibilidad]').count())await page.locator('[name=contacto_disponibilidad]').selectOption('sin_preferencia');
  for(const el of await page.locator('input[type=checkbox][required]:enabled').all())await el.check();
  if(await page.locator('input[type=file]').count())await page.locator('input[type=file]').setInputFiles({name:'pago.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4 prueba')});
 }
 for(const tipo of ['registro','publico','legacy','inscripcion']){
  await page.goto(base+'tests/formularios_revision_fixture.php?tipo='+tipo);
  await page.locator('[data-revision-aviso]').waitFor();
  const submit=page.locator('button[type=submit]');const antes=posts;
  await submit.click();assert.equal(await page.locator('[role=dialog]').count(),0,'campos vacíos conservan validación');assert.equal(posts,antes);
  await llenar();await submit.click();
  try{await page.locator('.formulario-revision-dialogo').waitFor({timeout:5000});}catch(e){throw new Error(tipo+': '+JSON.stringify({errors,invalid:await page.locator('.is-invalid').evaluateAll(es=>es.map(e=>({name:e.name,value:e.value,message:e.validationMessage}))),toast:await page.locator('.system-toast').allTextContents()})+' '+e.message);}
  assert.equal(posts,antes,'no envía antes de confirmar');
  assert.match(await page.locator('.formulario-revision-resumen').innerText(),/Roberto/);
  await page.waitForFunction(()=>{const e=document.querySelector('.formulario-revision-marca img');return e&&e.complete&&e.naturalWidth>0;});
  await page.locator('.formulario-revision-volver').click();assert.equal(await page.locator('[role=dialog]').count(),0);assert.equal(await page.locator('[name=nombre]').inputValue(),'Roberto');assert.equal(posts,antes);
  if(tipo==='inscripcion')assert.equal(await page.locator('input[type=file]').evaluate(e=>e.files[0].name),'pago.pdf');
  await submit.click();await page.locator('[role=dialog]').waitFor();await page.keyboard.press('Escape');assert.equal(posts,antes);
  await submit.click();await page.locator('[role=dialog]').waitFor();
  if(tipo==='inscripcion'){
   await page.locator('.formulario-revision-dialogo').screenshot({path:path.join(root,'tmp/formulario-revision-desktop.png')});
   await page.setViewportSize({width:390,height:844});assert(await page.locator('.formulario-revision-dialogo').evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&e.scrollWidth<=r.width+1;}));await page.locator('.formulario-revision-dialogo').screenshot({path:path.join(root,'tmp/formulario-revision-movil.png')});
   await page.keyboard.press('Tab');await page.keyboard.press('Tab');assert(await page.locator('[role=dialog]').evaluate(e=>e.contains(document.activeElement)));
  }
  await page.locator('.formulario-revision-confirmar').evaluate(e=>{e.click();e.click();});await page.waitForTimeout(350);assert.equal(posts,antes+1,'incluso doble clic produce un envío');
 }
 // Summary never interprets captured text as markup, even for a forged value.
 await page.goto(base+'tests/formularios_revision_fixture.php?tipo=inscripcion');await page.locator('[data-revision-aviso]').waitFor();
 await page.locator('[name=nombre]').fill('<img src=x onerror=alert(1)>');
 await page.evaluate(()=>{void window.FormularioRevision.confirmar(document.querySelector('form'));});
 await page.locator('[role=dialog]').waitFor();
 assert.match(await page.locator('.formulario-revision-resumen').innerText(),/<img src=x/);
 assert.equal(await page.locator('[role=dialog] img').count(),1,'sólo el logo, sin HTML inyectado');
 await page.keyboard.press('Escape');assert.deepEqual(errors,[]);
 const antesFallo=posts;
 await page.route('**/formularios_revision_envio.js?*',r=>r.abort());
 await page.goto(base+'tests/formularios_revision_fixture.php?tipo=inscripcion');await llenar();await page.locator('button[type=submit]').click();
 await page.locator('.system-toast').filter({hasText:'No se pudo cargar la revisión'}).waitFor();
 assert.equal(posts,antesFallo,'fallo del componente no omite la confirmación');assert.equal(await page.locator('[name=nombre]').inputValue(),'Roberto');
 await page.unroute('**/formularios_revision_envio.js?*');
 const logs=fs.readFileSync(path.join(root,'tmp/formularios-revision-http.log'),'utf8');assert(!/PHP (Warning|Fatal)/.test(logs),logs.split('\n').filter(l=>/PHP (Warning|Fatal)/.test(l)).join('\n'));
 console.log('PASS: Registro público/CRM, Inscripción actual/anterior, validación previa, aviso, logo, cancelar/Escape sin perder datos, móvil, foco, resumen seguro y un único envío tras confirmar. Sin SQL.');
}finally{if(browser)await browser.close();if(server)server.kill();}})().catch(e=>{console.error(e);process.exitCode=1;});
