const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{spawn}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=process.env.IMPE_V5_APP_ROOT||path.resolve(__dirname,'..'),base=process.env.IMPE_BASE_URL||'http://127.0.0.1:8120/',out=process.env.IMPE_V5_OUTPUT;
assert.match(process.env.IMPE_DB_NAME||'',/^impe_test_handly_aliados_v4_v5_[a-z0-9_]+$/);assert.ok(out);fs.mkdirSync(out,{recursive:true});
const server=spawn(process.env.PHP_BINARY||'php',['-S',new URL(base).host,'-t',root,path.join(__dirname,'convocatorias_v5_router.php')],{cwd:root,env:{...process.env,IMPE_TEST_ALLOW_ROUTER:'1'},stdio:['ignore','ignore','pipe']});let logs='';server.stderr.on('data',x=>logs+=x);
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.join(root,'../browser-deps/node_modules/bootstrap');
const endpoint=(action,query={})=>base+'index.php?'+new URLSearchParams({controller:'convocatoria',action,...query});
const tiny=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jfXkAAAAASUVORK5CYII=','base64');
(async()=>{let browser,count=0;const errors=[],check=(v,m)=>{assert.ok(v,m);console.log('OK '+m);count++;};
try{
 for(let i=0;i<80;i++){try{await fetch(base+'_v5_stats');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 const context=await browser.newContext(),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));page.on('dialog',async dialog=>{errors.push(dialog.message());await dialog.dismiss();});
 await context.route('**/*',route=>{const url=new URL(route.request().url());if(url.origin!==new URL(base).origin)return route.abort();if(url.pathname==='/fixture-bootstrap.css')return route.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'});if(url.pathname==='/fixture-bootstrap.js')return route.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),contentType:'text/javascript'});
 const deps=path.resolve(bootstrap,'..');
 if(url.pathname==='/fixture-icons.css')return route.fulfill({path:path.join(deps,'bootstrap-icons/font/bootstrap-icons.css'),contentType:'text/css'});
 if(url.pathname==='/fixture-font.css')return route.fulfill({path:path.join(deps,'@fontsource/manrope/latin.css'),contentType:'text/css'});
 if(/^\/fonts\/bootstrap-icons\.(woff2?|ttf)$/.test(url.pathname))return route.fulfill({path:path.join(deps,'bootstrap-icons/font',url.pathname),contentType:'font/woff2'});
 if(/^\/files\/manrope-latin-[2-8]00-normal\.woff2?$/.test(url.pathname))return route.fulfill({path:path.join(deps,'@fontsource/manrope',url.pathname),contentType:'font/woff2'});
 return route.continue();});
 await context.addInitScript(()=>document.addEventListener('DOMContentLoaded',()=>{for(const href of ['/fixture-icons.css','/fixture-font.css']){const link=document.createElement('link');link.rel='stylesheet';link.href=href;document.head.append(link);}}));
 const session=role=>context.request.get(base+'_v5_session?role='+role),stats=async()=>await(await context.request.get(base+'_v5_stats')).json();
 const list=async(query={})=>await(await context.request.get(endpoint('listadoFiltrado',{territorio_id:'1',tipo:'bachillerato',...query}))).json();
 const go=async(query={})=>{await page.goto(base+'_v5?'+new URLSearchParams({territorio_id:'1',tipo:'bachillerato',...query}));await page.locator('[data-convocatorias-listado] tbody').first().waitFor();};
 await session('marketing');await go();
 if(process.env.IMPE_V5_QR_ONLY==='1'){
  const deps=path.resolve(bootstrap,'..'),decode=require(path.join(deps,'jsqr')),{PNG}=require(path.join(deps,'pngjs'));
  const before=await stats(),posts=[];page.on('request',r=>{if(r.method()==='POST')posts.push(r.url());});
  const current=(await list({buscar:'IQJ'})).convocatorias.find(c=>c.titulo.includes('editada después'));assert.ok(current?.enlace_registro);
  const edit=page.locator('#modalEditarConvocatoria');
  const open=async(c,query={})=>{await go(query);await page.locator(`tr[data-convocatoria-id="${c.id}"] .btn-editar-convocatoria`).click();await edit.waitFor({state:'visible'});await page.waitForFunction(()=>!bootstrap.Modal.getInstance(document.getElementById('modalEditarConvocatoria'))._isTransitioning);};
  const close=async()=>{await edit.locator('.btn-close').click();await edit.waitFor({state:'hidden'});await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));};
  const canvasUrl=async()=>{const url=await edit.locator('canvas').evaluate(c=>c.toDataURL('image/png')),pixels=PNG.sync.read(Buffer.from(url.split(',')[1],'base64'));return decode(pixels.data,pixels.width,pixels.height)?.data;};
  check(await page.locator('.convocatoria-program-heading.convocatoria-month-group-header .convocatoria-month-group-icon.bi-mortarboard').count()>0,'programas reutilizan encabezado e icono institucional');
  check(await page.locator('.convocatoria-program-heading h3').evaluateAll(nodes=>nodes.every(n=>getComputedStyle(n).fontWeight==='800'&&getComputedStyle(n).fontSize==='12.5px')),'títulos de programa conservan tipografía institucional');
  check(await page.locator('.convocatoria-program-heading .convocatoria-month-group-count').evaluateAll(nodes=>nodes.every(n=>/convocatorias?$/.test(n.textContent))),'contadores de programa usan cápsula y texto institucional');
  await open(current);check(await edit.locator('[data-v5-view-qr].btn-system-light').isVisible()&&await edit.locator('[data-v5-download-qr].btn-system-light').isVisible(),'Ver QR y Descargar PNG accesibles en el editor existente');
  check(await edit.locator('.convocatoria-edit-qr').isHidden(),'QR oculto inicialmente sin ocupar espacio');
  await edit.locator('[data-v5-view-qr]').click();check(await canvasUrl()===current.enlace_registro,'QR de Vinculación decodifica exactamente el enlace guardado');
  check(await edit.locator('[data-v5-view-qr]').getAttribute('aria-expanded')==='true'&&await edit.locator('canvas').getAttribute('aria-label')!==null,'panel QR anuncia expansión y texto accesible');
  const download=await Promise.all([page.waitForEvent('download'),edit.locator('[data-v5-download-qr]').click()]);const file=path.join(out,'registro-qr.png');await download[0].saveAs(file);const png=PNG.sync.read(fs.readFileSync(file));
  check(png.width===600&&png.height===600&&decode(png.data,png.width,png.height)?.data===current.enlace_registro,'PNG descargado 600×600 recupera el mismo enlace sin token nuevo');
  check(await edit.evaluate(m=>m.dataset.v5Dirty)==='0','ver y descargar QR no marca la publicación como modificada');
  await close();await open(current);check(await edit.locator('.convocatoria-edit-qr').isHidden()&&await edit.locator('[data-v5-view-qr]').getAttribute('aria-expanded')==='false','reabrir editor limpia el QR anterior');
  await page.evaluate(()=>{window.__testQr=window.qrcode;window.qrcode=undefined;});await edit.locator('[data-v5-view-qr]').click();
  check(await edit.locator('[data-v5-qr-error]').isVisible()&&await edit.locator('.convocatoria-edit-qr').isHidden(),'fallo del generador muestra aviso institucional sin QR obsoleto');
  await page.evaluate(()=>{window.qrcode=window.__testQr;});await edit.locator('[data-v5-view-qr]').click();check(await edit.locator('[data-v5-qr-error]').isHidden()&&await canvasUrl()===current.enlace_registro,'reintento recupera QR y limpia el aviso');
  await page.evaluate(c=>window.IMPEConvocatoriasV5.edit({...c,enlace_registro:''}),current);
  check(await edit.locator('[data-v5-view-qr]').isDisabled()&&await edit.locator('[data-v5-download-qr]').isDisabled()&&await edit.locator('.convocatoria-edit-qr').isHidden(),'sin enlace guardado no se ofrece un QR incorrecto');
  await close();
  const manual=(await list({territorio_id:'2',tipo:'titulacion'})).convocatorias.find(c=>c.aliado?.tipo==='manual'&&c.enlace_registro);assert.ok(manual);check(manual.enlace_registro!==current.enlace_registro,'prueba utiliza dos aliados con enlaces distintos');
  await open(manual,{territorio_id:'2',tipo:'titulacion'});await edit.locator('[data-v5-download-qr]').click();check(await canvasUrl()===manual.enlace_registro,'descarga directa dibuja QR del aliado manual seleccionado');await close();
  for(const width of [1440,1024,768,390,360]){
   await page.setViewportSize({width,height:1000});await go({buscar:'IQJ'});await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:path.join(out,'qr-listado-'+width+'.png'),fullPage:true});
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'encabezados sin desbordamiento a '+width);
   await open(current,{buscar:'IQJ'});await edit.locator('[data-v5-view-qr]').click();await edit.locator('canvas').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(out,'qr-editor-'+width+'.png')});
   check(await edit.locator('.modal-body').evaluate(n=>n.scrollWidth<=n.clientWidth+1)&&await edit.locator('canvas').evaluate(c=>c.getBoundingClientRect().width>=190),'QR legible sin desbordamiento del editor a '+width);await close();
  }
  check(posts.length===0&&JSON.stringify(before)===JSON.stringify(await stats()),'consultar y descargar no envía POST ni modifica publicaciones o relaciones');
  check(errors.length===0&&!/PHP (?:Warning|Fatal|Notice)|Uncaught/.test(logs),'QR y encabezados sin errores JavaScript/PHP');console.log('PASS '+count+' comprobaciones QR y encabezados');return;
 }
 if(process.env.IMPE_V5_CORRECTIONS_ONLY==='1'){
  check(await page.locator('[data-v5-modalidad]').evaluateAll(nodes=>nodes.every(n=>n.classList.contains('convocatoria-status-tab'))),'modalidades reutilizan pestañas institucionales');
  check(await page.locator('[data-convocatorias-listado] [data-v5-consultar-aliado]').count()===0,'sin Consultar aliado en filas del listado');
  check(await page.locator('[data-convocatorias-listado] .convocatoria-date').first().evaluate(n=>getComputedStyle(n).whiteSpace==='nowrap'),'fechas completas sin partir el año');
  await page.locator('#filtro_convocatoria_buscar').fill('IQJ');await page.locator('[data-v5-modalidad="bachillerato|bachillerato-general"]').click();await page.waitForFunction(()=>document.querySelectorAll('tr[data-convocatoria-id]').length===3);
  const current=(await list({buscar:'IQJ',clasificacion:'bachillerato|bachillerato-general'})).convocatorias.find(c=>c.titulo.includes('editada después'));
  assert.ok(current);const row=()=>page.locator(`tr[data-convocatoria-id="${current.id}"]`);
  await row().locator('.btn-ver-convocatoria').click();const panel=page.locator('#offcanvasDetalleConvocatoria');await panel.waitFor({state:'visible'});
  check(await page.locator('#convocatoriaDetalleContenido > .user-detail-tab-content > .user-detail-info-row').count()===11,'todas las filas del detalle comparten el mismo contenedor');
  const positions=await page.locator('#convocatoriaDetalleContenido .user-detail-info-label').evaluateAll(nodes=>nodes.map(n=>Math.round(n.getBoundingClientRect().x)));
  check(new Set(positions).size===1,'etiquetas originales y nuevas alineadas');
  check(await panel.locator('.convocatoria-detail-actions .btn-system-light[data-v5-consultar-aliado]').count()===1,'Consultar aliado es una acción institucional del detalle');
  await panel.locator('[data-v5-consultar-aliado]').click();await page.locator('#modalConvenioCrearConvocatoria.show').waitFor();
  check((await page.locator('[data-convenio-modal-title]').textContent()).includes('IQJ'),'acción abre el convenio del aliado verificado');
  await page.locator('#modalConvenioCrearConvocatoria .btn-close').click();await page.waitForURL(/controller=convocatoria.*action=index/);await panel.waitFor({state:'visible'});
  check(await page.locator('#filtro_convocatoria_buscar').inputValue()==='IQJ'&&await page.locator('[data-convocatoria-clasificacion]').inputValue()==='bachillerato|bachillerato-general','cerrar convenio restaura búsqueda y modalidad de Gestión');
  check((await panel.textContent()).includes(current.titulo),'se reabre el detalle de la convocatoria original');
  await panel.locator('[data-bs-dismiss]').click();await panel.waitFor({state:'hidden'});
  const openEdit=async()=>{await row().locator('.btn-editar-convocatoria').click();await page.locator('#modalEditarConvocatoria').waitFor({state:'visible'});await page.waitForFunction(()=>!bootstrap.Modal.getInstance(document.getElementById('modalEditarConvocatoria'))._isTransitioning);};
  await openEdit();const edit=page.locator('#modalEditarConvocatoria');
  check(await edit.locator('.impe-aliado-multi-card').count()===1,'sólo la publicación seleccionada aparece inicialmente');
  check(await edit.locator('[data-v5-consultar-aliado]').count()===0&&!((await edit.textContent()).includes('Consultar aliado')),'sin enlace improvisado en el editor');
  check(await edit.locator('.impe-aliado-multi-card h4').textContent()==='Bachillerato General','edición reutiliza tarjeta de programa V3');
  check(await edit.locator('[name="enlace_registro"]').getAttribute('readonly')!==null,'enlace de Registro permanece protegido');
  const before=await stats();await edit.locator('[name="titulo"]').fill('Cambio todavía no guardado');await edit.locator('[data-v5-show-others]').click();await edit.locator('[data-v5-other-publications] button').first().waitFor();
  check(await edit.locator('[data-v5-other-publications] button').count()>=2,'otras publicaciones sólo se despliegan a petición del usuario');
  await edit.locator('[data-v5-show-others]').click();check(await edit.locator('[data-v5-other-publications]').isHidden(),'Ocultar otras publicaciones las retira efectivamente');await edit.locator('[data-v5-show-others]').click();await edit.locator('[data-v5-other-publications] button').first().waitFor();
  await edit.locator('[data-v5-other-publications] button').first().click();check((await edit.locator('[role="alert"]').textContent()).includes('Guarda o cancela'),'cambiar de publicación no descarta cambios pendientes');
  await edit.locator('.btn-close').click();await edit.waitFor({state:'hidden'});await openEdit();await edit.locator('[data-v5-show-others]').click();await edit.locator('[data-v5-other-publications] button').first().waitFor();await edit.locator('[data-v5-other-publications] button').first().click();
  await page.waitForFunction(id=>document.querySelector('#modalEditarConvocatoria [name="id"]').value!==String(id),current.id);
  const selectedId=await edit.locator('[name="id"]').inputValue();check(Number(selectedId)!==current.id&&await page.locator('.modal.show').count()===1,'seleccionar otra publicación reutiliza el mismo editor');
  await edit.locator('[name="titulo"]').fill('V5 UI guardado individual');await Promise.all([page.waitForNavigation(),edit.locator('button[type="submit"]').click()]);
  const after=await stats();check(after.rows.find(r=>r.id==selectedId).titulo==='V5 UI guardado individual'&&after.rows.find(r=>r.id==current.id).titulo===current.titulo,'guardar actualiza sólo la publicación elegida');
  check(before.rows.length===after.rows.length&&JSON.stringify(before.links)===JSON.stringify(after.links),'edición conserva número de publicaciones y relaciones');
  await page.goto(base+'_v4');await page.locator('[data-convenio-notification]').filter({hasText:'IQJ'}).first().locator('[data-convenio-open]').click();await page.locator('[data-convenio-create-link]').click();const create=page.locator('#modalCrearConvocatoria');await create.waitFor({state:'visible'});await create.locator('.impe-aliado-multi-card').first().waitFor();
  check(await create.locator('.impe-aliado-multi-card').count()===2,'Nueva convocatoria conserva sus dos programas y componente V3');
  await create.locator('.btn-close').click();await create.waitFor({state:'detached'});
  for(const width of [1440,1024,768,390,360]){
   await page.setViewportSize({width,height:1000});await go({buscar:'IQJ'});await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:path.join(out,'corregido-listado-'+width+'.png'),fullPage:true});
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'listado institucional sin desbordamiento a '+width);
   await openEdit();await page.screenshot({path:path.join(out,'corregido-editor-'+width+'.png')});const box=await edit.locator('button[type="submit"]').boundingBox();check(box.y>=0&&box.y+box.height<=1000,'editor V3 mantiene footer visible a '+width);
   await edit.locator('.btn-close').click();await edit.waitFor({state:'hidden'});await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));
  }
  check(errors.length===0&&!/PHP (?:Warning|Fatal|Notice)|Uncaught/.test(logs),'correcciones sin errores JavaScript/PHP');console.log('PASS '+count+' correcciones visuales, retorno y edición individual');return;
 }
 if(process.env.IMPE_V5_ACTIONS_ONLY==='1'){
  const before=await stats(),record=before.rows.find(c=>c.titulo==='V5 Publicación desde Convenios');assert.ok(record);
  const target=()=>page.locator(`tr[data-convocatoria-id="${record.id}"]`);
  await target().locator('[data-bs-target="#modalEstadoConvocatoria"]').click();const status=page.locator('#modalEstadoConvocatoria');await status.waitFor({state:'visible'});
  check(await status.locator('[name="subtipo"]').inputValue()==='bachillerato-general','cambiar disponibilidad desde Todas usa clasificación real del registro');
  await status.locator('button[type="submit"]').click();await page.waitForURL(/action=index/);
  check((await list({buscar:'V5 Publicación desde Convenios'})).convocatorias[0].estado==0,'publicación pasa al historial tras desactivar');
  await go();await page.locator('[data-convocatoria-view="historial"]').click();await target().locator('[data-bs-target="#modalEstadoConvocatoria"]').click();await status.waitFor({state:'visible'});await status.locator('button[type="submit"]').click();await page.waitForURL(/action=index/);
  check((await list({buscar:'V5 Publicación desde Convenios'})).convocatorias[0].estado==1,'reactivar desde Historial conserva clasificación y registro');
  await go();await target().locator('.btn-editar-convocatoria').click();const edit=page.locator('#modalEditarConvocatoria');await edit.waitFor({state:'visible'});await edit.locator('[name="titulo"]').fill('V5 IQJ editada después de publicar');await edit.locator('input[type="file"]').setInputFiles({name:'iqj-reemplazo.png',mimeType:'image/png',buffer:tiny});await edit.locator('button[type="submit"]').click();await page.waitForURL(/action=index/);
  const after=await stats(),saved=after.rows.find(c=>c.id===record.id);
  check(saved.titulo==='V5 IQJ editada después de publicar'&&saved.imagen!==record.imagen,'convocatoria real V4 permite editar título e imagen');
  check(saved.enlace_registro===record.enlace_registro&&JSON.stringify(after.links)===JSON.stringify(before.links)&&after.rows.length===before.rows.length,'edición V4 conserva token/QR, programa, aliado y número de publicaciones');
  const download=await context.request.get(endpoint('descargarImagen',{id:String(record.id)}));check(download.ok()&&(await download.body()).equals(tiny),'descarga existente devuelve la imagen reemplazada');
  check(errors.length===0&&!/PHP (?:Warning|Fatal|Notice)|Uncaught/.test(logs),'acciones existentes sin errores');console.log('PASS '+count+' comprobaciones de disponibilidad, edición V4 y descarga');return;
 }
 if(process.env.IMPE_V5_VISUAL_ONLY==='1'){
  const row=page.locator('tr[data-convocatoria-id]').filter({hasText:'Título actualizado sin alterar vínculo'}).first();
  for(const width of [1440,1024,768,390,360]){
   await page.setViewportSize({width,height:1000});await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:path.join(out,'gestion-'+width+'.png'),fullPage:true});
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'sin desbordamiento a '+width);
   check(await page.locator('.table-actions').evaluateAll(nodes=>nodes.every(n=>n.getBoundingClientRect().right<=n.closest('td').getBoundingClientRect().right+1)),'acciones completas sin recorte a '+width);
   await row.locator('.btn-editar-convocatoria').click();const modal=page.locator('#modalEditarConvocatoria');await modal.waitFor({state:'visible'});await page.waitForFunction(()=>!bootstrap.Modal.getInstance(document.getElementById('modalEditarConvocatoria'))._isTransitioning);await page.screenshot({path:path.join(out,'editar-'+width+'.png'),fullPage:false});
   const bounds=await modal.locator('button[type="submit"]').boundingBox();check(bounds&&bounds.y>=0&&bounds.y+bounds.height<=1000,'footer dentro de pantalla a '+width);
   await modal.locator('.btn-close').click();await modal.waitFor({state:'hidden'});await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));
  }
  check(errors.length===0&&!/PHP (?:Warning|Fatal|Notice)|Uncaught/.test(logs),'variante sin errores de navegador o PHP');console.log('PASS '+count+' comprobaciones visuales V5');return;
 }
 if(process.env.IMPE_V5_EXTRA_ONLY==='1'){
  check((await list({territorio_id:'2',tipo:'titulacion',buscar:'titulacion ejecutiva'})).convocatorias.length===1,'búsqueda sin acentos conserva comportamiento institucional');
  const response=await list({fecha:'2001-01-01'});check(response.convocatorias.length===0&&Object.keys(response.modalidades).length===0,'fecha sin vigencia limpia filas y contadores');
  await page.goto(base+'_v4?estado_id=2');await page.locator('[data-convenio-notification]').filter({hasText:'V4 Manual'}).first().locator('[data-convenio-open]').click();await page.locator('[data-convenio-create-link]').click();const modal=page.locator('#modalCrearConvocatoria');await modal.waitFor({state:'visible'});const cards=modal.locator('.impe-aliado-multi-card');await cards.first().waitFor();
  for(let i=0;i<await cards.count();i++){const card=cards.nth(i);await card.locator('input[type="text"]').fill('V5 Manual publicada '+i);await card.locator('input[type="date"]').nth(0).fill(new Date().toISOString().slice(0,10));await card.locator('input[type="date"]').nth(1).fill(new Date(Date.now()+86400000*20).toISOString().slice(0,10));await card.locator('input[type="file"]').setInputFiles({name:'manual-'+i+'.png',mimeType:'image/png',buffer:tiny});await modal.locator('[data-v4-status]').filter({hasText:/^Borrador guardado/}).waitFor();}
  await modal.locator('button[type="submit"]').click();await modal.waitFor({state:'detached'});
  const manual=(await list({territorio_id:'2',tipo:'titulacion',clasificacion:'titulacion|experiencia-laboral',buscar:'V5 Manual publicada'})).convocatorias;
  check(manual.length===1&&manual[0].aliado.nombre==='V4 Manual'&&manual[0].aliado.procedencia==='Aliado manual','manual publicado V4 aparece en Guanajuato → Titulación con origen exacto');
  const detailUrl=(await list({buscar:'IQJ'})).convocatorias[0].aliado.detalle_url;await page.goto(detailUrl);await page.locator('#modalConvenioCrearConvocatoria.show').waitFor();check((await page.locator('[data-convenio-modal-title]').textContent()).includes('IQJ'),'Consultar aliado abre el detalle institucional correcto');
  check(errors.length===0&&!/PHP (?:Warning|Fatal|Notice)|Uncaught/.test(logs),'flujos adicionales sin errores');console.log('PASS '+count+' comprobaciones de publicación manual y búsqueda');return;
 }
 check(await page.locator('[data-v5-modalidad]').count()===5,'segmentación visible: Todas y cuatro modalidades Bachillerato');
 let r=await list();check(r.convocatorias.length===new Set(r.convocatorias.map(c=>c.id)).size,'endpoint conserva un ID por convocatoria');
 check(r.convocatorias.some(c=>c.aliado.nombre==='IQJ'&&c.aliado.procedencia==='Vinculación'),'IQJ se identifica desde relaciones reales');
 check(r.convocatorias.some(c=>c.aliado.estado==='sin_asociacion'),'el listado mantiene publicaciones territoriales antiguas');
 const review=page.locator('tr[data-convocatoria-id]').filter({hasText:'Relación contradictoria'});check(await review.locator('.btn-editar-convocatoria').isDisabled(),'relaciones contradictorias se muestran y bloquean edición ordinaria');
 await page.locator('[data-v5-modalidad="bachillerato|ingles"]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-convocatorias-listado] tr[data-convocatoria-id]').length===1);
 check((await page.locator('[data-convocatorias-listado]').textContent()).includes('Instituto Internacional Sintético'),'Inglés sólo presenta su modalidad y aliado internacional');
 check(await page.locator('.btn-editar-convocatoria').isDisabled(),'flujo internacional conserva su gestión protegida');
 await page.locator('[data-v5-modalidad=""]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-convocatorias-listado] tr[data-convocatoria-id]').length>1);
 await page.locator('[data-convocatoria-view="historial"]').click();check(await page.locator('[data-convocatorias-listado] tr[data-convocatoria-id]').count()===1,'historial conserva filtros y desactivadas');
 check(await page.locator('[data-v5-count="bachillerato|bachillerato-general"]').textContent()==='1','contador de modalidad respeta pestaña Historial');
 await page.locator('[data-convocatoria-view="activas"]').click();await page.locator('#filtro_convocatoria_buscar').fill('IQJ');await page.waitForFunction(()=>document.querySelectorAll('[data-convocatorias-listado] tr[data-convocatoria-id]').length===2);
 check((await page.locator('[data-convocatorias-listado]').textContent()).includes('Título sin nombre institucional'),'buscar aliado encuentra título sin su nombre');
 check(await page.locator('[data-v5-count=""]').textContent()==='2','contadores se recalculan tras búsqueda por aliado');
 const iqj=(await list({buscar:'IQJ'})).convocatorias.find(c=>c.titulo==='Título independiente del aliado');
 await page.locator(`tr[data-convocatoria-id="${iqj.id}"] .btn-ver-convocatoria`).click();await page.locator('#offcanvasDetalleConvocatoria.show').waitFor();
 check((await page.locator('#convocatoriaDetalleContenido').textContent()).includes('IQJ')&&(await page.locator('#convocatoriaDetalleContenido').textContent()).includes('Bachillerato General'),'detalle informa aliado, procedencia, programa y ubicación');
 check(await page.locator('#convocatoriaDetalleContenido [data-v5-consultar-aliado]').count()===1,'consulta del aliado disponible con permisos de Convenios');
 await page.locator('#offcanvasDetalleConvocatoria [data-bs-dismiss]').click();await page.locator('#offcanvasDetalleConvocatoria').waitFor({state:'hidden'});
 await page.locator(`tr[data-convocatoria-id="${iqj.id}"] .btn-editar-convocatoria`).click();const edit=page.locator('#modalEditarConvocatoria');await edit.waitFor({state:'visible'});
 check(await edit.locator('[name="enlace_registro"]').getAttribute('readonly')!==null,'editor existente muestra enlace compartido de sólo lectura');
 check((await edit.locator('[data-v5-edit-shell]').textContent()).includes('IQJ'),'editor muestra contexto verificado');
 const before=await stats(),original=before.rows.find(c=>c.id==iqj.id);
 await edit.locator('[name="titulo"]').fill('Título actualizado sin alterar vínculo');await edit.locator('button[type="submit"]').click();await page.waitForURL(/action=index/);
 let after=await stats(),saved=after.rows.find(c=>c.id==iqj.id);check(saved.titulo==='Título actualizado sin alterar vínculo'&&saved.imagen===original.imagen,'editar título persiste y conserva imagen si no se selecciona otra');
 check(saved.enlace_registro===original.enlace_registro&&saved.subtipo_convocatoria===original.subtipo_convocatoria&&JSON.stringify(after.links)===JSON.stringify(before.links),'edición conserva programa, relación y URL/identidad del QR');
 const payload={id:String(iqj.id),territorio_id:'1',tipo:'bachillerato',subtipo:'bachillerato-general',titulo:saved.titulo,enlace_registro:saved.enlace_registro,fecha_inicio:iqj.fecha_inicio,fecha_termino:iqj.fecha_termino,estado:'1','estados[]':'1',csrf_token:'a'.repeat(64)};
 let bad=await context.request.post(endpoint('actualizar'),{form:{...payload,enlace_registro:'https://example.test/ataque'}});check(bad.status()===422,'POST manipulado no sustituye enlace compartido');
 bad=await context.request.post(endpoint('actualizar'),{form:{...payload,subtipo:'ingles'}});check((await stats()).rows.find(c=>c.id==iqj.id).subtipo_convocatoria==='bachillerato-general','POST manipulado no sustituye modalidad');
 await go({buscar:'Título actualizado'});await page.locator(`tr[data-convocatoria-id="${iqj.id}"] .btn-editar-convocatoria`).click();await edit.waitFor({state:'visible'});
 await edit.locator('input[type="file"]').setInputFiles({name:'v5-original.png',mimeType:'image/png',buffer:tiny});await edit.locator('button[type="submit"]').click();await page.waitForURL(/action=index/);
 saved=(await stats()).rows.find(c=>c.id==iqj.id);check(saved.imagen!==original.imagen&&fs.existsSync(path.join(root,saved.imagen)),'reemplazar imagen guarda archivo real y persiste la referencia');
 const confirmedImage=saved.imagen;
 await context.request.get(base+'_v5_failure?active=1');
 bad=await context.request.post(endpoint('actualizar'),{multipart:{...payload,imagen:{name:'fallo.png',mimeType:'image/png',buffer:tiny}},maxRedirects:0});
 await context.request.get(base+'_v5_failure?active=0');
 saved=(await stats()).rows.find(c=>c.id==iqj.id);check(saved.imagen===confirmedImage&&fs.existsSync(path.join(root,confirmedImage)),'fallo de actualización conserva imagen confirmada anterior');
 await session('cc');bad=await context.request.post(endpoint('actualizar'),{form:{...payload,id:'3',territorio_id:'1',tipo:'titulacion',subtipo:'experiencia-laboral'}});check(bad.status()===403,'territorio del POST no permite editar registro de otro territorio');
 bad=await context.request.get(endpoint('detalle',{id:'3'}));check(bad.status()===403,'detalle fuera de territorio denegado');
 bad=await context.request.get(endpoint('descargarImagen',{id:'3'}));check(bad.status()===403,'descarga fuera de territorio denegada');
 check((await list({territorio_id:'2',tipo:'titulacion'})).convocatorias.length===0,'listado AJAX deniega territorio ajeno');
 await session('reader');bad=await context.request.post(endpoint('actualizar'),{form:payload});check(bad.status()===403,'lector sin permiso de edición no modifica');
 check((await list()).convocatorias.every(c=>!c.aliado.detalle_url),'ver convocatorias no concede acceso al directorio de aliados');
 await session('marketing');await go({territorio_id:'2',tipo:'titulacion'});
 check(await page.locator('[data-v5-modalidad]').count()===4,'Titulación ofrece Todas, experiencia, ejecutiva e inscripciones');
 check((await page.locator('[data-convocatorias-listado]').textContent()).includes('V4 Manual'),'manual y programa aparecen en Guanajuato → Titulación');
 await page.locator('[data-v5-modalidad="titulacion|experiencia-laboral"]').click();await page.waitForFunction(()=>document.querySelectorAll('tr[data-convocatoria-id]').length===1);check((await page.locator('[data-convocatorias-listado]').textContent()).includes('Publicación técnica'),'segmentación específica Titulación');
 // Publicación real V4 → clasificación y relación visibles inmediatamente en Gestión.
 await page.goto(base+'_v4');await page.locator('[data-convenio-notification]').filter({hasText:'IQJ'}).first().locator('[data-convenio-open]').click();await page.locator('[data-convenio-create-link]').click();const modal=page.locator('#modalCrearConvocatoria');await modal.waitFor({state:'visible'});
 const cards=modal.locator('.impe-aliado-multi-card');await cards.first().waitFor();
 const draftsBefore=await stats();check(!(await list()).convocatorias.some(c=>c.titulo==='V5 Publicación desde Convenios'),'borrador abierto no aparece como publicación');
 for(let i=0;i<await cards.count();i++){
  const card=cards.nth(i);await card.locator('input[type="text"]').fill(i===0?'V5 Publicación desde Convenios':'V5 Titulación desde Convenios');await card.locator('input[type="date"]').nth(0).fill(new Date().toISOString().slice(0,10));await card.locator('input[type="date"]').nth(1).fill(new Date(Date.now()+86400000*20).toISOString().slice(0,10));await card.locator('input[type="file"]').setInputFiles({name:'v5-'+i+'.png',mimeType:'image/png',buffer:tiny});await modal.locator('[data-v4-status]').filter({hasText:/^Borrador guardado/}).waitFor();
 }
 await modal.locator('button[type="submit"]').click();await modal.waitFor({state:'detached'});check(new URL(page.url()).pathname==='/_v4','publicación V4 permanece en Convenios');
 r=await list({clasificacion:'bachillerato|bachillerato-general',buscar:'V5 Publicación desde Convenios'});check(r.convocatorias.length===1&&r.convocatorias[0].aliado.nombre==='IQJ'&&r.convocatorias[0].aliado.procedencia==='Vinculación','caso obligatorio IQJ publicado V4 → Querétaro → General');
 check(r.convocatorias[0].imagen&&r.convocatorias[0].enlace_registro.includes('action=registro'),'imagen y enlace corresponden a publicación real');
 await go({buscar:'V5 Publicación desde Convenios'});check((await page.locator('[data-convocatorias-listado]').textContent()).includes('IQJ'),'cliente muestra publicación recién creada sin depender del título del aliado');
 for(const width of [1440,1024,768,390,360]){
  await page.setViewportSize({width,height:1000});await go();await page.screenshot({path:path.join(out,'gestion-'+width+'.png'),fullPage:true});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'sin desbordamiento horizontal a '+width);
  await page.locator(`tr[data-convocatoria-id="${iqj.id}"] .btn-editar-convocatoria`).click();await edit.waitFor({state:'visible'});await page.screenshot({path:path.join(out,'editar-'+width+'.png'),fullPage:true});
  check(await edit.locator('button[type="submit"]').isVisible(),'acciones accesibles en editor a '+width);await edit.locator('.btn-close').click();await edit.waitFor({state:'hidden'});
 }
 check(errors.length===0,'sin errores JavaScript: '+errors.join(';'));check(!/PHP (?:Warning|Fatal|Notice)|Uncaught/.test(logs),'sin errores PHP en flujo V5');
 console.log('PASS '+count+' comprobaciones V5 navegador, HTTP y DB');
}catch(e){console.error(e.stack);console.error(logs.slice(-8000));process.exitCode=1;}
finally{if(browser)await browser.close();server.kill();fs.writeFileSync(path.join(out,'php.log'),logs);}
})();
