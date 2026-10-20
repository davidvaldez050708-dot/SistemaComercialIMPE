const assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'php';
const port = Number(process.env.IMPE_TEST_HTTP_PORT || 8097);
const base = `http://127.0.0.1:${port}/`;
const env = {...process.env, IMPE_BASE_URL: base, APP_ENV:'testing'};
assert.match(env.IMPE_DB_NAME || '', /^impe_test_handly[a-z0-9_]*$/);
const token = execFileSync(php,['tools/preparar_handly_formularios.php','--check'],{cwd:root,env,encoding:'utf8'}).match(/aliado=([a-f0-9]{64})/)[1];
const server = spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{cwd:root,env,stdio:'ignore'});
const url = (action, t=token) => `${base}index.php?controller=formularioPublico&action=${action}${t===null?'':'&aliado='+encodeURIComponent(t)}`;
(async()=>{
 let browser;
 try {
  for(let i=0;i<80;i++){try {await fetch(url('registro'));break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser = await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
  const page=await browser.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  // Assets externos no determinan autorización; no depender de CDN en CI.
  const bootstrap=process.env.BOOTSTRAP_MODULE_PATH || path.join(root,'../browser-deps/node_modules/bootstrap');
  await page.route('https://**/*',r=>{
   if(r.request().url().includes('bootstrap.bundle.min.js')) return r.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
   if(r.request().url().includes('bootstrap.min.css')) return r.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'});
   return r.abort();
  });
  const expected=['Bachillerato General','Titulación por experiencia laboral'];
  const data={nombre:'Prueba',apellido:'Navegador',fecha_nacimiento:'1990-01-01',movil:'7772345678',movil_confirmacion:'7772345678',correo:`navegador-${Date.now()}@example.test`,correo_confirmacion:`navegador-${Date.now()}@example.test`,perfil_interes:expected[0],pais_nombre:'Estados Unidos',estado_provincia:'Texas',ciudad:'Houston',lugar_laboras:'',cargo_puesto:''};
  data.correo_confirmacion=data.correo;
  const counts=()=>JSON.parse(execFileSync(php,['-r',`require 'config/db_connection.php'; $d=(new Database())->connect(false); $r=[]; foreach(['formulario_registros','formulario_inscripciones','formulario_aliado_procedencias'] as $t){$r[$t]=(int)$d->query('SELECT COUNT(*) n FROM '.$t)->fetch_assoc()['n'];} echo json_encode($r);`],{cwd:root,env,encoding:'utf8'}));
  for(const action of ['registro','inscripcion']){
   await page.goto(url(action)); assert.match(await page.locator('h1').textContent(),/Formulario/);
   assert.deepEqual(await page.locator('[name=perfil_interes] option').allTextContents().then(a=>a.map(x=>x.trim()).filter(x=>x!=='Selecciona una opción'&&expected.includes(x))),expected);
   assert.equal(await page.locator('[name=perfil_interes] option').count(),3);
   assert.match(await page.locator('form').getAttribute('action'),new RegExp('aliado='+token));
   assert.match(await page.locator('body').textContent(),/Handly Lenguaje Center/);
   for(const width of [1440,390]){
    await page.setViewportSize({width,height:900});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'sin overflow');
    if(process.env.IMPE_TEST_OUTPUT){fs.mkdirSync(process.env.IMPE_TEST_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.IMPE_TEST_OUTPUT,`handly_${action}_${width}.png`),fullPage:true});}
   }
   // Envío mediante el JS original del formulario, con catálogo de municipios real.
   data.csrf_formulario_publico=await page.locator('[name=csrf_formulario_publico]').inputValue();
   assert.equal((await page.request.post(url('guardar'+action[0].toUpperCase()+action.slice(1)),{form:{...data,csrf_formulario_publico:'falso'}})).status(),403);
   for(const key of ['nombre','apellido','fecha_nacimiento','movil','movil_confirmacion','correo','correo_confirmacion']){
    await page.locator(`[name=${key}]`).fill(key.startsWith('correo')?`ui-${action}@example.test`:data[key]);
   }
   await page.locator('[name=perfil_interes]').selectOption(expected[0]);
   assert.equal(await page.locator('[name=estado_id]').count(),0);
   for(const key of ['pais_nombre','estado_provincia','ciudad']) await page.locator(`[name=${key}]`).fill(data[key]);
   await page.locator('button[type=submit]').click();
   await page.waitForURL(/action=gracias/);
   const save='guardar'+action[0].toUpperCase()+action.slice(1);
   const beforeReject=counts();
   for(const bad of [{estado_id:'1'},{municipio_id:'abc'},{estado_provincia:''},{ciudad:'x'.repeat(121)}]) {
    assert.equal((await page.request.post(url(save),{form:{...data,...bad}})).status(),422);
   }
   for(const program of ['Inglés','Bachillerato en 2 años','programa adulterado']){
    const response=await page.request.post(url(save),{form:{...data,perfil_interes:program,aliado_id:'999'}});
    assert.equal(response.status(),422); assert.equal((await response.json()).ok,false);
   }
   for(const invalid of ['', 'invalido', '0'.repeat(64)]){
    assert.equal((await page.request.get(url(action,invalid))).status(),404);
    assert.equal((await page.request.post(url(save,invalid),{form:data})).status(),422);
   }
   assert.deepEqual(counts(),beforeReject,'rechazos sin escrituras ni procedencia falsa');
   const good=await page.request.post(url(save),{form:data});assert.equal(good.status(),200); const first=await good.json();assert.equal(first.ok,true);
   const replies=await Promise.all([1,2].map(()=>page.request.post(url(save),{form:data}).then(r=>r.json())));
   for(const reply of replies)assert.equal(reply.registro_id,first.registro_id);
   const second=await page.request.post(url(save),{form:{...data,perfil_interes:expected[1]}});assert.equal((await second.json()).ok,true);
   // General: catálogo original e Inglés permitidos, sin atribución arbitraria del POST.
   await page.goto(url(action,null));assert.equal(await page.locator('[name=perfil_interes] option').count(),7);
   assert.equal(await page.locator('[name=estado_id]').count(),1);
   await page.locator('[name=estado_id]').selectOption('1');
   await page.locator('[name=municipio_id] option[value="1"]').waitFor({state:'attached'});
   const beforeGeneral=counts().formulario_aliado_procedencias;
   const legacy=await page.request.post(url(save,null),{form:{...data,estado_id:'1',municipio_id:'1',perfil_interes:'Inglés',aliado_id:'999'}});assert.equal((await legacy.json()).ok,true);
   assert.equal(counts().formulario_aliado_procedencias,beforeGeneral,'formulario general no hereda aliado por sesión ni por POST');
   console.log(`OK: ${action}: UI, responsive, autorización, enlaces, reenvíos y formulario general`);
  }
  assert.deepEqual(errors,[]); console.log('OK: navegador y HTTP reales');
 } finally {if(browser)await browser.close();server.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
