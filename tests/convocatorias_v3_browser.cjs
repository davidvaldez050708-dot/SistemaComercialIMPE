const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8101/',modules=process.env.BOOTSTRAP_MODULE_PATH||path.resolve(root,'../browser-deps/node_modules/bootstrap');
assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_aliados[a-z0-9_]*$/);
const old=execFileSync('git',['show','4b0c3abc:public/javascript/convocatorias_aliado_multiprograma_v3.js'],{cwd:root}),current=fs.readFileSync(path.join(root,'public/javascript/convocatorias_aliado_multiprograma_v3.js'));
const server=spawn(process.env.PHP_BINARY||'php',['-S',new URL(base).host,'-t',root,'tests/convocatorias_v3_router.php'],{cwd:root,env:{...process.env,IMPE_TEST_ALLOW_ROUTER:'1'},stdio:['ignore','ignore','pipe']});let logs='';server.stderr.on('data',d=>logs+=d);
(async()=>{let browser,count=0;let baseline=false,delay=false,corrupt='';const check=(ok,m)=>{assert.ok(ok,m);console.log('OK '+m);count++;};
try{
 for(let i=0;i<70;i++){try{await fetch(base+'_fixture_v3');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 const context=await browser.newContext(),page=await context.newPage(),errors=[],savedResponses=[];page.on('pageerror',e=>errors.push(e.message));
 await context.route('**/*',async r=>{const u=new URL(r.request().url());
  if(u.origin!==new URL(base).origin)return r.abort();
  if(u.searchParams.get('action')==='guardarProgramasAliado'&&r.request().method()==='POST'){
   const response=await r.fetch();savedResponses.push(await response.json());return r.fulfill({response});
  }
  if(u.pathname==='/bootstrap.css')return r.fulfill({path:path.join(modules,'dist/css/bootstrap.min.css'),contentType:'text/css'});
  if(u.pathname==='/bootstrap.js')return r.fulfill({path:path.join(modules,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
  if(u.pathname.startsWith('/fixture-font/'))return r.fulfill({path:path.join(modules,'../@fontsource/manrope/files',path.basename(u.pathname)),contentType:'font/woff2'});
  if(u.pathname.endsWith('/convocatorias_aliado_modular.js')&&delay)return r.fulfill({body:'setTimeout(()=>{'+fs.readFileSync(path.join(root,'tests/fixtures/v3_hostinger/convocatorias_aliado_modular.js'),'utf8')+'},50);',contentType:'application/javascript'});
  if(u.pathname.endsWith('/convocatorias_aliado_multiprograma_v3.js')){
   let prefix='';if(corrupt)prefix="(()=>{const mutate=()=>{const o=document.querySelector('[data-convocatoria-aliado-modulo] [data-impe-programa-option]');if(o)o.dataset."+(corrupt==='codigo'?'codigo':'clasificacion')+"='valor inválido';};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mutate);else mutate();})();\n";
   return r.fulfill({body:prefix+(baseline?old:current).toString(),contentType:'application/javascript'});
  }return r.continue();
 });
 const modal=page.locator('#modalCrearConvocatoria'),cards=modal.locator('.impe-aliado-multi-card'),button=modal.locator('[type=submit]');
 const goto=async(caso='ssp',role='marketing')=>{await page.goto(base+'_fixture_v3?caso='+caso+'&role='+role);await modal.waitFor({state:'visible'});await page.addStyleTag({content:fs.readFileSync(path.join(modules,'../@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/','/fixture-font/')});await page.evaluate(()=>document.fonts.ready);await page.waitForTimeout(350);};
 const capture=async name=>{const png=await modal.screenshot();if(process.env.IMPE_V3_OUTPUT){fs.mkdirSync(process.env.IMPE_V3_OUTPUT,{recursive:true});fs.writeFileSync(path.join(process.env.IMPE_V3_OUTPUT,name+'.png'),png);}return png;};
 // La versión anterior funciona con uno y varios programas válidos; reproduce
 // exactamente la salida silenciosa con la plantilla real y opciones vacías.
 if(!process.env.IMPE_V3_RESUME){
 baseline=true;await goto('iqj');check(await cards.count()===1,'baseline: un programa válido sí inicia V3');
 await goto('sin-clase');check(await cards.count()===0&&await button.textContent()==='Guardar convocatoria','baseline: sin clasificación retorna al formulario antiguo');
 for(const width of [1440,1024,768,390,360]){
  await page.setViewportSize({width,height:900});baseline=true;await goto('ssp');const before=await capture('antes-ssp-'+width);
  baseline=false;await goto('ssp');const after=await capture('despues-ssp-'+width);check(before.equals(after),'SSP diseño aprobado idéntico píxel a píxel '+width);
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'sin desbordamiento horizontal '+width);
  const foot=await modal.locator('.modal-footer').boundingBox();check(foot.y>=0&&foot.y+foot.height<=901,'footer accesible '+width);
 }
 for(const role of ['marketing','cc'])for(const [caso,n] of [['iqj',1],['ssp',2],['uno',1],['tres',3]]){
  await page.setViewportSize({width:role==='marketing'?1440:390,height:900});
  await goto(caso,role);check(await cards.count()===n,'tarjetas autorizadas '+caso+' '+role);
  check(await modal.locator('.convocatoria-aliado-modulo-eyebrow').textContent()==='PUBLICACIONES POR PROGRAMA','editor único '+caso+' '+role);
  check(await button.textContent()==='Guardar convocatorias'&&await button.isEnabled(),'guardar lote '+caso+' '+role);
  check(!await modal.locator('[name=titulo]').isVisible(),'formulario individual oculto '+caso+' '+role);
 }
 await goto('sin-clase');check(await button.isDisabled()&&await cards.count()===0,'sin compatibles: guardado bloqueado');
 check((await modal.locator('[data-impe-error]').textContent()).includes('clasificación compatible'),'explicación institucional del dato faltante');
 check(!await modal.locator('[name=titulo]').isVisible(),'sin compatibles: no hay fallback individual');
 check(await page.evaluate(()=>{document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));return window.legacySubmits===0;}),'sin compatibles: el submit antiguo no se ejecuta');
 }
 for(const mode of ['codigo','clasificacion']){corrupt=mode;await goto('ssp');check(await button.isDisabled()&&await cards.count()===0,'opción incompleta bloquea lote entero: '+mode);}corrupt='';
 delay=true;await goto('ssp');await cards.first().waitFor();check(await cards.count()===2,'montaje tardío V2 recuperado');delay=false;
 await page.addScriptTag({content:current.toString()});await page.addScriptTag({content:current.toString()});check(await cards.count()===2,'script repetido no duplica tarjetas ni inicialización');
 for(const role of ['sin-permiso','sin-territorio']){const response=await page.goto(base+'_fixture_v3?caso=iqj&role='+role);check(response.status()===403,'backend conserva acceso/territorio: '+role);}
 const stats=async()=>await(await context.request.get(base+'_v3_stats')).json();
 const fill=async(fail=false)=>{for(let i=0;i<await cards.count();i++){const c=cards.nth(i);await c.locator('input[type=text]').fill('V3 TEST '+Date.now()+' '+i);const dates=c.locator('input[type=date]');await dates.nth(0).fill('2026-10-22');await dates.nth(1).fill('2026-11-22');await c.locator('select').first().selectOption(String(i%2));await c.locator('input[type=file]').setInputFiles({name:fail&&i===1?'invalida.txt':'imagen-'+i+'.png',mimeType:fail&&i===1?'text/plain':'image/png',buffer:fail&&i===1?Buffer.from('imagen no válida'):fs.readFileSync(path.join(root,'public/img/brand/Logo-Sep-Blanco.png'))});}};
 for(const [caso,role,n] of [['iqj','cc',1],['ssp','marketing',2],['tres','marketing',3]]){
  await goto(caso,role);await fill();const before=await stats();let requests=0;const listener=r=>{if(r.url().includes('action=guardarProgramasAliado')&&r.method()==='POST')requests++;};page.on('request',listener);
  const response=page.waitForResponse(r=>r.url().includes('action=guardarProgramasAliado')&&r.request().method()==='POST');
  const legacy=await page.evaluate(()=>{document.querySelector('form').requestSubmit();document.querySelector('form').requestSubmit();return window.legacySubmits;});const res=await response,data=savedResponses.at(-1);check(res.ok()&&data.ok&&data.ids.length===n,'guardado HTTP/DB real '+caso+': '+JSON.stringify(data));
  await page.waitForURL(u=>u.searchParams.get('action')==='index');page.off('request',listener);check(requests===1&&legacy===0,'un único POST multiprograma y ningún submit antiguo '+caso);
  const after=await stats();check(Number(after.n)-Number(before.n)===n&&Number(after.images)-Number(before.images)===n&&Number(after.links)-Number(before.links)===n,'IDs/imágenes/orígenes independientes '+caso);
  const group=await(await context.request.get(base+'_v3_stats?ids='+data.ids.join(','))).json();check(Number(group.urls)===1&&Number(group.n)===n,'un enlace compartido por aliado '+caso);check(Number(group.manual_links)===(caso==='tres'?n:0),'vínculo manual compatible sin mezclar instituciones '+caso);
 }
 await goto('ssp');await fill(true);const before=await stats();const response=page.waitForResponse(r=>r.url().includes('action=guardarProgramasAliado'));await button.click();const bad=await response;check(!bad.ok(),'rechazo de segunda imagen no válida');const after=await stats();check(Number(after.n)===Number(before.n)&&Number(after.links)===Number(before.links),'rollback completo: no queda el primer programa');
 const forbidden=await context.request.post(base+'index.php?controller=convocatoria&action=guardarProgramasAliado',{multipart:{csrf_token:'a'.repeat(64),aliado_tipo:'institucional',aliado_id:'9101','programas[ingles][clasificacion]':'bachillerato|ingles'}});check(forbidden.status()===422,'backend rechaza programa no autorizado');
 const plain=await context.newPage();await plain.setContent('<form><button type=submit>Guardar convocatoria</button></form><script>window.legacySubmits=0;document.querySelector("form").addEventListener("submit",e=>{e.preventDefault();legacySubmits++})</script>');await plain.addScriptTag({content:current.toString()});check(await plain.evaluate(()=>{document.querySelector('form').requestSubmit();return legacySubmits===1;}),'flujo tradicional sin aliado conserva su submit');await plain.close();
 check(errors.length===0,'sin errores de consola: '+errors.join('; '));check(!/PHP (Warning|Fatal|Parse error|Deprecated)/.test(logs),'sin errores PHP');console.log('PASS '+count+' comprobaciones V3, visuales, HTTP, permisos y DB aislada');
}finally{if(process.env.IMPE_V3_LOG)fs.writeFileSync(process.env.IMPE_V3_LOG,logs);if(browser)await browser.close();server.kill();}})().catch(e=>{console.error(e);process.exitCode=1;});
