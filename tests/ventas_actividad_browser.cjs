const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'..');
const bootstrap=path.dirname(require.resolve('bootstrap/package.json'));
const icons=path.dirname(require.resolve('bootstrap-icons/package.json'));
(async()=>{
 const browser=await (process.env.SALES_BROWSER==='webkit'?webkit:chromium).launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{});
 try {
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  let html='';
  await page.route('**/*',async route=>{
   const u=new URL(route.request().url());if(u.hostname!=='ventas.test')return route.abort();
   if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   const file=u.pathname==='/bootstrap.css'?path.join(bootstrap,'dist/css/bootstrap.min.css'):u.pathname.startsWith('/icons/')?path.join(icons,'font',u.pathname.slice(7)):path.join(root,u.pathname);
   if(fs.existsSync(file))return route.fulfill({path:file});return route.abort();
  });
  for(const role of ['own','global','global_long']){
   html=execFileSync(process.env.PHP_BINARY||'php',[path.join(root,'tests/ventas_actividad_vista_fixture.php'),'html',role],{encoding:'utf8'});
   assert(!html.includes('Warning:')&&!html.includes('Fatal error'));
   for(const size of [{width:1366,height:900},{width:390,height:844},{width:320,height:568},{width:844,height:390}]){
    await page.setViewportSize(size);await page.goto('http://ventas.test/');
    await page.locator('[data-sales-activity-form]').waitFor();
    assert.equal(await page.locator('#salesAdvisor').count(),role.startsWith('global')?1:0);
    assert.equal(await page.locator('[data-sales-custom]:visible').count(),role==='global_long'?2:0);
    assert(await page.locator('[data-sales-report-pdf]').isVisible());
    assert.equal(await page.locator('[data-sales-report-pdf]').getAttribute('target'),'_blank');
    assert.equal(await page.locator('.performance-view-tabs').count(),0,'Reportes no debe incluir navegación de Desempeño ni otras áreas');
    await page.locator('#salesPeriod').selectOption('personalizado');
    assert.equal(await page.locator('[data-sales-custom]:visible').count(),2);
    assert(await page.locator('#salesFrom').evaluate(e=>e.required&&!e.disabled));
    await page.locator('#salesFrom').fill('2026-10-01');await page.locator('#salesTo').fill('2026-10-02');
    await page.locator('#salesPeriod').selectOption('semana');
    assert(await page.locator('#salesFrom').evaluate(e=>!e.required&&e.disabled));
    assert(!(await page.locator('[data-sales-report-pdf]').isVisible()));
    assert(await page.locator('[data-sales-dirty-note]').isVisible());
    const days=await page.locator('.performance-day').count();assert(days>0&&days<=20);
    assert((await page.locator('body').innerText()).includes('Observación <script>no ejecutar</script>'));
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);
    assert(!overflow,`Desbordamiento de página: ${role} ${size.width}`);
    for(const selector of ['.performance-hero','.performance-filter-panel','.performance-kpi-grid','.sales-activity-panel']){
      const boxes=await page.locator(selector).evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return {x:r.x,right:r.right,width:r.width};}));
      assert(boxes.every(b=>b.x>=0&&b.right<=size.width+1&&b.width>0),JSON.stringify({selector,boxes}));
    }
    if(process.env.SALES_SCREENSHOT_DIR){await page.reload();}
    if(process.env.SALES_SCREENSHOT_DIR&&size.width===390)await page.screenshot({path:path.join(process.env.SALES_SCREENSHOT_DIR,`asesores_${role}_movil.png`)});
    if(process.env.SALES_SCREENSHOT_DIR&&size.width===1366)await page.screenshot({path:path.join(process.env.SALES_SCREENSHOT_DIR,`asesores_${role}_web.png`)});
    console.log(`Vista ${role}: filtros, PDF, contenido escapado y diseño ${size.width}x${size.height} OK`);
   }
  }
  assert.deepEqual(errors,[]);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
