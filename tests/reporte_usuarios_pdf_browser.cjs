const {chromium}=require('playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.platform==='win32'?{channel:'chrome'}:{})});
 try {
  for(const viewport of [{width:1366,height:900},{width:390,height:844}]) {
   const page=await browser.newPage({viewport,acceptDownloads:true}),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.addInitScript(()=>{const original=window.setTimeout;window.setTimeout=(fn,ms,...args)=>original(fn,ms===60000?120:ms,...args);});
   const pdf=fs.readFileSync(path.join(__dirname,'../tmp/pdfs/usuarios-todos.pdf'));
   let mode='success',requests=0;
   await page.route('http://usuarios.test/**',async route=>{
    const url=new URL(route.request().url());
    if(url.pathname==='/screen')return route.fulfill({contentType:'text/html',body:'<a data-admin-users-pdf download href="/export?roles%5B%5D=3">Exportar PDF</a>'});
    requests++;assert.equal(url.searchParams.get('roles[]'),'3');
    if(mode==='timeout')return;
    if(mode==='forbidden')return route.fulfill({status:403,body:'Prohibido'});
    if(mode==='invalid')return route.fulfill({contentType:'application/pdf',body:'<html>No es PDF</html>'});
    if(mode==='error')return route.fulfill({status:500,body:'Error técnico privado'});
    return route.fulfill({contentType:'application/pdf',headers:{'Content-Disposition':'attachment; filename="Reporte_Usuarios_Corte_2026-11-06.pdf"'},body:pdf});
   });
   await page.goto('http://usuarios.test/screen');await page.addScriptTag({path:path.join(__dirname,'../public/javascript/reporte_usuarios_pdf.js')});
   const button=page.locator('[data-admin-users-pdf]');
   const download=page.waitForEvent('download');await button.click();const result=await download;
   assert.equal(result.suggestedFilename(),'Reporte_Usuarios_Corte_2026-11-06.pdf');assert.equal(requests,1);
   await page.locator('.system-toast').last().waitFor();assert.equal(page.url(),'http://usuarios.test/screen');
   assert.equal(await button.innerText(),'Exportar PDF');
   for(const scenario of ['forbidden','error','invalid','timeout']) {
    mode=scenario;await page.locator('.toast-container').evaluateAll(nodes=>nodes.forEach(n=>n.remove()));
    await button.click();await page.locator('.system-toast-error').waitFor({state:'visible'});
    assert.equal(await button.innerText(),'Exportar PDF');assert.equal(await button.getAttribute('aria-busy'),null);
    assert.equal(page.url(),'http://usuarios.test/screen');assert(!await page.locator('.system-toast-error').innerText().then(t=>t.includes('técnico privado')));
   }
   assert.deepEqual(errors,[]);await page.close();
  }
 } finally {await browser.close();}
 console.log('PASS: descarga, filtros, permanencia en pantalla, permisos, PDF inválido, error y timeout con toast institucional en escritorio/móvil.');
})().catch(e=>{console.error(e);process.exitCode=1;});
