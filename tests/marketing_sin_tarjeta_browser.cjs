// Retirada visual aislada; reutiliza los datos y render de prueba existentes, sin BD del CRM.
const {chromium}=require(process.env.IMPE_PLAYWRIGHT_PATH||'playwright');
const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('node:assert/strict');
const root=process.cwd(),out=path.join(root,'tmp/marketing_sin_tarjeta');fs.mkdirSync(out,{recursive:true});
const file='app/views/dashboard/marketing.php',old=cp.execFileSync('git',['show','7e27f0ae:'+file]);
const beforeFile=path.join(out,'marketing.php');fs.writeFileSync(beforeFile,old);fs.copyFileSync('app/views/dashboard/marketing_indicadores.php',path.join(out,'marketing_indicadores.php'));
const removed=old.toString().match(/<section class="dashboard-panel marketing-expiring-panel">[\s\S]*?<\/section>/)[0];
assert.ok(fs.readFileSync(file,'utf8').replace(/\r\n/g,'\n')===old.toString().replace(removed,'').replace(/\r\n/g,'\n'),'Sólo se elimina el bloque señalado');
const render=before=>{const r=cp.spawnSync('C:/xampp/php/php.exe',['tests/marketing_indicadores_test.php','--render',...(before?['--baseline']:[])],{encoding:'utf8',env:{...process.env,IMPE_KPI_BASELINE_VIEW:beforeFile}});assert.equal(r.status,0,r.stderr);return r.stdout;};
const before=render(true),after=render(false);
const sidebar=cp.spawnSync('C:/xampp/php/php.exe',['-r',`define('BASE_URL','https://marketing-tarjeta.test/');$_SESSION=['rol'=>'Marketing','nombre'=>'Prueba','apellidos'=>'Local'];$nombreCompleto='Prueba Local';function tienePermiso($p){return true;}require 'app/views/layout/sidebar.php';`],{encoding:'utf8'});assert.equal(sidebar.status,0,sidebar.stderr);assert.doesNotMatch(sidebar.stdout,/Warning:|Fatal error:/);
const sheets=['global.css','dashboard.css','recordatorios.css','poblacion_objetivo_educativa.css','marketing_selectores_uniformes.css','dashboard_analista.css','dashboard_analista_refinamientos.css','dashboard_analista_reuniones.css','dashboard_analista_boceto.css','dashboard_analista_boceto_ajustes.css','territorios_resumen_refinamientos.css','convocatorias.css','responsive_sistema.css'];
const html=body=>'<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
 '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">'+
 '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">'+
 '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">'+sheets.map(f=>`<link rel="stylesheet" href="/public/css/${f}">`).join('')+
 '</head><body>'+sidebar.stdout+'<div class="admin-main"><main class="admin-content">'+body+'</main></div></body></html>';
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.IMPE_CHROME_PATH});try{
 const page=await browser.newPage();await page.route('https://marketing-tarjeta.test/**',route=>{const u=new URL(route.request().url());if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html(u.searchParams.has('before')?before:after)});const p=path.join(root,u.pathname);if(!p.startsWith(root)||!fs.existsSync(p))return route.fulfill({status:404});return route.fulfill({body:fs.readFileSync(p),contentType:p.endsWith('.css')?'text/css':'image/png'});});
 const goto=async before=>{await page.goto('https://marketing-tarjeta.test/'+(before?'?before=1':''),{waitUntil:'networkidle'});await page.evaluate(()=>document.fonts.ready);};
 const selectors=['.marketing-welcome-shell','.marketing-key-indicators','.marketing-recent-publications','.marketing-territory-coverage','.marketing-publications-by-type','.marketing-pending-coverage'];
 const snapshot=()=>page.evaluate(selectors=>({overflow:document.documentElement.scrollWidth,widgets:selectors.map(sel=>{const e=document.querySelector(sel),r=e.getBoundingClientRect();return {sel,html:e.outerHTML,y:r.y,width:r.width,height:r.height,styles:Array.from(e.querySelectorAll('*')).map(n=>{const s=getComputedStyle(n);return [s.fontFamily,s.fontSize,s.color,s.padding,s.margin,s.backgroundColor];})};})}),selectors);
 const measurements=[];
 for(const [name,width,height] of [['escritorio',1517,1000],['compacto',1280,1000],['tableta',820,1180],['movil',390,844]]){
  await page.setViewportSize({width,height});await goto(true);const previous=await snapshot();assert.equal(await page.locator('.marketing-expiring-panel').count(),1);
  if(name==='escritorio')await page.screenshot({path:path.join(out,'antes.png')});
  await goto(false);const current=await snapshot();assert.equal(await page.locator('.marketing-expiring-panel').count(),0);assert.equal(await page.getByRole('button',{name:'Ir a convocatorias',exact:true}).count(),0);
  const links=page.locator('.sidebar-link').filter({hasText:'Gestión de Convocatorias'});assert.ok(await links.count()>0);for(const link of await links.all())assert.equal(await link.getAttribute('href'),'https://marketing-tarjeta.test/index.php?controller=convocatoria&action=index');
  assert.ok(current.overflow<=previous.overflow,'No se añade desbordamiento');for(let i=0;i<selectors.length;i++){const {y:oldY,...oldWidget}=previous.widgets[i],{y:newY,...newWidget}=current.widgets[i];assert.deepEqual(newWidget,oldWidget,name+' widget intacto '+selectors[i]);if(i<2)assert.equal(newY,oldY);else assert.ok(newY<oldY,'Se ocupa el espacio retirado');}
  const gap=current.widgets[2].y-current.widgets[1].y-current.widgets[1].height;assert.ok(gap>=16&&gap<=32,name+' separación natural sin hueco: '+gap);
  await page.screenshot({path:path.join(out,name+'.png')});measurements.push({name,gap,widgets:current.widgets.map(({sel,y,width,height})=>({sel,y,width,height}))});
 }
 fs.writeFileSync(path.join(out,'medidas.json'),JSON.stringify(measurements,null,2));console.log('OK: tarjeta y botón retirados, menú intacto, indicadores y otros widgets idénticos, flujo sin hueco en escritorio/tableta/móvil.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
