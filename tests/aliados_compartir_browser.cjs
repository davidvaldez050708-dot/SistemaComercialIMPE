const assert=require('node:assert/strict'),{spawn,execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php',base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8097/';
assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_aliados[a-z0-9_]*$/);
const env={...process.env,APP_ENV:'testing',IMPE_TEST_ALLOW_ROUTER:'1'};
const sql=q=>JSON.parse(execFileSync(php,['-r',`require 'config/db_connection.php';$d=(new Database())->connect(false);$r=$d->query(${JSON.stringify(q)});echo json_encode($r===true?[]:$r->fetch_all(MYSQLI_ASSOC));`],{cwd:root,env,encoding:'utf8'}));
const manual=sql("SELECT id FROM aliados_manuales WHERE nombre_entidad='Aliado Regional'")[0].id;
const server=spawn(php,['-S',new URL(base).host,'-t',root,'tests/aliados_registro_router.php'],{cwd:root,env,stdio:['ignore','ignore','pipe']});
let logs='';server.stderr.on('data',d=>logs+=d);
const bootstrap=env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
const fixture=base+'_fixture?view=aliados&role=share';
(async()=>{
 let browser,count=0;const check=(v,m)=>{assert.ok(v,m);console.log('OK '+m);count++;};
 try{
  for(let i=0;i<70;i++){try{await fetch(fixture);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true,...(env.PLAYWRIGHT_CHANNEL?{channel:env.PLAYWRIGHT_CHANNEL}:{})});
  const context=await browser.newContext({permissions:['clipboard-read','clipboard-write']}),page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/*',r=>{
   const url=r.request().url();
   if(url.endsWith('/fixture-bootstrap.css'))return r.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'});
   if(url.endsWith('/fixture-bootstrap.js'))return r.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
   if(url.includes('/fixture-font/'))return r.fulfill({path:path.join(bootstrap,'../@fontsource/manrope/files',path.basename(new URL(url).pathname)),contentType:'font/woff2'});
   if(url.includes('/fixture-icon/'))return r.fulfill({path:path.join(bootstrap,'../bootstrap-icons/font/fonts',path.basename(new URL(url).pathname)),contentType:'font/woff2'});
   if(url.includes('fixture-compartir-'))return r.fulfill({contentType:'image/svg+xml',body:`<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="600" height="400" fill="${url.includes('-1.svg')?'#273a8a':'#008575'}"/><text x="40" y="200" fill="white" font-size="30">Convocatoria ${url.includes('-1.svg')?1:2}</text></svg>`});
   if(url.startsWith('https://'))return r.abort();return r.continue();
  });
  const modal=page.locator('#modalAliadoCompartir');
  const open=async tipo=>{
   await page.goto(fixture);
   await page.addStyleTag({content:fs.readFileSync(path.join(bootstrap,'../@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/','/fixture-font/')});
   await page.addStyleTag({content:fs.readFileSync(path.join(bootstrap,'../bootstrap-icons/font/bootstrap-icons.css'),'utf8').replaceAll('./fonts/','/fixture-icon/')});
   await page.evaluate(()=>document.fonts.ready);
   await page.locator(`[data-aliado-share-origen="${tipo}"][data-aliado-share="${tipo==='manual'?manual:10}"]`).click();
   await modal.locator('[name=convocatoria_id]:enabled').waitFor();
   await page.waitForTimeout(200);
  };
  const select=async id=>{
   const response=page.waitForResponse(r=>r.url().includes('action=borradorEnvio'));
   await modal.locator('[name=convocatoria_id]').selectOption(String(id));const res=await response;
   check(res.status()===200&&(await res.json()).ok,'borrador asociado por HTTP');
   await modal.locator('[data-aliado-send-button]:enabled').waitFor();
  };
  const urls={};
  for(const tipo of ['manual','institucional']){
   await open(tipo);
   const options=await modal.locator('[name=convocatoria_id] option').allTextContents();
   check(options.filter(x=>x.includes(ucfirst(tipo)+' · Programa')).length===2,'dos programas distinguibles '+tipo);
   check(!options.some(x=>x.includes('Vencida')||x.includes('Borrador')||x.includes('Sólo territorial')||x.includes('Inscripción')),'sin publicaciones no elegibles '+tipo);
   check(!options.some(x=>x.includes(ucfirst(tipo==='manual'?'institucional':'manual')+' · Programa')),'sin convocatoria ajena '+tipo);
   const ids=sql(`SELECT convocatoria_id FROM marketing_aliado_convocatorias_origen WHERE origen_tipo='${tipo}' AND origen_id=${tipo==='manual'?manual:10} ORDER BY convocatoria_id`).map(x=>x.convocatoria_id).filter(id=>options.some(x=>x.includes('#'+id+' ')));
   await select(ids[0]);const image1=await modal.locator('[data-aliado-convocatoria-image]').getAttribute('src');
   urls[tipo]=await modal.locator('[data-aliado-convocatoria-link]').textContent();
   await select(ids[1]);
   check(image1!==await modal.locator('[data-aliado-convocatoria-image]').getAttribute('src'),'cambia imagen '+tipo);
   check(urls[tipo]===await modal.locator('[data-aliado-convocatoria-link]').textContent(),'enlace estable entre programas '+tipo);
   check((await modal.locator('[data-aliado-convocatoria-programa]').textContent()).includes('Titulación'),'cambia programa de vista previa '+tipo);
   check((await modal.locator('[name=destinatario]').inputValue()).endsWith('@example.test'),'destinatario válido del aliado '+tipo);
   await modal.locator('[data-aliado-share-channel=WHATSAPP_MANUAL]').click();
   check(await modal.locator('[name=contacto_id] option').count()===2,'selector de números autorizados '+tipo);
   await modal.locator('[name=mensaje]').fill('Mensaje personalizado del aliado');
   const phoneOptions=await modal.locator('[name=contacto_id] option').evaluateAll(nodes=>nodes.map(n=>({id:n.value,text:n.textContent})));
   await modal.locator('[name=contacto_id]').selectOption(phoneOptions[1].id);
   check(await modal.locator('[name=mensaje]').inputValue()==='Mensaje personalizado del aliado','cambiar número conserva mensaje editado '+tipo);
   check((await modal.locator('[data-aliado-open-whatsapp-manual]').getAttribute('href')).includes(phoneOptions[1].text.split(' · ')[0]),'WhatsApp abre el número seleccionado '+tipo);
   await modal.locator('[data-aliado-copy-link]').click();check((await page.evaluate(()=>navigator.clipboard.readText())).includes('action=registro&aliado='),'copiar enlace '+tipo);
   await modal.locator('[data-aliado-copy-number]').click();check((await page.evaluate(()=>navigator.clipboard.readText())).includes(phoneOptions[1].text.split(' · ')[0]),'copiar número '+tipo);
   await modal.locator('[data-aliado-copy-message]').click();check(await page.evaluate(()=>navigator.clipboard.readText())==='Mensaje personalizado del aliado','copiar mensaje '+tipo);
   check((await modal.locator('[data-aliado-download-image]').getAttribute('href')).endsWith('fixture-compartir-2.svg'),'descarga corresponde a la imagen elegida '+tipo);
   for(const [width,height] of [[1440,900],[1024,768],[768,1024],[390,844],[360,740]]){
    await page.setViewportSize({width,height});
    check(await modal.evaluate(el=>el.scrollWidth<=innerWidth&&el.querySelector('.modal-body').scrollWidth<=el.querySelector('.modal-body').clientWidth),'sin desbordamiento '+tipo+' '+width);
    await modal.locator('[data-aliado-send-button]').scrollIntoViewIfNeeded();
    check(await modal.locator('[data-aliado-send-button]').evaluate(el=>{const r=el.getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight&&r.height<60;}),'acción proporcionada y accesible '+tipo+' '+width);
    if(env.IMPE_COMPARTIR_OUTPUT){fs.mkdirSync(env.IMPE_COMPARTIR_OUTPUT,{recursive:true});await page.screenshot({path:path.join(env.IMPE_COMPARTIR_OUTPUT,`compartir-${tipo}-${width}.png`),fullPage:true});}
   }
   const table=tipo==='manual'?'aliados_manuales_difusiones':'aliados_convocatorias_envios';
   check(Number(sql(`SELECT COUNT(*) n FROM ${table}`)[0].n)===0,'preparar/copiar no registra envío '+tipo);
   const saved=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('action=registrarConvocatoriaWhatsappManual'));
   await modal.locator('[data-aliado-send-button]').click();const response=await saved;
   check(response.status()===200&&(await response.json()).ok,'registro manual confirmado por servidor '+tipo);
   check(Number(sql(`SELECT COUNT(*) n FROM ${table}`)[0].n)===1,'historial del origen correcto '+tipo);
   if(tipo==='institucional')check(sql('SELECT destinatario FROM aliados_convocatorias_envios')[0].destinatario===phoneOptions[1].text.split(' · ')[0],'historial conserva destinatario seleccionado');
   await page.setViewportSize({width:1440,height:900});
  }
  check(urls.manual!==urls.institucional,'no mezcla enlaces entre aliados');
  sql(`UPDATE formulario_aliado_registro SET habilitado=0 WHERE manual_id=${manual}`);
  await page.goto(fixture);await page.locator(`[data-aliado-share-origen=manual][data-aliado-share="${manual}"]`).click();
  await modal.locator('[data-aliado-send-status]:visible').waitFor();
  check(await modal.locator('[name=convocatoria_id]').isDisabled()&&await modal.locator('[data-aliado-send-button]').isDisabled(),'sin publicaciones no muestra selector operativo');
  sql(`UPDATE formulario_aliado_registro SET habilitado=1 WHERE manual_id=${manual}`);
  await open('institucional');
  const scopeResponse=page.waitForResponse(r=>r.url().includes('action=prepararEnvio')&&r.url().includes('ambito=territorial'));
  await modal.locator('[name=ambito]').selectOption('territorial');await scopeResponse;
  await modal.locator('[name=convocatoria_id]:enabled').waitFor();
  check((await modal.locator('[name=convocatoria_id] option').allTextContents()).some(x=>x.includes('Sólo territorial')),'ámbito territorial explícito conserva Vinculación');
  const get=action=>base+'index.php?controller=aliado&action='+action;
  let res=await page.request.post(get('registrarConvocatoriaWhatsappManual'),{form:{origen_tipo:'manual',origen_id:String(manual),csrf_token:'incorrecto'},headers:{Accept:'application/json','X-Requested-With':'fetch'}});
  check(res.status()===419,'CSRF inválido rechazado por HTTP');
  await page.goto(base+'_fixture?role=guest');
  res=await page.request.get(get('prepararEnvio')+'&origen_tipo=manual&origen_id='+manual,{headers:{Accept:'application/json','X-Requested-With':'fetch'}});
  check(res.status()===401,'sesión vencida recibe respuesta JSON autorizada');
  check(errors.length===0,'sin errores JavaScript: '+errors.join(';'));
  check(!/PHP (Warning|Fatal|Parse|Deprecated)/.test(logs),'sin errores PHP');
  console.log('PASS '+count+' comprobaciones de Compartir en navegador/HTTP');
 }finally{if(browser)await browser.close();server.kill();}
})().catch(e=>{console.error(e);console.error(logs.slice(-2000));process.exitCode=1;});
function ucfirst(value){return value.charAt(0).toUpperCase()+value.slice(1);}
