const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
(async()=>{
 const out=process.env.IMPE_TEST_CONSULTA_OUT,fixture=JSON.parse(fs.readFileSync(path.join(out,'datos.json'),'utf8'));
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  for(const viewport of [{width:1366,height:950},{width:390,height:844}]){
   const page=await browser.newPage({viewport}),errors=[];page.on('pageerror',e=>errors.push(e.message));page.setDefaultTimeout(8000);
   for(const mode of ['hoy','ayer','semana','rango','vacio','equipo']){
    await page.goto(process.env.IMPE_TEST_URL+'?vista='+mode);await page.waitForLoadState('networkidle');
    const body=await page.locator('body').textContent();assert(!/Warning:|Fatal error|Parse error/.test(body),body);
    assert(!body.includes('Conexiones confirmadas'));assert(body.includes('Buzones de voz'));
    const card=page.locator('.performance-kpi-grid').first().locator('article').nth(2);
    assert.equal(await card.locator('strong').textContent(),String(fixture[mode].resumen.buzones));
    const section=page.locator('[data-sales-call-schedule]');
    if(mode==='equipo')assert.equal(await section.count(),0,'Sin horario individual en el reporte del equipo');
    else{
     assert((await section.textContent()).includes('Hora de México'));
     if(['hoy','ayer','vacio'].includes(mode)){
      const values=section.locator('.sales-call-boundaries article strong');assert.equal(await values.count(),2);
      for(const [i,key] of ['primera_llamada','ultima_llamada'].entries())assert.equal(await values.nth(i).textContent(),fixture[mode].dias[0][key]?.slice(11)||'—');
      if(viewport.width<600){const boxes=await section.locator('.sales-call-boundaries article').evaluateAll(nodes=>nodes.map(n=>({x:n.getBoundingClientRect().x,y:n.getBoundingClientRect().y,width:n.getBoundingClientRect().width})));assert.equal(boxes[0].x,boxes[1].x);assert(boxes[1].y>boxes[0].y);}
     }else{
      const rows=section.locator('tbody tr');assert.equal(await rows.count(),fixture[mode].dias.length);
      for(const [i,d] of fixture[mode].dias.entries()){
       const cells=await rows.nth(i).locator('td').allTextContents();assert.deepEqual(cells,[d.fecha,String(d.llamadas),d.primera_llamada?.slice(11)||'—',d.ultima_llamada?.slice(11)||'—']);
      }
     }
     if(['hoy','rango','vacio'].includes(mode))await section.screenshot({path:path.join(out,mode+'-'+viewport.width+'.png')});
    }
    assert.deepEqual(errors,[]);assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'El reporte debe conservar el ancho de pantalla y desplazar sólo sus tablas');
   }
   await page.close();
  }
  console.log('PASS: buzones, tarjetas Hoy/Ayer, rango y semana por día, días vacíos, equipo sin horario individual, coherencia y ancho en escritorio/móvil.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
