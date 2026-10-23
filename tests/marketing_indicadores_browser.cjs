// Render PHP real, sin API/BD de producción ni cambios de datos reales.
const {chromium}=require(process.env.IMPE_PLAYWRIGHT_PATH||'playwright');
const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('node:assert/strict');
const root=process.cwd(),out=path.join(root,'tmp/marketing_indicadores');fs.mkdirSync(out,{recursive:true});
const r=cp.spawnSync('C:/xampp/php/php.exe',['tests/marketing_indicadores_test.php','--render'],{encoding:'utf8'});assert.equal(r.status,0,r.stderr);
const baselineFile=path.join(out,'baseline.php');fs.writeFileSync(baselineFile,cp.execFileSync('git',['show','66ecd65a:app/views/dashboard/marketing.php']));
const beforeRender=cp.spawnSync('C:/xampp/php/php.exe',['tests/marketing_indicadores_test.php','--render','--baseline'],{encoding:'utf8',env:{...process.env,IMPE_KPI_BASELINE_VIEW:baselineFile}});assert.equal(beforeRender.status,0,beforeRender.stderr);
const html=body=>'<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
 '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">'+
 '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">'+
 '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">'+
 ['global.css','dashboard.css','convocatorias.css','responsive_sistema.css'].map(f=>'<link rel="stylesheet" href="/public/css/'+f+'">').join('')+
 '</head><body><div class="admin-main"><main class="admin-content">'+body+'</main></div></body></html>';
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.IMPE_CHROME_PATH});try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://marketing-kpi.test/**',async route=>{const u=new URL(route.request().url());if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html(u.searchParams.has('baseline')?beforeRender.stdout:r.stdout)});
  const p=path.join(root,u.pathname);if(!p.startsWith(root)||!fs.existsSync(p))return route.fulfill({status:404});return route.fulfill({contentType:'text/css',body:fs.readFileSync(p)});
 });
 await page.goto('https://marketing-kpi.test/',{waitUntil:'networkidle'});await page.evaluate(()=>document.fonts.ready);
 const panel=page.locator('.marketing-key-indicators');assert.equal(await panel.count(),1);assert.equal(await page.locator('.metric-grid').count(),0);
 assert.equal(await panel.locator('article').count(),5);assert.equal(await panel.locator('[role="progressbar"]').count(),4);
 assert.equal(await panel.locator('[data-marketing-key="publicaciones"] [role="progressbar"]').count(),0);
 const values=await panel.locator('article strong').allTextContents();assert.deepEqual(values,['6','5','6','2 de 32','2']);
 assert.equal(await panel.locator('.marketing-key-details').getAttribute('href'),'https://marketing-kpi.test/index.php?controller=convocatoria&action=index');
 assert.match(await panel.innerText(),/33.3% con convocatoria activa/);assert.doesNotMatch(await panel.innerText(),/objetivo|mensual/i);
 const metrics=[];
 for(const [name,width,height] of [['ancho',2172,1000],['escritorio',1440,1000],['compacto',1280,1000],['tableta',820,1180],['movil',390,844]]){
  const rest=()=>page.evaluate(()=>({scrollWidth:document.documentElement.scrollWidth,widgets:Array.from(document.querySelectorAll('.marketing-welcome-shell,.marketing-expiring-panel,.marketing-territory-coverage,.marketing-pending-list')).map(e=>{const s=getComputedStyle(e);return [e.className,s.width,s.height,s.fontFamily,s.color,s.backgroundColor];})}));
  await page.setViewportSize({width,height});await page.goto('https://marketing-kpi.test/?baseline=1',{waitUntil:'networkidle'});const old=await rest();
  await page.goto('https://marketing-kpi.test/',{waitUntil:'networkidle'});const current=await rest();assert.deepEqual(current.widgets,old.widgets,name+' otros widgets intactos');
  assert.ok(current.scrollWidth<=old.scrollWidth,name+' no añadir desbordamiento a la página');await panel.scrollIntoViewIfNeeded();
  const measure=await panel.evaluate(e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);return {font:s.fontFamily,overflow:document.documentElement.scrollWidth>innerWidth,
   panel:[r.x,r.y,r.width,r.height],items:Array.from(e.children).map(n=>{const b=n.getBoundingClientRect();return [b.x,b.y,b.width,b.height];}),
   clipped:Array.from(e.querySelectorAll('h2,h3,strong,a,p')).filter(n=>n.scrollWidth>n.clientWidth+1).map(n=>n.textContent.trim())};});
  assert.match(measure.font,/Manrope/);assert.ok(measure.panel[0]+measure.panel[2]<=width+1,name+' panel dentro de página');assert.deepEqual(measure.clipped,[],name+' texto completo');
  if(width>=1200)assert.ok(measure.items.every(i=>Math.abs(i[1]-measure.items[0][1])<1),name+' siete secciones horizontales');
  if(width<768)assert.ok(measure.items.every(i=>Math.abs(i[0]-measure.items[0][0])<1),name+' panel vertical único');
  await page.screenshot({path:path.join(out,name+'.png'),fullPage:false});
  if(name==='ancho')await panel.screenshot({path:path.join(out,'panel-ancho.png')});metrics.push({name,...measure});
 }
 assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'medidas.json'),JSON.stringify(metrics,null,2));
 console.log('OK: un panel, cinco indicadores dinámicos, cuatro barras reales, destino existente, Manrope, escritorio/ancho/tableta/móvil y sin recortes. '+out);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
