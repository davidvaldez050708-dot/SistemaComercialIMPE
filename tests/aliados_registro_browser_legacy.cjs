const assert=require('node:assert/strict');
const {spawn,execFileSync,execFile}=require('node:child_process');
const {promisify}=require('node:util');
const path=require('node:path'),fs=require('node:fs');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
const base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8097/';
assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_aliados[a-z0-9_]*$/);
const env={...process.env,APP_ENV:'testing',IMPE_TEST_ALLOW_ROUTER:'1'};
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
const query=sql=>JSON.parse(execFileSync(php,['-r',`require 'config/db_connection.php'; echo json_encode((new Database())->connect(false)->query(${JSON.stringify(sql)})->fetch_all(MYSQLI_ASSOC));`],{cwd:root,env,encoding:'utf8'}));
const server=spawn(php,['-S',new URL(base).host,'-t',root,'tests/aliados_registro_router.php'],{cwd:root,env,stdio:['ignore','ignore','pipe']});
let logs='';server.stderr.on('data',d=>logs+=d);
const fixture=(view,role='cc')=>base+'_fixture?view='+view+'&role='+role;
(async()=>{
 let browser;let count=0;
 const check=(ok,msg)=>{assert.ok(ok,msg);count++;console.log('OK '+msg);};
 try{
  for(let i=0;i<80;i++){try{await fetch(fixture('aliados'));break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true,...(env.PLAYWRIGHT_CHANNEL?{channel:env.PLAYWRIGHT_CHANNEL}:{})});
  const context=await browser.newContext(),page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/*',route=>{
   const url=route.request().url();
   if(url.endsWith('/fixture-bootstrap.css')||url.includes('bootstrap.min.css'))return route.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'});
   if(url.endsWith('/fixture-bootstrap.js')||url.includes('bootstrap.bundle.min.js'))return route.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
   if(url.startsWith('https://'))return route.abort();return route.continue();
  });
  const viewport=async(label,modal,tab=page)=>{
   for(const [width,height] of [[1366,900],[1440,900],[390,844],[844,390]]){
    await tab.setViewportSize({width,height});
    check(await tab.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),label+' sin scroll horizontal '+width);
    if(modal){await tab.locator(modal+' .modal-footer').waitFor({state:'visible'});const footer=tab.locator(modal+' .modal-footer');const box=await footer.boundingBox();check(box&&box.y>=0&&box.y+box.height<=height,label+' footer accesible '+width);}
    if(env.IMPE_TEST_OUTPUT){fs.mkdirSync(env.IMPE_TEST_OUTPUT,{recursive:true});await tab.screenshot({path:path.join(env.IMPE_TEST_OUTPUT,label+'-'+width+'.png'),fullPage:true});}
   }
  };
  await page.goto(fixture('aliados'));
  check(await page.locator('[data-aliado-row]').count()===2,'directorio unificado visible');
  await page.locator('[data-aliados-origen]').selectOption('manual');
  check(await page.locator('[data-aliado-row]:visible').count()===1,'filtro origen manual');
  await page.locator('[data-aliados-origen]').selectOption('');
  const manualRow=page.locator('[data-aliado-row]').filter({hasText:'Aliado Regional'});
  check(await manualRow.locator('[href*="action=detalle"]').count()===0,'manual sin expediente falso');
  await page.getByRole('button',{name:'Agregar aliado',exact:true}).click();
  const modal='#modalAliadoRegistro';await page.locator(modal+' [name=municipio_id] option[value="1"]').waitFor({state:'attached'});
  await viewport('manual',modal);await page.setViewportSize({width:1366,height:900});
  await page.locator(modal+' [name=nombre_entidad]').fill('Aliado navegador');
  await page.locator(modal+' [name=municipio_id]').selectOption('1');
  await page.locator(modal+' [value="bachillerato-286"]').check();await page.locator(modal+' [value="ingles"]').check();
  await Promise.all([page.waitForNavigation(),page.locator('[data-registro-guardar]').click()]);
  check(await page.locator('[data-aliado-row]').count()===3,'alta por interfaz y recarga directorio');
  const row=page.locator('[data-aliado-row]').filter({hasText:'Aliado navegador'});await row.getByRole('button',{name:'Programas',exact:true}).click();
  await page.locator('[data-registro-guardar]:enabled').waitFor();
  check(await page.locator(modal+' [value="bachillerato-286"]').isChecked(),'programas recuperados');
  await page.locator(modal+' [value="ingles"]').uncheck();
  await Promise.all([page.waitForNavigation(),page.locator('[data-registro-guardar]').click()]);
  await page.goto(fixture('convenios','marketing'));
  await page.locator('[data-convenio-origin-filter]').selectOption('institucional');
  check(await page.locator('[data-convenio-notification]:visible').count()===1,'Marketing filtra Vinculación');
  await page.locator('[data-convenio-origin-filter]').selectOption('');
  const card=page.locator('[data-convenio-notification]').filter({hasText:'Aliado navegador'});await card.click();
  await page.getByRole('button',{name:'Generar enlace',exact:true}).waitFor({state:'visible'});
  await page.waitForFunction(()=>!document.querySelector('[data-enlace-accion="generar"]').disabled);
  await page.getByRole('button',{name:'Generar enlace',exact:true}).click();await page.waitForFunction(()=>Boolean(document.querySelector('[data-enlace-url]').value));
  const publicUrl=await page.locator('[data-enlace-url]').inputValue();
  check(publicUrl.includes('action=registro&aliado='),'URL generada de Registro');
  check(await page.locator('[data-convenio-create-link]').isHidden(),'manual explica dependencia convocatoria');
  await page.getByRole('button',{name:'Generar QR',exact:true}).click();
  const jsQR=require(env.JSQR_MODULE_PATH||path.join(root,'../browser-deps/node_modules/jsqr'));
  const image=await page.locator('canvas').evaluate(c=>({pixels:Array.from(c.getContext('2d').getImageData(0,0,c.width,c.height).data),width:c.width,height:c.height}));
  check(jsQR(new Uint8ClampedArray(image.pixels),image.width,image.height)?.data===publicUrl,'QR decodifica exactamente enlace exclusivo');
  await viewport('convenio','#modalConvenioCrearConvocatoria');
  await page.setViewportSize({width:1366,height:900});
  const disabled=page.locator('[data-enlace-accion="deshabilitar"]');await disabled.click();await page.getByText('Captura deshabilitada',{exact:true}).waitFor();
  const publicContext=await browser.newContext();
  await publicContext.route('https://**/*',r=>r.request().url().includes('bootstrap.min.css')?r.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'}):r.abort());
  const external=await publicContext.newPage();
  check((await external.goto(publicUrl)).status()===404,'enlace deshabilitado no disponible');
  await page.locator('[data-enlace-accion="habilitar"]').click();await page.getByText('Captura habilitada',{exact:true}).waitFor();
  check(await page.locator('[data-enlace-url]').inputValue()===publicUrl,'habilitar conserva enlace');
  await external.goto(publicUrl);
  check(await external.locator('[name=perfil_interes] option').count()===2,'único programa autorizado y placeholder');
  await viewport('publico',null,external);
  const csrf=await external.locator('[name=registro_csrf]').inputValue();
  const data={nombre:'Persona',apellido:'Navegador',fecha_nacimiento:'1990-01-01',movil:'7773456789',movil_confirmacion:'7773456789',correo:'navegador@example.test',correo_confirmacion:'navegador@example.test',perfil_interes:'Bachillerato 286',estado_id:'1',municipio_id:'1',registro_csrf:csrf};
  const save=publicUrl.replace('action=registro','action=guardarRegistro');
  check((await external.request.post(save,{form:{...data,perfil_interes:'Inglés'}})).status()===422,'programa adulterado rechazado HTTP');
  check((await external.request.post(save,{form:{...data,municipio_id:'2'}})).status()===422,'municipio adulterado rechazado HTTP');
  check((await external.request.post(save,{form:{...data,registro_csrf:''}})).status()===419,'CSRF público exclusivo');
  check((await external.request.post(save,{form:{...data,sitio_web:'bot'}})).status()===419,'control anti automatización');
  for(const key of ['nombre','apellido','fecha_nacimiento','movil','movil_confirmacion','correo','correo_confirmacion'])await external.locator('[name='+key+']').fill(data[key]);
  await external.locator('[name=perfil_interes]').selectOption(data.perfil_interes);await external.locator('[name=estado_id]').selectOption('1');await external.locator('[name=municipio_id] option[value="1"]').waitFor({state:'attached'});await external.locator('[name=municipio_id]').selectOption('1');
  await external.locator('[data-publico-submit]').click();await external.waitForURL(/action=gracias/);
  const a=await external.request.post(save,{form:data}),b=await external.request.post(save,{form:data});
  check(a.status()===200&&(await a.json()).registro_id===(await b.json()).registro_id,'reenvíos HTTP idempotentes');
  await page.goto(fixture('prospectos','marketing'));check(await page.locator('tbody tr').filter({hasText:'navegador@example.test'}).textContent().then(t=>t.includes('Aliado navegador')),'Prospectos muestra institución de origen');
  await viewport('prospectos');
  const auth=base+'index.php?controller=aliado&action=registroGestion';
  check((await page.request.post(auth,{form:{operacion:'generar',origen:'manual',id:'1'}})).status()===419,'CSRF interno obligatorio');
  await page.goto(fixture('aliados','admin'));check(await page.locator('[data-registro-abrir]').count()===0,'Admin consulta sin botones operativos');
  check((await page.request.post(auth,{form:{operacion:'guardar_manual',csrf_token:'a'.repeat(64)}})).status()===403,'Admin backend sin operación');
  await page.goto(fixture('aliados','guest'));check((await page.request.get(base+'index.php?controller=aliado&action=registroDetalle&origen=manual&id=1',{headers:{'X-Requested-With':'fetch'}})).status()===401,'sin sesión no expone datos ni token');
  for(const action of ['manual','enlace','registro']){
   const results=await Promise.all(Array.from({length:4},()=>promisify(execFile)(php,['tests/aliados_registro_concurrente.php',action],{cwd:root,env}).then(r=>r.stdout)));
   if(action==='manual')check(results.filter(r=>r==='DUPLICADO').length===3,'altas manuales concurrentes sin duplicados');
   else check(new Set(results).size===1,'concurrencia '+action+' idempotente');
  }
  check(Number(query("SELECT COUNT(*) n FROM formulario_registros WHERE correo='concurrente@example.test'")[0].n)===1,'una sola respuesta concurrente');
  check(errors.length===0,'sin errores JavaScript');
  check(!/PHP (Warning|Fatal|Parse|Deprecated)/.test(logs),'sin errores PHP en vistas y endpoints');
  console.log('PASS '+count+' comprobaciones navegador/HTTP/concurrencia');
 }finally{if(browser)await browser.close();server.kill();}
})().catch(e=>{console.error(e);console.error(logs.slice(-5000));process.exitCode=1;});
