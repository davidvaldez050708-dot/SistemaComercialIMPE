/* Vista PHP real + componentes V3/V4 reales. Transporte de borradores simulado, sin BD. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),app=process.env.IMPE_TABLA_APP_ROOT||root;
const base='http://127.0.0.1:8134/',php=process.env.PHP_BINARY||'php';
const deps=process.env.BROWSER_DEPS||path.resolve(root,'../browser-deps/node_modules');
const out=process.env.IMPE_TABLA_OUTPUT;
const render=query=>execFileSync(php,[path.join(root,'tests/convenios_tabla_preview.php'),query],{env:{...process.env,IMPE_TABLA_APP_ROOT:app},encoding:'utf8'});
(async()=>{
 let browser,count=0;const check=(v,m)=>{assert.ok(v,m);console.log('OK '+m);count++;};
 const drafts=new Map(),errors=[],calls=[];let delayEditor=false,resumeEditor=null;
 try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
  const context=await browser.newContext(),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/*',async route=>{
   const req=route.request(),url=new URL(req.url()),action=url.searchParams.get('action');
   if(url.origin!==new URL(base).origin)return route.abort();
   if(action==='borrador'){
    const post=req.postData()||'',field=name=>post.match(new RegExp('name="'+name+'"\\r?\\n\\r?\\n([^\\r\\n]*)'))?.[1];
    const op=field('operacion');calls.push(op);
    let draft;
    if(op==='abrir'){
     const key=field('aliado_tipo')+':'+field('aliado_id');
     if(!drafts.has(key))drafts.set(key,{id:drafts.size+1,version:1,propietario_id:4,programas:{},imagenes:{},estado:'borrador',actualizado_at:'2026-10-23 18:30:00'});
     draft=drafts.get(key);
    }else{
     draft=[...drafts.values()].find(d=>d.id===Number(field('id')));assert.ok(draft);
     if(op==='guardar'){draft.programas=JSON.parse(field('programas'));draft.version++;}
    }
    return route.fulfill({json:{ok:true,datos:op==='abrir'?{borrador:draft}:draft}});
   }
   if(action==='editorConvenio'){
    if(delayEditor){delayEditor=false;await new Promise(r=>resumeEditor=r);}
    return route.fulfill({json:{ok:true,datos:{html:render('preview=editor&aliado_tipo='+url.searchParams.get('aliado_tipo')+'&aliado_id='+url.searchParams.get('aliado_id'))}}});
   }
   if(action==='qrDiseno')return route.fulfill({json:{ok:true,datos:{version:0,config:{}}}});
   if(url.pathname==='/index.php')return route.fulfill({body:render(url.search.slice(1)),contentType:'text/html'});
   const staticFiles={
    '/fixture-bootstrap.css':'bootstrap/dist/css/bootstrap.min.css','/fixture-bootstrap.js':'bootstrap/dist/js/bootstrap.bundle.min.js',
    '/fixture-icons.css':'bootstrap-icons/font/bootstrap-icons.css','/fixture-font.css':'@fontsource/manrope/latin.css'
   };
   let file=staticFiles[url.pathname]?path.join(deps,staticFiles[url.pathname]):path.join(app,url.pathname);
   if(url.pathname.startsWith('/fonts/'))file=path.join(deps,'bootstrap-icons/font',url.pathname);
   if(url.pathname.startsWith('/files/'))file=path.join(deps,'@fontsource/manrope',url.pathname);
   if(!fs.existsSync(file)&&/convocatorias_aliado_modular\.(js|css)$/.test(url.pathname))file=path.join(root,'tests/fixtures/v3_hostinger',path.basename(url.pathname));
   if(!fs.existsSync(file))return route.fulfill({status:404,body:''});
   return route.fulfill({path:file,contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':undefined});
  });
  const go=async(q='')=>{await page.goto(base+'index.php?estado_id=11&'+q);await page.locator('[data-convenio-notification]').first().waitFor();await page.evaluate(()=>document.fonts.ready);};
  const detail=page.locator('#modalConvenioCrearConvocatoria'),editor=page.locator('#modalCrearConvocatoria');
  const row=title=>page.locator('[data-convenio-notification]').filter({hasText:title}).first();
  const closeDetail=async()=>{await detail.locator('.btn-close').click();await detail.waitFor({state:'hidden'});};
  const capture=async name=>{if(out){await page.waitForFunction(()=>[...document.querySelectorAll('.modal.show')].every(m=>!bootstrap.Modal.getInstance(m)?._isTransitioning));fs.mkdirSync(out,{recursive:true});await page.screenshot({path:path.join(out,name+'.png'),fullPage:true});}};
  for(const width of [1440,1366,1024,768,390,360]){
   await page.setViewportSize({width,height:900});await go();
   check(await page.locator('[data-convenio-group]').count()===2,'grupos por municipio '+width);
   check(await page.locator('thead').evaluateAll(nodes=>nodes.every(n=>n.querySelectorAll('th').length===6)),'seis columnas con encabezado compartido '+width);
   check(await page.locator('[data-convenio-notification]').evaluateAll(nodes=>nodes.every(n=>n.tagName==='TR'&&n.children.length===6&&!n.hasAttribute('tabindex'))),'filas semánticas, sin botón implícito '+width);
   const columns=await page.locator('thead tr').evaluateAll(rows=>rows.map(r=>[...r.cells].map(c=>c.getBoundingClientRect().width)));
   check(columns[0].every((w,i)=>Math.abs(w-columns[1][i])<1),'columnas alineadas entre municipios '+width);
   check(await page.locator('[data-convenio-notification]').evaluateAll(rows=>rows.every(r=>r.querySelectorAll('[data-convenio-open]').length===1&&r.querySelectorAll('[data-convenio-create-row]').length===1)),'ojo y crear convocatoria por fila '+width);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'sin desbordamiento de página '+width);
   check(await row('PRUEBA').locator('[data-convenio-open]').evaluate(b=>b.querySelector('.bi-eye')&&b.getAttribute('aria-label').includes('PRUEBA')),'ojo accesible '+width);
   await capture('tabla-'+width);
   const eye=row('PRUEBA').locator('[data-convenio-open]');await eye.focus();await eye.press('Enter');await detail.waitFor({state:'visible'});
   check(await detail.locator('[data-convenio-create-link],a,form,input').count()===0,'detalle sólo de consulta '+width);
   check(await detail.locator('[data-convenio-modal-title]').textContent()==='PRUEBA','detalle corresponde al aliado '+width);
   check(await detail.locator('.aliados-source-chip').count()===2,'programas completos e independientes '+width);
   check((await detail.locator('[data-convenio-modal-notes]').textContent()).includes('<script>alert(1)</script>'),'notas como texto escapado '+width);
   await capture('detalle-'+width);await closeDetail();
  }
  await page.setViewportSize({width:1440,height:900});await go();
  await page.locator('[data-convenio-search]').fill('PRUEBA');
  check(await page.locator('[data-convenio-group]:visible').count()===1&&await page.locator('[data-convenio-group-count]:visible').textContent()==='1 convenio','búsqueda oculta grupos y actualiza conteo');
  await page.locator('[data-convenio-search]').fill('');await page.locator('[data-convenio-municipio-filter]').selectOption('2');await page.locator('[data-convenio-origin-filter]').selectOption('institucional');
  check(await page.locator('[data-convenio-group]:visible').count()===0&&await page.locator('[data-convenio-empty-filter]').isVisible(),'filtros combinados y vacío');
  await page.locator('[data-convenio-clear-filters]').click();check(await page.locator('[data-convenio-group]:visible').count()===2,'limpiar restaura ambos municipios');
  await page.locator('[data-convenio-search]').fill('PRUEBA');
  const direct=row('PRUEBA').locator('[data-convenio-create-row]');
  const target=new URL(await direct.getAttribute('href'));
  check(target.searchParams.get('aliado_tipo')==='manual'&&target.searchParams.get('aliado_id')==='1'&&target.searchParams.get('territorio_id')==='11','ruta directa conserva origen, identidad y estado');
  await direct.click();await editor.waitFor({state:'visible'});await editor.locator('.impe-aliado-multi-card').first().waitFor();
  check(!await detail.isVisible()&&await page.locator('.modal.show').count()===1,'creación directa abre un único editor, sin detalle');
  check(await editor.locator('.modal-footer [data-bs-dismiss]').textContent()==='Volver a convenios','acción de regreso coherente');
  await editor.locator('.impe-aliado-multi-card input[type=text]').first().fill('Prueba de borrador desde fila');
  await editor.locator('[data-v4-status]').filter({hasText:/Borrador guardado/}).waitFor();
  await editor.locator('.modal-footer [data-bs-dismiss]').click();await editor.waitFor({state:'detached'});
  check(!await detail.isVisible()&&await page.locator('[data-convenio-search]').inputValue()==='PRUEBA','cerrar vuelve al listado con su filtro');
  check(await direct.evaluate(a=>a===document.activeElement)&&await direct.textContent()==='Crear convocatoria','foco y etiqueta de creación conservados');
  await direct.click();await editor.waitFor({state:'visible'});await editor.locator('.impe-aliado-multi-card').first().waitFor();
  check(await editor.locator('.impe-aliado-multi-card input[type=text]').first().inputValue()==='Prueba de borrador desde fila','reabrir recupera borrador');
  await editor.locator('[data-impe-crear-qr]').click();check(await editor.locator('[data-impe-qr-canvas]').isVisible(),'QR compartido disponible al crear desde fila');
  await capture('editor-directo');await editor.locator('.btn-close').click();await editor.waitFor({state:'detached'});
  await go();await row('Celayense').locator('[data-convenio-create-row]').click();await editor.waitFor({state:'visible'});await editor.locator('.impe-aliado-multi-card').first().waitFor();
  check(await editor.locator('.impe-aliado-multi-card input[type=text]').first().inputValue()==='','aliado institucional no recibe campos del manual');await editor.locator('.btn-close').click();await editor.waitFor({state:'detached'});
  delayEditor=true;await row('PRUEBA').locator('[data-convenio-create-row]').click();await page.waitForFunction(()=>document.querySelector('[aria-busy="true"]'));
  await page.locator('[data-convenio-search]').fill('Celayense');while(!resumeEditor)await page.waitForTimeout(20);resumeEditor();await page.waitForFunction(()=>!document.querySelector('[aria-busy="true"]'));
  check(await editor.count()===0,'respuesta tardía de una fila filtrada no abre un editor equivocado');
  await go('role=consulta');check(await page.locator('[data-convenio-create-row]').count()===0&&await page.locator('[data-convenio-open]').count()===3,'permiso de consulta no muestra creación');
  await row('PRUEBA').locator('[data-convenio-open]').click();await detail.waitFor({state:'visible'});check(await detail.locator('a').count()===0,'consulta tampoco tiene creación dentro del detalle');await closeDetail();
  const back=base+'index.php?controller=convocatoria&action=index&territorio_id=11&busqueda=prueba';
  await go('detalle_tipo=manual&detalle_id=1&volver_gestion='+encodeURIComponent(back));await detail.waitFor({state:'visible'});await detail.locator('.btn-close').click();await page.waitForURL(back);
  check(page.url()===back,'cerrar consulta desde Gestión conserva el retorno completo');
  check(calls.includes('guardar')&&errors.length===0,'autoguardado y navegación sin errores JavaScript: '+errors.join(';'));
  console.log('PASS '+count+' comprobaciones de tabla, accesibilidad, filtros y editor (transporte simulado)');
 }finally{if(browser)await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
