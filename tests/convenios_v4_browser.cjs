const assert=require('node:assert/strict'),{spawn}=require('node:child_process'),fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const testRoot=path.resolve(__dirname,'..'),root=process.env.IMPE_V4_APP_ROOT||testRoot,base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8110/',out=process.env.IMPE_V4_OUTPUT||path.join(os.tmpdir(),'impe-v4-visual');fs.mkdirSync(out,{recursive:true});
assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_aliados_v4_[a-z0-9_]+$/);
const privateDir=process.env.IMPE_CONVOCATORIA_DRAFT_DIR||fs.mkdtempSync(path.join(os.tmpdir(),'impe-v4-imagenes-'));
let component=path.join(root,'app/views/convocatorias/partials/aliado_programas_modular.php');
if(!fs.existsSync(component)){
 const resources=fs.mkdtempSync(path.join(os.tmpdir(),'impe-v4-componente-'));component=path.join(resources,'app/views/convocatorias/partials/aliado_programas_modular.php');fs.mkdirSync(path.dirname(component),{recursive:true});fs.copyFileSync(path.join(testRoot,'tests/fixtures/v4_hostinger/aliado_programas_modular.php'),component);
 for(const name of ['public/css/convocatorias_aliado_modular.css','public/javascript/convocatorias_aliado_modular.js','public/css/convocatorias_aliado_multiprograma_v3.css','public/javascript/convocatorias_aliado_multiprograma_v3.js']){const dest=path.join(resources,name);fs.mkdirSync(path.dirname(dest),{recursive:true});fs.copyFileSync(fs.existsSync(path.join(root,name))?path.join(root,name):path.join(testRoot,'tests/fixtures/v3_hostinger',path.basename(name)),dest);}
}
const server=spawn(process.env.PHP_BINARY||'php',['-S',new URL(base).host,'-t',root,path.join(testRoot,'tests/convenios_v4_router.php')],{cwd:root,env:{...process.env,IMPE_TEST_ALLOW_ROUTER:'1',IMPE_V3_COMPONENT_PATH:component,IMPE_CONVOCATORIA_DRAFT_DIR:privateDir},stdio:['ignore','ignore','pipe']});let logs='';server.stderr.on('data',x=>logs+=x);
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
const sample=fs.readFileSync(path.join(root,'public/img/brand/Logo-Sep-Blanco.png'));
const alternate=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jfXkAAAAASUVORK5CYII=','base64');
(async()=>{let browser,context,page,count=0,posts=0,loseImage=false,losePublish=false,delayEditor=false,resumeEditor=null;const errors=[],network=[];const check=(v,m)=>{assert.ok(v,m);count++;console.log('OK '+m);};
const setup=async()=>{
 context=await browser.newContext();page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 page.on('requestfailed',r=>network.push([r.url(),r.failure()?.errorText]));page.on('response',r=>{if(r.status()>=400)network.push([r.url(),r.status()]);});
 page.on('response',async r=>{if(r.url().includes('action=borrador')||r.url().includes('action=editorConvenio'))network.push([r.url(),r.status(),(await r.text()).slice(0,350)]);});
 await context.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url());if(url.origin!==new URL(base).origin)return route.abort();
  if(delayEditor&&url.searchParams.get('action')==='editorConvenio'){delayEditor=false;await new Promise(resolve=>resumeEditor=resolve);return route.continue();}
  if(url.pathname==='/fixture-bootstrap.css')return route.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'});
  if(url.pathname==='/fixture-bootstrap.js')return route.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
  if(url.searchParams.get('action')==='guardarProgramasAliado'&&req.method()==='POST'){
   posts++;if(losePublish){losePublish=false;await route.fetch();return route.abort('failed');}
  }
  if(loseImage&&url.searchParams.get('action')==='borrador'&&/name="operacion"\r?\n\r?\nimagen/.test(req.postData()||'')){loseImage=false;await route.fetch();return route.abort('failed');}
  return route.continue();
 });
 await context.request.get(base+'_v4_session?role=marketing');await page.goto(base+'_v4');
};
const modal=()=>page.locator('#modalCrearConvocatoria'),cards=()=>modal().locator('.impe-aliado-multi-card'),status=()=>modal().locator('[data-v4-status]');
const open=async(name='Instituto Existente')=>{
 const row=page.locator('[data-convenio-notification]').filter({hasText:name}).first();await row.locator('[data-convenio-open]').click();
 await page.locator('#modalConvenioCrearConvocatoria').waitFor({state:'visible'});await page.locator('[data-convenio-create-link]').click();await modal().waitFor({state:'visible',timeout:10000});await cards().first().waitFor();
};
const close=async()=>{await modal().locator('.btn-close').click();await modal().waitFor({state:'detached'});await page.locator('#modalConvenioCrearConvocatoria .btn-close').click();await page.locator('#modalConvenioCrearConvocatoria').waitFor({state:'hidden'});};
const saved=async()=>{await status().filter({hasText:/^Borrador guardado/}).waitFor();};
const stats=async()=>await(await context.request.get(base+'_v4_stats')).json();
const fill=async(index,title)=>{const card=cards().nth(index);await card.locator('input[type=text]').fill(title);const dates=card.locator('input[type=date]');await dates.nth(0).fill('2026-10-22');await dates.nth(1).fill('2026-12-22');};
const upload=async(index,buffer=sample)=>{await cards().nth(index).locator('input[type=file]').setInputFiles({name:'programa-'+index+'.png',mimeType:'image/png',buffer});await modal().locator('[data-v4-status]').filter({hasText:/^Borrador guardado/}).waitFor();};
try{
 for(let i=0;i<70;i++){try{await fetch(base+'_v4_stats');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});await setup();
 if(process.env.IMPE_V4_LATE_ONLY==='1'){
  const row=page.locator('[data-convenio-notification]').filter({hasText:'Instituto Existente'}).first();await row.locator('[data-convenio-open]').click();await page.waitForFunction(()=>!bootstrap.Modal.getInstance(document.getElementById('modalConvenioCrearConvocatoria'))._isTransitioning);
  delayEditor=true;await page.locator('[data-convenio-create-link]').click();await page.waitForFunction(()=>document.querySelector('[data-convenio-create-link]').getAttribute('aria-busy')==='true');
  for(let attempt=0;attempt<500&&!resumeEditor;attempt++)await new Promise(resolve=>setTimeout(resolve,20));assert.ok(resumeEditor,'el endpoint del editor debe llegar a la barrera de red');
  await page.locator('#modalConvenioCrearConvocatoria .btn-close').click();await page.locator('#modalConvenioCrearConvocatoria').waitFor({state:'hidden'});
  await page.locator('[data-convenio-notification]').filter({hasText:'V4 Otro'}).first().locator('[data-convenio-open]').click();resumeEditor();await page.waitForFunction(()=>!document.querySelector('[data-convenio-create-link]').hasAttribute('aria-busy'));
  check(await modal().count()===0,'respuesta tardía de otro aliado no monta un editor antiguo');
  check((await page.locator('[data-convenio-modal-title]').textContent()).includes('V4 Otro'),'el detalle conserva al aliado elegido después de cancelar');
  await page.locator('[data-convenio-create-link]').click();await modal().waitFor({state:'visible'});check(await cards().count()===1&&(await modal().locator('[data-convocatoria-aliado-modulo] h3').textContent()).includes('V4 Otro'),'el nuevo editor sólo contiene los programas del aliado actual');await close();console.log('PASS '+count+' comprobaciones de montaje tardío y aislamiento');return;
 }
 if(process.env.IMPE_V4_OFFLINE_ONLY==='1'){
  await page.locator('#crear_convocatoria_buscar').fill('Instituto Existente');await open();
  await context.setOffline(true);await cards().first().locator('input[type=text]').fill('V4 Pendiente al cerrar');await status().filter({hasText:/Error al guardar/}).waitFor();await modal().locator('.btn-close').click();await status().filter({hasText:/Error al guardar/}).waitFor();check(await modal().isVisible(),'un cierre con error mantiene el formulario y sus campos');
  await context.setOffline(false);await page.evaluate(()=>window.dispatchEvent(new Event('online')));await saved();await close();check(await page.locator('#crear_convocatoria_buscar').inputValue()==='Instituto Existente','se conserva el filtro del directorio al volver del editor');
  await page.goto(base+'_v4?estado_id=2');await page.goto(base+'_v4');await open();check(await cards().first().locator('input[type=text]').inputValue()==='V4 Pendiente al cerrar','cambiar de territorio y volver conserva el borrador del aliado');await close();console.log('PASS '+count+' comprobaciones dirigidas de cierre, conexión y navegación');return;
 }
 if(process.env.IMPE_V4_VISUAL_ONLY==='1'){
  await open();await fill(0,'V4 Borrador recuperable');await upload(0);await saved();
  for(const width of [1440,1024,768,390,360]){
   await page.setViewportSize({width,height:900});await modal().locator('.modal-body').evaluate(el=>el.scrollTop=0);await page.screenshot({path:path.join(out,'editor-'+width+'.png')});
   const bounds=await modal().locator('.modal-dialog').boundingBox();check(bounds.width<=width&&bounds.width>Math.min(1100,width*.9),'ancho institucional proporcionado en '+width);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth&&document.querySelectorAll('.modal.show').length===1),'sin doble modal ni scroll horizontal en '+width);
  }
  await close();console.log('PASS '+count+' comprobaciones visuales V4');return;
 }
 await open();check(await cards().count()===2,'editor V3 compartido con los dos programas autorizados');
 await fill(0,'V4 General conservado');await upload(0);await saved();
 const snapshot=await modal().evaluate(el=>el.__draftV4),preview=await cards().first().locator('.impe-v4-preview img').getAttribute('src'),originalLink=await modal().locator('[data-impe-url]').inputValue();
 check(snapshot.programas['bachillerato-general'].titulo==='V4 General conservado'&&snapshot.programas['bachillerato-general'].completo,'título, fechas, disponibilidad, clasificación y completitud persistidos');
 check(snapshot.completitud['titulacion-experiencia-laboral']===false,'segundo programa incompleto no bloquea el borrador');
 check((await(await context.request.get(preview)).body()).equals(sample),'imagen física y vista previa autorizada recuperan los bytes subidos');
 check((await stats()).convocatorias.length===0&&posts===0,'autoguardado e imagen no disparan solicitudes de publicación');
 await modal().locator('[data-impe-crear-qr]').click();
 const pixels=await modal().locator('[data-impe-qr-canvas]').evaluate(canvas=>({data:Array.from(canvas.getContext('2d').getImageData(0,0,600,600).data),width:600,height:600}));
 const jsqr=require(process.env.JSQR_MODULE_PATH||path.join(bootstrap,'../jsqr'));
 check(jsqr(new Uint8ClampedArray(pixels.data),600,600).data===originalLink,'QR local existente conserva exactamente el enlace original');
 await close();check(page.url()===base+'_v4','cerrar vuelve a Convenios sin navegación ni publicación');
 await open();check(await cards().first().locator('input[type=text]').inputValue()==='V4 General conservado','cerrar y reabrir recupera los campos');
 check(await cards().first().locator('input[type=file]').inputValue()===''&&await cards().first().locator('.impe-v4-preview small').textContent()==='Imagen guardada · programa-0.png','imagen recuperada sin rellenar artificialmente el input file');
 await close();await page.reload();await open();check(await cards().first().locator('input[type=text]').inputValue()==='V4 General conservado','recarga de página recupera el borrador SQL');
 await close();await context.request.get(base+'_v4_session?role=logout');check((await context.request.get(preview)).status()===404,'cerrar sesión revoca acceso a imágenes privadas');await browser.close();browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});await setup();await open();
 check(await cards().first().locator('input[type=text]').inputValue()==='V4 General conservado'&&await cards().first().locator('.impe-v4-preview img').getAttribute('src')===preview,'nuevo navegador/sesión recupera texto e imagen sin almacenamiento local');
 await close();await open('V4 Otro');check(await cards().count()===1&&await cards().first().locator('input[type=text]').inputValue()===''&&!await cards().first().locator('.impe-v4-preview').isVisible(),'otro aliado no recibe campos ni imágenes del anterior');await close();await open();
 await context.request.get(base+'_v4_session?role=other');check((await context.request.get(preview)).status()===404,'otro propietario no puede obtener la vista previa por URL');await context.request.get(base+'_v4_session?role=outside');
 const forbidden=await context.request.post(base+'index.php?controller=crearConvocatoria&action=borrador',{multipart:{csrf_token:'a'.repeat(64),operacion:'abrir',aliado_tipo:'institucional',aliado_id:'10'}});check(forbidden.status()===403,'Cuenta Clave fuera de territorio no abre borradores');await context.request.get(base+'_v4_session?role=marketing');
 const api=base+'index.php?controller=crearConvocatoria&action=borrador';
 const badCsrf=await context.request.post(api,{multipart:{csrf_token:'incorrecto',operacion:'abrir',aliado_tipo:'institucional',aliado_id:'10'}});check(badCsrf.status()===419,'los cambios de borrador exigen token CSRF efectivo');
 const current=await modal().evaluate(el=>el.__draftV4);
 for(const [code,buffer,mime,label] of [['bachillerato-general',Buffer.from('no es una imagen'),'image/png','MIME real incompatible'],['bachillerato-general',Buffer.alloc(5*1024*1024+1),'image/png','límite de 5 MB'],['../bachillerato-general',sample,'image/png','traversal en código de programa']]){
  const res=await context.request.post(api,{multipart:{csrf_token:'a'.repeat(64),operacion:'imagen',id:String(current.id),version:String(current.version),operacion_id:require('node:crypto').randomBytes(32).toString('hex'),programa:code,imagen:{name:'prueba.png',mimeType:mime,buffer}}});check(res.status()===422,'subida rechazada: '+label);
 }
 check((await context.request.get(preview+'&ref=../../config/db_connection.php')).status()===404,'referencias manipuladas no exponen rutas privadas');
 const otherId=await page.locator('[data-convenio-notification]').filter({hasText:'V4 Otro'}).first().evaluate(row=>JSON.parse(row.dataset.convenio).origen_id);
 let formats=(await(await context.request.post(api,{multipart:{csrf_token:'a'.repeat(64),operacion:'abrir',aliado_tipo:'manual',aliado_id:String(otherId)}})).json()).datos.borrador;
 for(const mime of ['image/jpeg','image/webp']){
  const data=await page.evaluate(mime=>{const canvas=document.createElement('canvas');canvas.width=canvas.height=2;canvas.getContext('2d').fillRect(0,0,2,2);return canvas.toDataURL(mime).split(',')[1];},mime);
  const res=await context.request.post(api,{multipart:{csrf_token:'a'.repeat(64),operacion:'imagen',id:String(formats.id),version:String(formats.version),operacion_id:require('node:crypto').randomBytes(32).toString('hex'),programa:'ingles',imagen:{name:'prueba.'+mime.split('/')[1],mimeType:mime,buffer:Buffer.from(data,'base64')}}});check(res.ok(),'imagen real aceptada y persistida: '+mime);formats=(await res.json()).datos;
  check((await context.request.get(formats.imagenes.ingles.url)).headers()['content-type']===mime,'vista previa autorizada devuelve el tipo real '+mime);
 }
 const discarded=await context.request.post(api,{multipart:{csrf_token:'a'.repeat(64),operacion:'descartar',confirmar:'DESCARTAR',id:String(formats.id),version:String(formats.version),operacion_id:require('node:crypto').randomBytes(32).toString('hex')}});check(discarded.ok(),'descartar elimina únicamente las imágenes del borrador elegido');
 const beforeReplace=await stats();loseImage=true;await cards().first().locator('input[type=file]').setInputFiles({name:'reemplazo.png',mimeType:'image/png',buffer:alternate});await status().filter({hasText:/Error al guardar/}).waitFor();await modal().locator('[data-v4-retry]').click();await saved();
 const replaced=await stats();check(replaced.image_count===beforeReplace.image_count&&replaced.physical_images===beforeReplace.physical_images,'reemplazo y reintento tras respuesta perdida no duplican archivos');
 const replacedPreview=await cards().first().locator('.impe-v4-preview img').getAttribute('src');check((await(await context.request.get(replacedPreview)).body()).equals(alternate),'reemplazo restaura la nueva imagen correcta');
 await context.setOffline(true);await cards().first().locator('input[type=text]').fill('V4 General sin conexión');await status().filter({hasText:/Error al guardar/}).waitFor();await modal().locator('.btn-close').click();await status().filter({hasText:/Error al guardar/}).waitFor();check(await modal().isVisible(),'cerrar sin conexión mantiene el editor y permite reintentar sin afirmar guardado');
 await context.setOffline(false);await page.evaluate(()=>window.dispatchEvent(new Event('online')));await saved();check((await modal().evaluate(el=>el.__draftV4)).programas['bachillerato-general'].titulo==='V4 General sin conexión','reconexión sincroniza los cambios pendientes');
 const tab=await context.newPage();await tab.goto(base+'_v4');const tabRow=tab.locator('[data-convenio-notification]').filter({hasText:'Instituto Existente'}).first();await tabRow.locator('[data-convenio-open]').click();await tab.locator('[data-convenio-create-link]').click();await tab.locator('#modalCrearConvocatoria').waitFor({state:'visible'});
 await cards().first().locator('input[type=text]').fill('V4 General versión actual');await saved();await tab.locator('.impe-aliado-multi-card input[type=text]').first().fill('V4 No sobrescribir');await tab.locator('[data-v4-reload]').waitFor({state:'visible'});
 check((await modal().evaluate(el=>el.__draftV4)).programas['bachillerato-general'].titulo==='V4 General versión actual','dos pestañas no sobrescriben silenciosamente la versión actual');await tab.close();
 const publish=modal().locator('[type=submit]');let response=page.waitForResponse(r=>r.url().includes('action=guardarProgramasAliado'));await publish.click();check((await response).status()===422&&(await stats()).convocatorias.length===0,'publicación incompleta falla sin crear convocatorias parciales');
 await fill(1,'V4 Titulación recuperada');await upload(1,sample);await saved();const draftId=await modal().evaluate(el=>el.__draftV4.id);
 await context.request.get(base+'_v4_link?active=0');response=page.waitForResponse(r=>r.url().includes('action=guardarProgramasAliado'));await publish.click();check((await response).status()===422&&(await stats()).drafts.some(d=>Number(d.id)===draftId&&d.estado==='borrador'),'Registro deshabilitado bloquea publicar y mantiene el borrador');await context.request.get(base+'_v4_link?active=1');
 await context.request.get(base+'_v4_fail?active=1');response=page.waitForResponse(r=>r.url().includes('action=guardarProgramasAliado'));await publish.click();check((await response).status()===500&&(await stats()).convocatorias.length===0,'fallo a mitad del lote revierte publicaciones y asociaciones');
 check((await context.request.get(replacedPreview)).ok()&&await modal().isVisible(),'rollback conserva la imagen privada y el borrador recuperable');await context.request.get(base+'_v4_fail?active=0');
 for(const width of [1440,1024,768,390,360]){
  await page.setViewportSize({width,height:900});await page.screenshot({path:path.join(out,'editor-'+width+'.png')});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth&&[...document.querySelectorAll('.modal.show')].length===1),'sin doble modal ni scroll horizontal en '+width);
  const bounds=await modal().locator('.modal-footer').boundingBox();check(bounds.y>=0&&bounds.y+bounds.height<=901,'footer y acciones accesibles en '+width);
 }
 await page.setViewportSize({width:1440,height:900});losePublish=true;const beforePosts=posts;await modal().locator('form').evaluate(form=>{form.requestSubmit();form.requestSubmit();});await status().filter({hasText:/Error al guardar/}).waitFor();
 check((await stats()).convocatorias.length===2&&posts===beforePosts+1,'doble envío sólo confirma un lote aunque se pierda su respuesta HTTP');await publish.click();await modal().waitFor({state:'detached'});
 const finished=await stats();check(finished.convocatorias.length===2&&finished.drafts.some(d=>Number(d.id)===draftId&&d.estado==='publicado'),'reintentar publicación recupera los mismos IDs sin duplicados');
 check(finished.convocatorias.every(c=>c.enlace_registro===originalLink)&&finished.convocatorias.some(c=>c.subtipo_convocatoria==='bachillerato-general')&&finished.convocatorias.some(c=>c.tipo_convocatoria==='titulacion'&&c.subtipo_convocatoria==='experiencia-laboral'),'clasificaciones y enlace compartido correctos en Gestión de Convocatorias');
 check(page.url()===base+'_v4'&&await page.locator('#modalConvenioCrearConvocatoria [data-convenio-modal-attention]').textContent()==='Con convocatoria','éxito permanece en Convenios y actualiza el estado del aliado');
 for(const c of finished.convocatorias)check((await context.request.get(base+c.imagen)).ok(),'imagen definitiva existe antes de confirmar el borrador '+c.programa_codigo);
 await page.locator('#modalConvenioCrearConvocatoria .btn-close').click();await open();await cards().first().locator('input[type=text]').fill('V4 Descartar explícito');await saved();page.once('dialog',dialog=>dialog.accept());await modal().locator('[data-v4-discard]').click();await modal().waitFor({state:'detached'});
 check((await stats()).convocatorias.length===2,'descartar otro borrador no afecta convocatorias publicadas');
 check(errors.length===0,'sin errores JavaScript: '+errors.join('; '));
 console.log('PASS '+count+' comprobaciones V4 de navegador/HTTP/SQL');
}catch(error){if(page&&!page.isClosed()){console.error('ESTADO',page.url(),errors,network.slice(-12),await page.locator('.toast-body,[data-v4-status],[data-impe-error]').allTextContents(),await page.evaluate(()=>({api:typeof window.IMPEConvocatoriaDraftV4,link:document.querySelector('[data-convenio-create-link]')?.href,containers:document.querySelectorAll('[data-v4-container]').length})));await page.screenshot({path:path.join(out,'fallo.png')});}throw error;}
finally{if(browser)await browser.close();server.kill();fs.writeFileSync(path.join(out,'php.log'),logs);if(/PHP (Fatal|Warning|Parse)/.test(logs))throw new Error('Errores PHP: '+logs.slice(-4500));}
})().catch(error=>{console.error(error);process.exit(1);});
