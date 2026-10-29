const {chromium}=require('playwright'),assert=require('node:assert/strict'),path=require('node:path');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});try{
 const today=new Date().toLocaleDateString('en-CA',{timeZone:'America/Mexico_City'}),from=new Date(today+'T00:00:00Z');from.setUTCDate(from.getUTCDate()-3);
 const cuts=[['equipo',''],['individual','&persona_id=8&periodo=hoy'],['rango','&persona_id=8&periodo=personalizado&fecha_desde='+from.toISOString().slice(0,10)+'&fecha_hasta='+today],['pagina2','&pagina=2'],['vacio','&periodo=ayer'],['sin-personas','&sin_personas=1'],['personal','&propio=1&persona_id=8']];
 for(const viewport of [{width:1440,height:1050},{width:768,height:1000},{width:390,height:844}]){
  const page=await browser.newPage({viewport});page.setDefaultTimeout(10000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const load=async(url)=>{await page.goto(url);await page.waitForLoadState('networkidle');await page.evaluate(()=>document.fonts.ready);assert(!/Warning:|Fatal error|Parse error/.test(await page.locator('body').textContent()));};
  const content=async()=>page.evaluate(()=>{
   const clean=t=>t.replace(/[\s·]+/g,'');
   return {metrics:[...document.querySelectorAll('.performance-kpi-grid')].slice(0,2).map(g=>[...g.querySelectorAll('article strong')].map(n=>n.textContent.trim())),
    rows:[...document.querySelectorAll('.sales-activity-table')].map(r=>[...r.querySelectorAll('tbody tr')].map(tr=>[...tr.querySelectorAll('td')].map(td=>clean(td.textContent)))),
    results:[...document.querySelectorAll('.sales-activity-results > div')].map(n=>clean(n.textContent)),pdf:document.querySelector('[data-sales-report-pdf]')?.getAttribute('href')?.replace('&antes=1','')};
  });
  for(const [mode,query] of cuts){
   const url=process.env.IMPE_TEST_URL+'index.php?controller=reporte&action=ventas'+query;
   await load(url+'&antes=1');const before=await content();
   await load(url);const after=await content();assert.deepEqual(after,before,'El rediseño conserva todos los valores y filas del corte '+mode);
   assert.equal(await page.locator('.sales-activity-table table:not(.users-table)').count(),0,'Todas las tablas utilizan el componente del sistema');
   assert.equal(await page.locator('[data-sales-activity-form] button:visible').count(),0,'Conservar los filtros automáticos');
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Sólo las tablas deben desplazarse, no la página');
   const headers=await page.locator('.sales-report-table-panel').evaluateAll(nodes=>nodes.map(n=>{
    const p=n.querySelector('header p').getBoundingClientRect(),table=n.querySelector('.sales-activity-table').getBoundingClientRect(),th=n.querySelector('th');
    return {gap:table.top-p.bottom,bg:getComputedStyle(th).backgroundColor,pad:getComputedStyle(th).paddingLeft};
   }));
   for(const header of headers){assert(header.gap>=16,'Separación real entre descripción y tabla');assert.equal(header.bg,'rgb(244, 246, 249)');assert.equal(header.pad,'18px');}
   if(mode==='equipo'){
    assert.equal(await page.locator('.sales-report-people-table tbody tr').count(),3);assert.equal(await page.locator('.sales-report-detail-table tbody tr').count(),25);
    assert(await page.getByRole('navigation',{name:'Páginas de llamadas'}).getByText('Anterior').getAttribute('aria-disabled'));
    const region=page.getByRole('region',{name:'Actividad por asesor',exact:true});await region.screenshot({path:path.join(process.env.IMPE_TEST_AUTO_OUT,'actividad-'+viewport.width+'.png')});
    await page.locator('.sales-report-table-panel').first().screenshot({path:path.join(process.env.IMPE_TEST_AUTO_OUT,'panel-actividad-'+viewport.width+'.png')});
    await page.locator('.sales-report-table-panel').last().screenshot({path:path.join(process.env.IMPE_TEST_AUTO_OUT,'detalle-'+viewport.width+'.png')});
    await page.locator('.sales-activity-results').screenshot({path:path.join(process.env.IMPE_TEST_AUTO_OUT,'resultados-'+viewport.width+'.png')});
    await page.screenshot({path:path.join(process.env.IMPE_TEST_AUTO_OUT,'reporte-'+viewport.width+'.png'),fullPage:true});
   }
   if(mode==='rango')await page.locator('[data-sales-call-schedule]').screenshot({path:path.join(process.env.IMPE_TEST_AUTO_OUT,'horarios-'+viewport.width+'.png')});
   if(mode==='vacio'){
    const empty=page.locator('.sales-report-detail-table .empty-table-message');
    assert(await empty.evaluate(n=>{const r=n.getBoundingClientRect(),p=n.closest('.sales-activity-table').getBoundingClientRect();return r.left>=p.left-1&&r.right<=p.right+1;}),'El mensaje vacío debe quedar visible dentro de la pantalla: '+viewport.width);
    await page.locator('.sales-report-table-panel').last().screenshot({path:path.join(process.env.IMPE_TEST_AUTO_OUT,'vacio-'+viewport.width+'.png')});
   }
   if(mode==='personal')assert.equal(await page.locator('[name=persona_id]').count(),0,'El asesor conserva su consulta personal');
   assert.deepEqual(errors,[]);
  }await page.close();
 }console.log('PASS: datos idénticos antes/después, tablas comunes, separación de encabezados, doble resultado, paginación, vacíos, personal y sin desbordar en escritorio/tablet/móvil.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
