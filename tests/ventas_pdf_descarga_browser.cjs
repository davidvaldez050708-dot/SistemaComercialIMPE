const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'..'),bootstrap=path.dirname(require.resolve('bootstrap/package.json')),icons=path.dirname(require.resolve('bootstrap-icons/package.json'));
(async()=>{
 const browser=await (process.env.SALES_BROWSER==='webkit'?webkit:chromium).launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{});
 try {
  const context=await browser.newContext({acceptDownloads:true}),page=await context.newPage();
  let mode='html',downloads=0,phoneLoads=0,screenLoads=0,failure=false,extended=false;
  const created=[];context.on('page',p=>created.push(p));
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
   const u=new URL(route.request().url());if(u.hostname!=='ventas.test')return route.abort();
   if(u.pathname==='/shell')return route.fulfill({contentType:'text/html',body:`<!doctype html><html><body><div data-shell-navigation-mask hidden><span data-shell-navigation-message></span><button data-shell-navigation-retry hidden></button></div><iframe id="phone" src="/phone"></iframe><iframe id="impeCrmFrame" style="width:100%;height:900px"></iframe><script>window.IMPE_PHONE_SHELL_CONFIG={userId:11,homeUrl:'http://ventas.test/index.php?controller=reporte&action=ventas'};</script><script src="/public/javascript/telefonia_shell_navegacion.js"></script></body></html>`});
   if(u.pathname==='/phone'){phoneLoads++;return route.fulfill({contentType:'text/html',body:'<!doctype html><title>Teléfono de prueba persistente</title>'});}
   if(u.pathname==='/index.php'){
    if(['exportarPdf','ventasPdf'].includes(u.searchParams.get('action'))){
     downloads++;
     if(failure)return route.fulfill({status:403,contentType:'text/html',body:'<html>Acceso rechazado</html>'});
     return route.fulfill({contentType:'application/pdf',headers:{'Content-Disposition':extended?"attachment; filename=Fallback.pdf; filename*=UTF-8''Desempe%C3%B1o.pdf":'attachment; filename="Nombre_del_servidor.pdf"'},body:'%PDF-1.4\n% archivo de prueba de transporte\n%%EOF'});
    }
    screenLoads++;
    const html=execFileSync(process.env.PHP_BINARY||'php',[path.join(root,'tests/ventas_actividad_vista_fixture.php'),mode,'global'],{encoding:'utf8'}).replace('</body>','<div class="admin-shell"></div></body>');
    return route.fulfill({contentType:'text/html',body:html});
   }
   const file=u.pathname==='/bootstrap.css'?path.join(bootstrap,'dist/css/bootstrap.min.css'):u.pathname.startsWith('/icons/')?path.join(icons,'font',u.pathname.slice(7)):path.join(root,u.pathname);
   if(fs.existsSync(file))return route.fulfill({path:file});return route.abort();
  });
  await page.clock.install();
  for(mode of ['html','desempeno','analistas']){
   await page.goto('http://ventas.test/shell');
   const frame=page.frameLocator('#impeCrmFrame'),link=frame.locator('[data-sales-report-pdf],[data-performance-pdf]');
   await link.waitFor();await page.waitForFunction(()=>document.querySelector('#impeCrmFrame')?.contentDocument?.readyState==='complete');
   assert.equal(await link.getAttribute('target'),null);
   assert(await page.locator('[data-shell-navigation-mask]').isHidden(),'Documento inicial reconocido por el shell');
   const phones=phoneLoads,screens=screenLoads,files=downloads,opened=created.length;
   const before=await frame.locator('h1').innerText();
   const [download]=await Promise.all([page.waitForEvent('download'),link.click()]);
   assert.equal(download.suggestedFilename(),'Nombre_del_servidor.pdf','Conservar el nombre que calcula PHP');
   assert.equal(downloads,files+1);assert.equal(await download.failure(),null);
   await page.clock.fastForward(21000);
   // Edge puede abrir su panel interno de descargas; ningún enlace del CRM abre pestañas.
   assert(created.slice(opened).every(p=>p.url().startsWith('edge://downloads-hub/')),'No abrir ventanas o pestañas del documento');
   assert.equal(context.pages().filter(p=>!p.url().startsWith('edge://downloads-hub/')).length,1,'Conservar una sola pestaña del CRM');
   assert.equal(phoneLoads,phones,'No reemplazar el contexto persistente del teléfono');
   assert.equal(screenLoads,screens,'No sustituir la pantalla ni recargar el CRM');
   assert.equal(await frame.locator('h1').innerText(),before);
   assert(await page.locator('[data-shell-navigation-mask]').isHidden(),'Descargar no activa recuperación de navegación');
   failure=true;
   await link.click();await frame.locator('[data-pdf-download-error]:visible').waitFor();
   assert.equal(await link.getAttribute('aria-busy'),null);
   assert.equal(screenLoads,screens);assert.equal(phoneLoads,phones);
   assert(!(await frame.locator('[data-pdf-download-error]').innerText()).includes('<html>'));
   failure=false;extended=true;
   const [retry]=await Promise.all([page.waitForEvent('download'),link.click()]);
   assert.equal(await retry.failure(),null);assert.equal(retry.suggestedFilename(),'Desempeño.pdf');extended=false;assert(await frame.locator('[data-pdf-download-error]').isHidden());
   console.log(`PDF ${mode}: descarga única, nombre del servidor, sin pestañas, CRM y teléfono persistentes OK`);
  }
  assert.deepEqual(errors,[]);await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
