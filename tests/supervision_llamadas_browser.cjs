const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'..'),bootstrap=path.dirname(require.resolve('bootstrap/package.json')),icons=path.dirname(require.resolve('bootstrap-icons/package.json'));
(async()=>{
 const browser=await (process.env.SALES_BROWSER==='webkit'?webkit:chromium).launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{});
 try{
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let mode='';let deny=false;
  await page.route('**/*',async route=>{
   const u=new URL(route.request().url());if(u.hostname!=='supervision.test')return route.abort();
   if(u.searchParams.get('action')==='supervisionDetalle')return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,llamada:{persona:'Persona',observacion:'<img src=x onerror=alert(1)>',resultado_texto:'Conversación con el prospecto'}})});
   if(u.searchParams.get('action')==='supervisionAudio'){
    if(deny)return route.fulfill({status:403,contentType:'text/plain',body:'Acceso revocado'});
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,disponible:true,segmentos:2,segmentos_info:[{indice:0,etiqueta:'Recepción · Ext. 100'},{indice:1,etiqueta:'Asesor · Ext. 150'}]})});
   }
   if(u.pathname==='/'||u.pathname==='/index.php')return route.fulfill({contentType:'text/html',body:execFileSync(process.env.PHP_BINARY||'php',[path.join(root,'tests/supervision_llamadas_vista_fixture.php'),mode],{encoding:'utf8'})});
   const file=u.pathname==='/bootstrap.css'?path.join(bootstrap,'dist/css/bootstrap.min.css'):u.pathname.startsWith('/icons/')?path.join(icons,'font',u.pathname.slice(7)):path.join(root,u.pathname);
   if(fs.existsSync(file))return route.fulfill({path:file});return route.abort();
  });
  for(const width of [1366,768,390,320]){
   await page.setViewportSize({width,height:900});await page.goto('http://supervision.test/');
   assert.equal(await page.locator('[data-call-key]').count(),2);assert.equal(await page.locator('[name=estado_id]').count(),0);
   assert.equal(await page.getByRole('button',{name:/Llamar|Guardar|Registrar/}).count(),0);
   assert(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)),`Desbordamiento a ${width}`);
   await page.locator('[data-supervision-detail]').first().click();await page.locator('dialog[open]').waitFor();
   assert((await page.locator('dialog').innerText()).includes('<img src=x onerror=alert(1)>'));assert.equal(await page.locator('dialog img').count(),0);
   await page.locator('[data-supervision-close]').click();
   await page.locator('[data-supervision-audio]').first().click();await page.locator('[data-supervision-player]').first().locator('audio').first().waitFor({state:'attached'});
   assert.equal(await page.locator('[data-supervision-player]').first().locator('audio').count(),2);
   assert((await page.locator('audio').last().getAttribute('src')).includes('segmento=1'));
   assert(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)),`Reproductor desborda ${width}`);
   if(process.env.SALES_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.SALES_SCREENSHOT_DIR,`supervision_${width}.png`),fullPage:true});
   await page.locator('[data-supervision-audio]').first().click();assert(await page.locator('[data-supervision-player]').first().isHidden());
   await page.evaluate(()=>document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.submitted=Object.fromEntries(new FormData(e.target));}));
   await page.selectOption('[name=persona_id]','301');await page.selectOption('[name=area]','analistas');
   assert.equal(await page.evaluate(()=>window.submitted.persona_id),'0');assert.equal(await page.evaluate(()=>window.submitted.resultado),'');
   console.log(`Supervisión: historial, detalle seguro, dos audios y diseño ${width}px OK`);
  }
  deny=true;await page.goto('http://supervision.test/');await page.locator('[data-supervision-audio]').first().click();await page.getByText('Ya no tienes permiso para consultar esta llamada.').waitFor();
  assert.equal(await page.locator('audio').count(),0);
  mode='sin_audio';await page.goto('http://supervision.test/');assert.equal(await page.locator('[data-supervision-audio]').count(),0);
  assert.deepEqual(errors,[]);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
