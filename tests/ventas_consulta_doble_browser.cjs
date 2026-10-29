const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});try{
 const fixture=JSON.parse(fs.readFileSync(path.join(process.env.IMPE_TEST_CONSULTA_OUT,'datos.json'),'utf8'));
 for(const viewport of [{width:1366,height:900},{width:390,height:844}]){
  const page=await browser.newPage({viewport}),errors=[];page.setDefaultTimeout(8000);page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/index.php?controller=reporte&action=supervisionDetalle*',route=>{const key=new URL(route.request().url()).searchParams.get('llamada');const call=fixture.supervision.find(c=>c.key===key);return route.fulfill({json:{ok:true,llamada:call}});});
  await page.route('**/*action=observacionesVenta*',r=>r.fulfill({json:{ok:true,disponible:true,observaciones:[]}}));
  for(const mode of ['historial','reporte','supervision']){
   await page.goto(process.env.IMPE_TEST_URL+'?vista='+mode);await page.waitForLoadState('networkidle');
   fs.writeFileSync(path.join(process.env.IMPE_TEST_CONSULTA_OUT,mode+'.html'),await page.content());
   assert(!/Warning:|Fatal error|Parse error/.test(await page.locator('body').textContent()),'La vista debe renderizar sin avisos PHP');
   if(mode==='historial'){
    const badges=page.locator('.linkage-call-result-badge.is-result');assert.equal(await badges.count(),2);assert.equal(await badges.nth(0).textContent(),'Prospecto potencial');assert.equal(await badges.nth(1).textContent(),'Se envió por WhatsApp');
   }else if(mode==='reporte'){
    assert((await page.locator('.sales-activity-results').textContent()).includes('Se envió por WhatsApp'));
    assert((await page.getByRole('region',{name:'Detalle de llamadas',exact:true}).textContent()).includes('Prospecto potencial · Se envió por WhatsApp'));
    assert.equal(await page.locator('select[name="resultado"] option[value="WHATSAPP"]').count(),1);
    assert.equal(await page.locator('select[name="resultado"] option[value="ENVIO_WHATSAPP"]').count(),0);
   }else{
    const card=page.locator('[data-call-key]').filter({hasText:'Prospecto potencial · Se envió por WhatsApp'}).first();await card.locator('[data-supervision-detail]').click();
    const dialog=page.getByRole('dialog');await dialog.waitFor({state:'visible'});assert((await dialog.textContent()).includes('Prospecto potencial · Se envió por WhatsApp'));
   }
   assert.deepEqual(errors,[]);await page.screenshot({path:path.join(process.env.IMPE_TEST_CONSULTA_OUT,mode+'-'+viewport.width+'.png'),fullPage:true});
   if(mode!=='reporte')assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'El historial no debe desbordar horizontalmente');
  }await page.close();
 }console.log('PASS: historial con dos etiquetas, reporte y filtro por WhatsApp, detalle real de Supervisión; escritorio y móvil.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
