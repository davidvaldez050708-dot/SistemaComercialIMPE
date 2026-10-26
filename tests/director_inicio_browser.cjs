const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'..'),bootstrap=path.dirname(require.resolve('bootstrap/package.json')),icons=path.dirname(require.resolve('bootstrap-icons/package.json'));
(async()=>{
 const browser=await (process.env.SALES_BROWSER==='webkit'?webkit:chromium).launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{});
 try{
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let mode='full';
  await page.route('**/*',async route=>{
   const u=new URL(route.request().url());if(u.hostname!=='director.test')return route.abort();
   if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:execFileSync(process.env.PHP_BINARY||'php',[path.join(root,'tests/director_inicio_vista_fixture.php'),mode],{encoding:'utf8'})});
   const file=u.pathname==='/bootstrap.css'?path.join(bootstrap,'dist/css/bootstrap.min.css'):u.pathname.startsWith('/icons/')?path.join(icons,'font',u.pathname.slice(7)):path.join(root,u.pathname);
   if(fs.existsSync(file))return route.fulfill({path:file});return route.abort();
  });
  for(mode of ['full','none','limited','error','zeros'])for(const width of [1366,768,390,320]){
   await page.setViewportSize({width,height:900});await page.goto('http://director.test/');
   const text=await page.locator('body').innerText();assert(!/Warning:|Fatal error/.test(text));
   assert.equal(await page.locator('[data-director-dashboard]').count(),1);
   assert(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)),`Desbordamiento ${mode} ${width}`);
   assert.equal(await page.locator('form,button[data-dial],audio').count(),0,'Inicio de consulta sin acciones telefónicas');
   if(mode==='full'){
    assert.equal(await page.locator('.kam-metric-card').count(),6);assert.equal(await page.locator('.kam-attention-item').count(),6);
    assert.equal(await page.locator('.do-state:visible').count(),8);await page.locator('summary').click();assert.equal(await page.locator('.do-state:visible').count(),32);
    assert((await page.locator('.kam-attention-item').first().innerText()).includes('<script>no ejecutar</script>'));
    assert.equal(await page.locator('[data-director-dashboard] script').count(),0);
    assert((await page.locator('.do-agenda-item').first().getAttribute('href')).includes('reunion_id=1'));
    if(process.env.SALES_SCREENSHOT_DIR&&[1366,390].includes(width))await page.screenshot({path:path.join(process.env.SALES_SCREENSHOT_DIR,`inicio_director_${width}.png`),fullPage:true});
   }
   if(mode==='none')assert(text.includes('Aún no tienes módulos habilitados')&&!text.includes('Actividad telefónica del equipo'));
   if(mode==='limited')assert.equal(await page.locator('.kam-attention-item,.do-access,.do-phone-grid').count(),0);
   if(mode==='error')assert(!text.includes('Vinculación sin casos prioritarios')&&text.includes('Información de prioridades no disponible'));
   if(mode==='zeros')assert(text.includes('Vinculación sin casos prioritarios')&&text.includes('No hay próximas reuniones'));
   console.log(`Inicio Dirección: ${mode} ${width}px OK`);
  }
  assert.deepEqual(errors,[]);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
