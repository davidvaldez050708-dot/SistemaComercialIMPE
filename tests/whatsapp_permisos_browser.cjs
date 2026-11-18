const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 try {
  for(const variant of ['canonical','host']) {
   const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   const html=fs.readFileSync(path.join(process.env.IMPE_WA_RENDER_DIR,`roles-${variant}.html`),'utf8');
   await page.route('**/*',r=>r.fulfill({body:'',contentType:'text/javascript'}));
   await page.setContent('<meta charset="utf-8"><script>'+fs.readFileSync(path.join(process.env.BOOTSTRAP_MODULE_PATH,'dist/js/bootstrap.bundle.min.js'),'utf8')+'</script>'+html);
   const box=c=>page.locator(`[data-permission-code="${c}"]`);
   assert.equal(await box('whatsapp.gestionar_conversaciones').count(),1);
   assert.equal(await box('whatsapp.ver_todas').count(),0);
   assert.match(await box('whatsapp.gestionar_conversaciones').locator('..').innerText(),/Consultar todas las conversaciones/);
   assert.equal(await box('whatsapp.gestionar_conversaciones').isChecked(),false);
   await box('whatsapp.responder_todas').check();
   for(const c of ['whatsapp.ver','whatsapp.enviar','whatsapp.gestionar_conversaciones'])assert.equal(await box(c).isChecked(),true,c);
   await box('whatsapp.gestionar_conversaciones').uncheck();
   assert.equal(await box('whatsapp.responder_todas').isChecked(),false);
   await box('whatsapp.responder_todas').check();
   await box('whatsapp.enviar').uncheck();
   assert.equal(await box('whatsapp.responder_todas').isChecked(),false);
   assert.equal(await page.locator('[data-permissions-save]').isDisabled(),false);
   if(variant==='host')assert.equal(await page.locator('#modalEstadoRol').count(),1,'conserva confirmación institucional de cambios de estado');
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log('CHECK_OK navegador Roles: un permiso global, sin duplicado, dependencias automáticas de consulta y envío, cambios guardables y confirmación de estado preservada.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
