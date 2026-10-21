const assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');const fs=require('node:fs');const path=require('node:path');const os=require('node:os');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');const root=path.resolve(__dirname,'..');const php=process.env.PHP_BINARY||'php';
assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly[a-z0-9_]*$/);
const uploadRoot=path.join(root,'public/uploads/convocatorias');const beforeUploads=new Set(fs.existsSync(uploadRoot)?fs.readdirSync(uploadRoot):[]);
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'impe-internacional-'));const beforeView=path.join(temp,'antes.php');fs.writeFileSync(beforeView,execFileSync('git',['show','aa1f9fafeeca8572e78098bef501bc81e0460c12:app/views/aliados/internacionales.php'],{cwd:root}));const router=path.join(temp,'router.php');const port=8101;const base=`http://127.0.0.1:${port}/`;
fs.writeFileSync(router,`<?php
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='/index.php' && parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='/__rol' && parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='/__before')return false;
session_save_path(__DIR__);session_start();require ${JSON.stringify(root.replaceAll('\\','/')+'/config/config.php')};
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/__rol'){
$rol=$_GET['rol']??'lector';$p=['aliados.ver','convocatorias.ver'];if($rol==='lector')$p=['aliados.ver'];if($rol==='marketing')$p=['convocatorias.ver'];
if($rol==='admin')$p[]='aliados.internacionales.configurar';
if($rol==='operador')$p=array_merge($p,['aliados.internacionales.configurar','convenios.gestionar']);
if($rol==='marketing')$p=array_merge($p,['convocatorias.crear','convocatorias.gestionar']);
$_SESSION=['usuario_id'=>$rol==='marketing'?3:($rol==='operador'?2:1),'rol_id'=>$rol==='admin'?1:($rol==='operador'?7:8),'rol'=>$rol==='marketing'?'Marketing':$rol,'permisos'=>$p];header('Location: /index.php?controller=aliado&action=internacionales');exit;}
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/__before'){
require ${JSON.stringify(root.replaceAll('\\','/')+'/app/controllers/AliadoController.php')};
if(!AliadoInternacionalService::puedeConsultar()){http_response_code(403);exit;}
$_GET['controller']='aliado';$_GET['action']='internacionales';$tituloPagina='Aliados internacionales';$subtituloPagina='Enlaces exclusivos y respuestas de instituciones externas';$opcionActiva='aliados';$db=(new Database())->connect(false);$externos=(new AliadoInternacionalModel($db))->obtenerAliados();$externos=array_slice($externos,0,3);$respuestas=[];$errorExternos='';$estructuraGestion=true;$convocatoriasExternas=(new AliadoInternacionalService($db))->convocatorias();$aliadoConvocatoria=null;$aliadoEditar=null;
require ${JSON.stringify(root.replaceAll('\\','/')+'/app/views/layout/dashboard_head.php')};require ${JSON.stringify(root.replaceAll('\\','/')+'/app/views/layout/sidebar.php')};require ${JSON.stringify(root.replaceAll('\\','/')+'/app/views/layout/topbar.php')};require ${JSON.stringify(beforeView.replaceAll('\\','/'))};require ${JSON.stringify(root.replaceAll('\\','/')+'/app/views/layout/dashboard_footer.php')};exit;}
$clases=['aliado'=>'AliadoController','convocatoria'=>'ConvocatoriaController','formularioPublico'=>'FormularioPublicoController'];$clase=$clases[$_GET['controller']??'aliado']??null;
if(!$clase){http_response_code(404);exit;}require ${JSON.stringify(root.replaceAll('\\','/')+'/app/controllers/')}.$clase.'.php';$action=$_GET['action']??'internacionales';(new $clase())->$action();
`);
const env={...process.env,APP_ENV:'testing',IMPE_BASE_URL:base};const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root,router],{cwd:root,env,stdio:['ignore','ignore',fs.openSync(path.join(temp,'php.log'),'w')]});
const today=execFileSync(php,['-r',"require 'config/db_connection.php';echo date('Y-m-d');"],{cwd:root,env,encoding:'utf8'}).trim();
const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64');
(async()=>{let browser;try{
for(let i=0;i<80;i++){try{await fetch(base+'__rol');break;}catch{await new Promise(r=>setTimeout(r,100));}}
browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
await page.route('https://**/*',r=>{
 const deps=path.dirname(bootstrap);
 if(r.request().url().includes('fonts.googleapis.com')){let css='';for(const weight of [400,500,600,700,800]){const file=path.join(deps,'@fontsource/manrope/files/manrope-latin-'+weight+'-normal.woff2');if(fs.existsSync(file))css+=`@font-face{font-family:Manrope;font-style:normal;font-weight:${weight};src:url(data:font/woff2;base64,${fs.readFileSync(file).toString('base64')}) format('woff2');}`;}return r.fulfill({body:css,contentType:'text/css'});}
 if(r.request().url().includes('bootstrap-icons.min.css')){const file=path.join(deps,'bootstrap-icons/font/fonts/bootstrap-icons.woff2');const css=fs.readFileSync(path.join(deps,'bootstrap-icons/font/bootstrap-icons.min.css'),'utf8').replace(/url\([^)]*\)/g,`url(data:font/woff2;base64,${fs.readFileSync(file).toString('base64')})`);return r.fulfill({body:css,contentType:'text/css'});}
 if(r.request().url().includes('bootstrap.min.css'))return r.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'});
 if(r.request().url().includes('bootstrap.bundle.min.js'))return r.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
 return r.abort();
});
await page.goto(base+'__rol?rol=lector');assert.equal(await page.locator('[data-copy-ally-url]').count(),0);
await page.goto(base+'__rol?rol=admin');await page.getByRole('button',{name:'Registrar institución'}).click();
const modal=page.locator('#modalAliadoInternacional');await modal.waitFor({state:'visible'});const name='Institución navegador '+Date.now();
await modal.locator('[name=nombre]').fill(name);await modal.locator('[name=pais_codigo]').selectOption('US');await modal.locator('[name=region]').fill('Texas');await modal.locator('[name=ciudad]').fill('Houston');await modal.locator('[value=bachillerato-general]').check();
await Promise.all([page.waitForURL(/action=internacionales/),modal.getByRole('button',{name:'Guardar institución'}).click()]);
await page.locator('#international-search').fill(name);let card=page.locator('[data-international-row]').filter({hasText:name});await card.waitFor();assert.match(await card.textContent(),/Houston, Texas, Estados Unidos/);await card.getByRole('button',{name:'Acciones de '+name}).click();await card.getByRole('link',{name:'Editar institución'}).click();
await modal.waitFor({state:'visible'});assert.equal(await modal.locator('[name=region]').inputValue(),'Texas');assert.equal(await modal.locator('[name=ciudad]').inputValue(),'Houston');await modal.locator('[name=ciudad]').fill('Austin');await modal.getByRole('button',{name:'Guardar institución'}).click();await page.waitForURL(/action=internacionales$/);await page.locator('#international-search').fill(name);assert.match(await card.textContent(),/Austin/);
const edit=await card.locator('a[href*=editar]').getAttribute('href');const id=Number(new URL(edit).searchParams.get('editar'));
// Admin conserva el bloqueo de operaciones de Marketing.
assert.equal(await card.locator('a[href*=crear_convocatoria]').count(),0);
await page.goto(base+'__rol?rol=operador');await page.locator('#international-search').fill(name);card=page.locator('[data-international-row]').filter({hasText:name});await card.getByRole('button',{name:'Acciones de '+name}).click();await card.getByRole('button',{name:'Gestionar convenio',exact:true}).click();const agreement=page.locator('#modalConvenioInternacional');await agreement.waitFor({state:'visible'});
await agreement.locator('[name=fecha]').fill(today);await agreement.locator('[name=referencia]').fill('USA-BROWSER');await agreement.getByRole('button',{name:'Guardar convenio'}).click();await page.waitForURL(/action=internacionales$/);await card.getByRole('button',{name:'Ver detalles'}).click();assert.match(await page.locator('#modalDetalleInternacional').textContent(),/USA-BROWSER/);await page.locator('#modalDetalleInternacional .modal-footer button').click();
// CSRF real: no se acepta una operación autenticada sin token.
assert.equal((await page.request.post(base+'index.php?controller=aliado&action=convenioInternacional',{form:{id,fecha:'2026-10-20',referencia:'ILEGAL'}})).status(),403);
await page.goto(base+'__rol?rol=marketing');
for(const type of ['registro','inscripcion']){
 await page.locator('#international-search').fill(name);card=page.locator('[data-international-row]').filter({hasText:name});await card.getByRole('button',{name:'Acciones de '+name}).click();await card.getByRole('link',{name:'Crear convocatoria'}).click();const create=page.locator('#modalCrearConvocatoria');await create.waitFor({state:'visible'});assert.equal(await page.locator('#modalCrearConvocatoria').count(),1);assert.match(await create.textContent(),new RegExp(name));
 await create.locator('[data-convocatoria-classification-trigger]').click();await create.locator('[data-convocatoria-classification-option="bachillerato|bachillerato-286"]').click();
 await create.locator('[data-convocatoria-form-type-trigger]').click();await create.locator(`[data-convocatoria-form-type-value=${type}]`).click();await create.locator('[name=titulo]').fill('Convocatoria '+type);
 await create.locator('[name=fecha_inicio]').fill(today);await create.locator('[name=fecha_termino]').fill('2099-12-31');await create.locator('[name=imagen]').setInputFiles({name:'prueba.png',mimeType:'image/png',buffer:png});
 const pending=await create.locator('form').evaluate(form=>Object.fromEntries(new FormData(form).entries()));
 const invalid=await page.request.post(base+'index.php?controller=convocatoria&action=guardar',{form:{...pending,clasificacion:'bachillerato|ingles'}});assert.match(await invalid.text(),/no está autorizada/);
 await create.locator('[data-convocatoria-generate-link]').click();const preview=await create.locator('[data-convocatoria-form-url]').inputValue();const parsed=new URL(preview);assert.equal(parsed.searchParams.get('action'),type);assert.match(parsed.searchParams.get('convocatoria_ref'),/^[a-f0-9]{32}$/);
 for(const width of [1440,390]){await page.setViewportSize({width,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);const x=create.locator('.btn-close').first();assert.equal(await x.isVisible(),true);}
 await create.locator('button[type=submit]').click();await page.waitForURL(/action=internacionales$/);assert.match(await page.locator('body').textContent(),/Convocatoria registrada correctamente/);
 await page.locator('#international-search').fill(name);card=page.locator('[data-international-row]').filter({hasText:name});await card.getByRole('button',{name:'Acciones de '+name}).click();await card.getByRole('button',{name:'Consultar convocatorias y QR'}).click();await page.locator('#modalConvocatoriasInternacional').waitFor({state:'visible'});card=page.locator('#modalConvocatoriasInternacional');assert.equal(await card.locator(`input[value="${preview}"]`).count(),1,'URL definitiva idéntica a la vista previa');
 await page.reload();await page.locator('#international-search').fill(name);card=page.locator('[data-international-row]').filter({hasText:name});await card.getByRole('button',{name:'Acciones de '+name}).click();await card.getByRole('button',{name:'Consultar convocatorias y QR'}).click();await page.locator('#modalConvocatoriasInternacional').waitFor({state:'visible'});card=page.locator('#modalConvocatoriasInternacional');const href=await card.getByRole('link',{name:'Abrir formulario'}).last().getAttribute('href');assert.ok(href);
 assert.equal(await card.getByRole('link',{name:'Consultar QR',exact:true}).count(),type==='registro'?1:2);assert.equal(await card.getByRole('link',{name:'Descargar QR',exact:true}).count(),type==='registro'?1:2);
 if(process.env.IMPE_TEST_QR_LIVE==='1'){
  const entry=card.locator('[data-international-campaign]').filter({has:page.locator(`input[value="${preview}"]`)});
  const endpoint=await entry.getByRole('link',{name:'Consultar QR',exact:true}).getAttribute('href');
  const image=await page.request.get(endpoint);assert.equal(image.status(),200);assert.match(image.headers()['content-type'],/image\/png/);
  const modules=process.env.IMPE_TEST_QR_MODULES||path.join(root,'../browser-deps/node_modules');
  const {PNG}=require(path.join(modules,'pngjs'));const jsqr=require(path.join(modules,'jsqr'));const decoded=PNG.sync.read(await image.body());
  assert.equal(jsqr(new Uint8ClampedArray(decoded.data),decoded.width,decoded.height).data,preview,'QR real decodifica exactamente la URL de su convocatoria');
  const download=await page.request.get(endpoint+'&descargar=1');assert.match(download.headers()['content-disposition'],/attachment/);assert.equal(download.status(),200);
 }
 if(process.env.IMPE_TEST_OUTPUT){fs.mkdirSync(process.env.IMPE_TEST_OUTPUT,{recursive:true});for(const width of [1440,390]){await page.setViewportSize({width,height:900});await page.screenshot({path:path.join(process.env.IMPE_TEST_OUTPUT,`internacionales_${type}_${width}.png`),fullPage:true});}}
 assert.equal((await page.request.post(base+'index.php?controller=convocatoria&action=guardar',{form:{...pending,clasificacion:'bachillerato|bachillerato-286'}})).status(),403,'reenvío de referencia consumida no duplica convocatoria');
 const broken=new URL(preview);broken.searchParams.set('convocatoria_ref','0'.repeat(32));assert.equal((await page.request.get(broken.toString())).status(),404);
 const response=await page.request.get(preview);assert.equal(response.status(),200);assert.match(await response.text(),/Bachillerato General/);await page.locator('#modalConvocatoriasInternacional .modal-footer button').click();await page.locator('#modalConvocatoriasInternacional').waitFor({state:'hidden'});
}
await require('./aliados_internacionales_visual.cjs')({page,base,name,id,php,env,root});
await page.goto(base+'__rol?rol=marketing');
const nacional=await page.goto(base+'index.php?controller=convocatoria&action=index&territorio_id=1&tipo=bachillerato');
assert.equal(nacional.status(),200);assert.equal(await page.locator('#modalCrearConvocatoria').count(),1);assert.equal(await page.locator('#modalCrearConvocatoria [name=aliado_internacional_id]').count(),0);
await page.evaluate(()=>bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCrearConvocatoria')).show());await page.locator('#modalCrearConvocatoria').waitFor({state:'visible'});
assert.equal(await page.locator('#modalCrearConvocatoria [name="estados[]"]').inputValue(),'1');
assert.deepEqual(errors,[]);console.log('OK: navegador real alta, edición, convenio, mismo modal, dos convocatorias, URLs persistentes, permisos, CSRF y responsive');
}finally{if(process.env.IMPE_TEST_OUTPUT)fs.copyFileSync(path.join(temp,'php.log'),path.join(process.env.IMPE_TEST_OUTPUT,'international-php.log'));if(browser)await browser.close();server.kill();if(fs.existsSync(uploadRoot)){for(const f of fs.readdirSync(uploadRoot)){if(!beforeUploads.has(f)&&/^convocatoria_[0-9]{14}_[a-f0-9]{12}\.png$/.test(f))fs.unlinkSync(path.join(uploadRoot,f));}}fs.rmSync(temp,{recursive:true,force:true});}})().catch(e=>{console.error(e);process.exitCode=1;});
