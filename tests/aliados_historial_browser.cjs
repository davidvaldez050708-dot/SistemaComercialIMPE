/* Vista real; catálogo y transporte simulados. Las autorizaciones se prueban además con SQL real. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),base='http://127.0.0.1:8135/',php=process.env.PHP_BINARY||'php';
const deps=process.env.BROWSER_DEPS||path.resolve(root,'../browser-deps/node_modules'),out=process.env.IMPE_HISTORIAL_OUTPUT;
const render=q=>execFileSync(php,[path.join(root,'tests/aliados_historial_preview.php'),q],{encoding:'utf8'});
(async()=>{let browser,count=0;const errors=[],requests=[];let delay=false,resume;
 const check=(v,m)=>{assert.ok(v,m);console.log('OK '+m);count++;};
 try{
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
  const context=await browser.newContext(),page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/*',async route=>{
   const req=route.request(),u=new URL(req.url());if(u.origin!==new URL(base).origin)return route.abort();
   if(u.searchParams.get('action')==='historial'){
    requests.push({method:req.method(),kind:u.searchParams.get('origen_tipo'),id:u.searchParams.get('id')});
    if(delay&&u.searchParams.get('origen_tipo')==='manual'){delay=false;await new Promise(r=>resume=r);}
    return route.fulfill({contentType:'application/json',body:render('preview=historial&origen_tipo='+u.searchParams.get('origen_tipo'))});
   }
   if(u.pathname==='/index.php')return route.fulfill({body:render(u.search.slice(1)),contentType:'text/html'});
   const map={'/fixture-bootstrap.css':'bootstrap/dist/css/bootstrap.min.css','/fixture-bootstrap.js':'bootstrap/dist/js/bootstrap.bundle.min.js','/fixture-font.css':'@fontsource/manrope/latin.css','/fixture-icons.css':'bootstrap-icons/font/bootstrap-icons.css'};
   let file=map[u.pathname]?path.join(deps,map[u.pathname]):path.join(root,u.pathname);
   if(u.pathname.startsWith('/fonts/'))file=path.join(deps,'bootstrap-icons/font',u.pathname);
   if(u.pathname.startsWith('/files/'))file=path.join(deps,'@fontsource/manrope',u.pathname);
   if(!fs.existsSync(file))return route.fulfill({status:404,body:''});
   return route.fulfill({path:file,contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':undefined});
  });
  const modal=page.locator('#modalAliadoHistorial'),rows=page.locator('[data-aliado-row]'),row=kind=>rows.filter({has:page.locator('[data-aliado-share-origen="'+kind+'"]')});
  const go=async(q='')=>{await page.goto(base+'index.php?'+q);await rows.first().waitFor();await page.evaluate(()=>document.fonts.ready);};
  const open=async kind=>{const r=rows.filter({has:page.locator('[data-aliado-history-origen="'+kind+'"]')});await r.locator('.aliados-more-button').click();await page.locator('[data-aliado-history-origen="'+kind+'"]').click();await modal.waitFor({state:'visible'});await modal.locator('.aliados-history-item').first().waitFor();await page.waitForFunction(()=>!bootstrap.Modal.getInstance(document.getElementById('modalAliadoHistorial'))._isTransitioning);};
  const close=async()=>{await modal.locator('.modal-footer button').click();await modal.waitFor({state:'hidden'});};
  const capture=async name=>{if(out){fs.mkdirSync(out,{recursive:true});await page.screenshot({path:path.join(out,name+'.png'),fullPage:true});}};
  for(const width of [1440,1366,1024,768,390,360]){
   await page.setViewportSize({width,height:900});await go();
   check(await rows.evaluateAll(rs=>rs.every(r=>{const items=[...r.querySelector('.aliados-actions').children];return items[0].matches('[data-registro-abrir]')&&items[1].matches('[data-aliado-share]')&&items[2].matches('.aliados-actions-menu');})),'Programas, Compartir, Más en ambos orígenes '+width);
   check(await row('manual').locator('.aliados-last-send').textContent().then(s=>s.includes('Bachillerato General')),'última difusión manual visible '+width);
   check(await row('manual').locator('td').nth(5).textContent().then(s=>s.includes('1 de 2 compartidas')),'avance manual separado de la respuesta '+width);
   check(await page.locator('[data-manual-difusion-form],#modalManualHistorial').count()===0,'sin formulario duplicado de difusión '+width);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'sin desbordamiento de página '+width);await capture('directorio-'+width);
   await open('manual');
   check(await modal.locator('.aliados-history-item').count()===3,'historial manual completo '+width);
   check(await modal.locator('form,input,select,[type=submit]').count()===0,'historial sólo de lectura '+width);
   check(await modal.locator('.aliados-history-item').first().textContent().then(s=>s.includes('Campus A · BACHILLERATO EXAMEN ÚNICO IEPFI')&&s.includes('Susana Molina')&&s.includes('contacto@example.test')),'programa, título, responsable y destino íntegros '+width);
   check(await modal.locator('.aliados-history-item').nth(1).textContent().then(s=>s.includes('confirmado manualmente')&&s.includes('Compartida')),'WhatsApp identificado como confirmación manual '+width);
   check(await modal.locator('script').count()===0&&(await modal.locator('.aliados-history-item').last().textContent()).includes('<script>prueba</script>'),'notas históricas escapadas '+width);
   await capture('historial-manual-'+width);await close();await open('institucional');
   check(await modal.locator('.aliados-history-item').count()===1&&(await modal.locator('[data-aliado-history-context]').textContent()).includes('Celayense'),'institucional no mezcla IDs del manual '+width);
   await capture('historial-institucional-'+width);await close();
  }
  await page.setViewportSize({width:1440,height:900});await go('role=consulta');
  check(await page.locator('[data-registro-abrir],[data-aliado-share]').count()===0,'consulta no recibe acciones operativas');await open('manual');check(await modal.locator('.aliados-history-item').count()===3,'permiso de historial independiente de gestionar programas');await close();
  await go('role=sin-historial');check(await page.locator('[data-aliado-history]').count()===0,'sin permiso no aparece historial');
  await go();delay=true;const r=rows.filter({has:page.locator('[data-aliado-history-origen="manual"]')});await r.locator('.aliados-more-button').click();await page.locator('[data-aliado-history-origen="manual"]').click();while(!resume)await page.waitForTimeout(20);
  await page.waitForFunction(()=>!bootstrap.Modal.getInstance(document.getElementById('modalAliadoHistorial'))._isTransitioning);await close();await open('institucional');resume();await page.waitForTimeout(150);
  check((await modal.locator('[data-aliado-history-context]').textContent()).includes('Celayense'),'respuesta tardía no cambia al aliado equivocado');await close();
  check(requests.every(r=>r.method==='GET'&&['manual','institucional'].includes(r.kind)&&r.id==='1'),'consulta usa identidad explícita y no realiza envíos');
  check(errors.length===0,'sin errores JavaScript: '+errors.join(';'));console.log('PASS '+count+' comprobaciones visuales/lectura de historial');
 }finally{if(browser)await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
