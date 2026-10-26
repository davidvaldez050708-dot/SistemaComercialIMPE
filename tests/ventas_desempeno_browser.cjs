const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'..'),bootstrap=path.dirname(require.resolve('bootstrap/package.json')),icons=path.dirname(require.resolve('bootstrap-icons/package.json'));
(async()=>{
 const browser=await (process.env.SALES_BROWSER==='webkit'?webkit:chromium).launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{});
 try {
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let role='global';
  const fixture=mode=>execFileSync(process.env.PHP_BINARY||'php',[path.join(root,'tests/ventas_actividad_vista_fixture.php'),mode,role],{encoding:'utf8'});
  await page.route('**/*',async route=>{
   const u=new URL(route.request().url());if(u.hostname!=='ventas.test')return route.abort();
   if(u.pathname==='/'||u.pathname==='/index.php')return route.fulfill({contentType:'text/html',body:fixture(u.searchParams.get('area')==='analistas'?'analistas':'desempeno')});
   const file=u.pathname==='/bootstrap.css'?path.join(bootstrap,'dist/css/bootstrap.min.css'):u.pathname.startsWith('/icons/')?path.join(icons,'font',u.pathname.slice(7)):path.join(root,u.pathname);
   if(fs.existsSync(file))return route.fulfill({path:file});return route.abort();
  });
  for(role of ['own','global','global_long'])for(const size of [{width:1366,height:900},{width:390,height:844},{width:320,height:568},{width:844,height:390}]){
   await page.setViewportSize(size);await page.goto('http://ventas.test/');await page.locator('[data-performance-filter-form]').waitFor();
   const text=await page.locator('body').innerText();assert(!/Warning:|Fatal error/.test(text));
   assert.equal(await page.locator('[data-sales-activity-form]').count(),0,'Desempeño no reutiliza el formulario de reportes');
   assert.equal(await page.locator('.sales-activity-table').count(),0,'Desempeño no es la tabla de reportes');
   assert.equal(await page.locator('.performance-index').count(),role==='own'?0:2);
   assert.equal(await page.locator('.performance-recognition-panel').count(),role==='own'?0:1);
   assert.equal(await page.locator('#performance_area option[value="asesores"]').count(),role==='own'?0:1);
   assert.equal(await page.locator('[data-performance-pdf]').getAttribute('target'),'_blank');
   assert.equal(await page.locator('.performance-person').first().innerText().then(t=>t.includes('María Fernanda')),true);
   assert(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)),`Desbordamiento: ${role} ${size.width}`);
   if(process.env.SALES_SCREENSHOT_DIR&&[1366,390].includes(size.width))await page.screenshot({path:path.join(process.env.SALES_SCREENSHOT_DIR,`desempeno_asesores_${role}_${size.width}.png`)});
   await page.evaluate(()=>document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.submitted=Object.fromEntries(new FormData(e.target));}));
   if(role!=='own'){
    await page.selectOption('#performance_persona','301');await page.selectOption('#performance_estado','1');
    assert.equal(await page.locator('#performance_persona').inputValue(),'0','Cambiar territorio limpia persona anterior');
    await page.selectOption('#performance_persona','301');await page.selectOption('#performance_area','analistas');
    const submitted=await page.evaluate(()=>window.submitted);
    assert.equal(submitted.area,'analistas');assert.equal(submitted.persona_id,'0');assert.equal(submitted.estado_id,'0');
   }
   await page.selectOption('#performance_periodo','personalizado');assert(await page.locator('[data-performance-custom-period]').isVisible());
   assert(!(await page.locator('[data-performance-pdf]').isVisible()),'No exportar el corte anterior con filtros editados');
   console.log(`Desempeño ${role}: ranking, destacados, filtro de área, PDF y diseño ${size.width}x${size.height} OK`);
  }
  role='global';await page.goto('http://ventas.test/?area=analistas');
  assert.equal(await page.locator('#performance_area option[value="asesores"]').count(),1,'Analistas permite cambiar a asesores');
  await Promise.all([page.waitForURL(/area=asesores/),page.selectOption('#performance_area','asesores')]);
  assert.equal(await page.locator('.performance-index').count(),2);
  assert.deepEqual(errors,[]);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
