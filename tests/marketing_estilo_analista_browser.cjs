const {chromium}=require(process.env.IMPE_PLAYWRIGHT_PATH||'playwright');
const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('node:assert/strict');
const root=process.cwd(),out=path.join(root,'tmp/marketing_estilo_analista');fs.mkdirSync(out,{recursive:true});
// Se reutiliza el render aislado existente; no se lee ni modifica la BD del CRM.
const rendered=cp.spawnSync('C:/xampp/php/php.exe',['tests/marketing_indicadores_test.php','--render'],{encoding:'utf8'});assert.equal(rendered.status,0,rendered.stderr);
const referenceSource=fs.readFileSync('app/views/dashboard/analista.php','utf8');
const referenceBlock=referenceSource.match(/<section class="analyst-kpi-panel">[\s\S]*?<\/section>/)[0];
// La referencia ejecuta el fragmento real del Analista, con valores de la captura del usuario.
const referenceFile=path.join(out,'referencia.php');fs.writeFileSync(referenceFile,`<?php
$esc=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');$baseMetrica=1299;$seguimientoIndexUrl='#';
$metricasClave=[['valor'=>1299,'etiqueta'=>'En seguimiento','ayuda'=>'Carga activa actual','clase'=>''],['valor'=>0,'etiqueta'=>'Para hoy','ayuda'=>'Acciones y reuniones del día','clase'=>''],['valor'=>0,'etiqueta'=>'Atrasados','ayuda'=>'Requieren atención prioritaria','clase'=>'is-overdue'],['valor'=>0,'etiqueta'=>'Reuniones próximas','ayuda'=>'Siguientes 7 días','clase'=>'']];
?><div class="analyst-dashboard analyst-dashboard-v2">${referenceBlock}</div>`);
const ref=cp.spawnSync('C:/xampp/php/php.exe',[referenceFile],{encoding:'utf8'});assert.equal(ref.status,0,ref.stderr);
const oldCss=cp.execFileSync('git',['show','04e2ada1:public/css/convocatorias.css']);
const sheets=['global.css','dashboard.css','recordatorios.css','poblacion_objetivo_educativa.css','marketing_selectores_uniformes.css','dashboard_analista.css','dashboard_analista_refinamientos.css','dashboard_analista_reuniones.css','dashboard_analista_boceto.css','dashboard_analista_boceto_ajustes.css','territorios_resumen_refinamientos.css','convocatorias.css','responsive_sistema.css'];
const html=(before,reference)=>'<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
 '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">'+
 '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">'+
 '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">'+
 sheets.map(f=>`<link rel="stylesheet" href="/public/css/${f}${before?'?before=1':''}">`).join('')+
 '</head><body><div class="admin-main"><main class="admin-content">'+(reference?ref.stdout:rendered.stdout)+'</main></div></body></html>';
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.IMPE_CHROME_PATH});try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://marketing-kpi.test/**',async route=>{const u=new URL(route.request().url());if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html(u.searchParams.has('before'),u.searchParams.has('reference'))});
 const p=path.join(root,u.pathname);if(!p.startsWith(root)||!fs.existsSync(p))return route.fulfill({status:404});
 return route.fulfill({contentType:'text/css',body:u.pathname.endsWith('/convocatorias.css')&&u.searchParams.has('before')?oldCss:fs.readFileSync(p)});});
 const goto=async query=>{await page.goto('https://marketing-kpi.test/?'+query,{waitUntil:'networkidle'});await page.evaluate(()=>document.fonts.ready);};
 const style=async(selector,properties)=>page.locator(selector).first().evaluate((e,keys)=>Object.fromEntries(keys.map(k=>[k,getComputedStyle(e)[k]])),properties);
 const typography=['fontFamily','fontSize','fontWeight','lineHeight','color','letterSpacing'];
 const pairs=[['h2','.analyst-kpi-heading h2',[...typography,'marginBottom']],['.marketing-key-intro p','.analyst-kpi-heading p',typography],['strong','.metric-value',[...typography,'marginBottom']],['h3','.metric-label',typography],['.marketing-key-item > p','.analyst-metric-help',[...typography,'marginTop']],['.marketing-key-bar','.analyst-metric-track',['height','backgroundColor','borderRadius','marginTop']],['.marketing-key-bar > span','.analyst-metric-track > span',['backgroundColor','borderRadius']],['.marketing-key-details','.analyst-kpi-action a',[...typography,'minHeight','padding','border','borderRadius','backgroundColor','gap']],['.marketing-key-details > i:first-child','.analyst-kpi-action-icon',['width','height','borderRadius','backgroundColor']]];
 const snapshot=()=>page.locator('.marketing-key-indicators').evaluate(e=>({text:e.textContent,href:e.querySelector('a')?.getAttribute('href'),bars:Array.from(e.querySelectorAll('[role=progressbar]')).map(b=>[b.outerHTML])}));
 const rest=()=>page.evaluate(()=>({width:document.documentElement.scrollWidth,widgets:Array.from(document.querySelectorAll('.marketing-welcome-shell,.marketing-expiring-panel,.marketing-territory-coverage,.marketing-pending-list')).map(e=>{const s=getComputedStyle(e);return [e.className,s.width,s.height,s.fontFamily,s.color,s.backgroundColor];})}));
 const results=[];
 for(const [name,width,height] of [['ancho',2172,1000],['escritorio',1517,1000],['medio',1440,1000],['compacto',1280,1000],['limite',1200,1000],['tableta',820,1180],['movil',390,844],['estrecho',360,844],['minimo',320,844]]){
  await page.setViewportSize({width,height});await goto('reference=1&before=1');const reference=[];for(const [,r,props]of pairs)reference.push(await style(r,props));
  await goto('reference=1');for(let i=0;i<pairs.length;i++)assert.deepEqual(await style(pairs[i][1],pairs[i][2]),reference[i],name+' Analista intacto');
  if(name==='escritorio')await page.locator('.analyst-kpi-panel').screenshot({path:path.join(out,'referencia-analista.png')});
  await goto('before=1');const before=await snapshot(),oldRest=await rest();
  if(name==='escritorio')await page.locator('.marketing-key-indicators').screenshot({path:path.join(out,'marketing-antes.png')});
  await goto('');assert.deepEqual(await snapshot(),before,name+' valores, textos, porcentajes y ruta intactos');const currentRest=await rest();assert.deepEqual(currentRest.widgets,oldRest.widgets,name+' otros widgets intactos');assert.ok(currentRest.width<=oldRest.width,name+' sin añadir overflow');
  for(let i=0;i<pairs.length;i++)assert.deepEqual(await style('.marketing-key-indicators '+pairs[i][0],pairs[i][2]),reference[i],name+' estilo '+pairs[i][0]);
  const panel=page.locator('.marketing-key-indicators');assert.equal(await panel.locator('article').count(),5);assert.equal(await panel.locator('[role=progressbar]').count(),4);assert.equal(await panel.locator('[data-marketing-key=publicaciones] [role=progressbar]').count(),0);
  await panel.scrollIntoViewIfNeeded();const measure=await panel.evaluate(e=>{const r=e.getBoundingClientRect();return {rect:[r.x,r.y,r.width,r.height],items:Array.from(e.children).map(n=>{const b=n.getBoundingClientRect();return[b.x,b.y,b.width,b.height];}),clipped:Array.from(e.querySelectorAll('h2,h3,strong,a,p')).filter(n=>n.scrollWidth>n.clientWidth+1).map(n=>n.textContent.trim())};});
  assert.ok(measure.rect[0]+measure.rect[2]<=width+1,name+' ancho');assert.deepEqual(measure.clipped,[],name+' texto completo');if(width>=1200)assert.ok(measure.items.every((i,n,a)=>!n||i[0]>a[n-1][0]),name+' horizontal');
  if(width>=1200) {
   const bars=await panel.locator('.marketing-key-bar').evaluateAll(nodes=>nodes.map(n=>n.getBoundingClientRect().y));
   assert.ok(Math.max(...bars)-Math.min(...bars)<1,name+' barras alineadas');
  }
  await panel.screenshot({path:path.join(out,'marketing-'+name+'.png')});results.push({name,width,measure,styles:reference});
 }
 assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'comparacion.json'),JSON.stringify(results,null,2));console.log('OK: CSS calculado idéntico al Analista; nueve tamaños, cinco indicadores, datos/porcentajes/ruta y otros widgets intactos. '+out);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
