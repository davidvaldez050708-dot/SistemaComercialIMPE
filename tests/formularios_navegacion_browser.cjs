const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const css=read('public/css/formularios.css'),delta=read('tools/formularios_navegacion_compacta.css');
assert(css.endsWith(delta));
const baseCSS=css.slice(0,-delta.length),output=process.env.IMPE_FORMS_SCREENSHOTS||path.join(root,'tmp/formularios-navegacion');fs.mkdirSync(output,{recursive:true});
function render(view,share){
 const r=cp.spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'fixtures/formularios_navegacion.php'),view,share?'1':'0'],{cwd:root,encoding:'utf8'});
 assert.equal(r.status,0,r.stderr);assert.equal(r.stderr,'');return r.stdout;
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 try{
  const context=await browser.newContext(),errors=[];let compact=true;
  await context.route('**/*',route=>{
   const u=new URL(route.request().url());
   if(process.env.IMPE_FORMS_ASSETS){
    const asset=path.join(process.env.IMPE_FORMS_ASSETS,path.basename(u.pathname));
    if(fs.existsSync(asset)&&fs.statSync(asset).isFile())return route.fulfill({contentType:asset.endsWith('.css')?'text/css':'font/'+(asset.endsWith('.ttf')?'ttf':'woff2'),body:fs.readFileSync(asset)});
   }
   if(u.pathname==='/bootstrap.css')return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(process.env.BOOTSTRAP_MODULE_PATH,'dist/css/bootstrap.min.css'),'utf8')});
   if(u.pathname.startsWith('/public/'))return route.fulfill({contentType:'text/css',body:u.pathname.endsWith('formularios.css')?(compact?css:baseCSS):read(u.pathname.slice(1))});
   const view=u.searchParams.get('vista')||'inicio',share=u.searchParams.get('compartir')!=='0';
   return route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8">'+(process.env.IMPE_FORMS_ASSETS?'<link rel="stylesheet" href="/manrope.css"><link rel="stylesheet" href="/icons.css">':'')+'<link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><link rel="stylesheet" href="/public/css/formularios.css"><style>body{margin:0;background:#f5f7fa}main{padding:28px;max-width:1560px;margin:auto}@media(max-width:575px){main{padding:16px}}</style><main>'+render(view,share)+'</main>'});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  for(const width of [1440,1024,768,390,320]){
   await page.setViewportSize({width,height:1000});await page.goto('https://formularios.test/?vista=inicio');
   await page.evaluate(()=>document.fonts.ready);
   assert.equal(await page.locator('.formularios-option-card').count(),2);
   assert.equal(await page.locator('.formularios-hero-visual').isVisible(),width>767);
   const originalHome=await page.locator('.formularios-hero,.formularios-option-card,.formularios-option-copy,.formularios-option-action').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);return {w:r.width,h:r.height,font:s.fontSize,display:s.display,padding:s.padding};}));
   compact=false;await page.reload();await page.evaluate(()=>document.fonts.ready);
   assert.deepEqual(await page.locator('.formularios-hero,.formularios-option-card,.formularios-option-copy,.formularios-option-action').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);return {w:r.width,h:r.height,font:s.fontSize,display:s.display,padding:s.padding};})),originalHome,'entrada conserva exactamente el diseño original');
   compact=true;await page.reload();await page.evaluate(()=>document.fonts.ready);
   for(const card of await page.locator('.formularios-option-card').all()){
    const rect=await card.boundingBox();assert(rect.height>200,JSON.stringify({width,rect}));
    assert.equal(await card.locator('.formularios-card-features').isVisible(),true);
    assert.equal(await card.locator('.formularios-option-action').isVisible(),true);
   }
   assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth+1),true,'inicio sin desbordamiento');
   const routes=await page.locator('.formularios-option-card').evaluateAll(cards=>cards.map(c=>c.href));
   assert(routes[0].includes('action=registro')&&routes[1].includes('action=inscripcion'));
   if(width===1440||width===390)await page.screenshot({path:path.join(output,'inicio-'+width+'.png'),fullPage:true});
   await page.goto('https://formularios.test/?vista=programas');
   await page.evaluate(()=>document.fonts.ready);
   const cards=page.locator('.formularios-inscripcion-programa');assert.equal(await cards.count(),5);
   for(const card of await cards.all()){
    assert.equal(await card.locator('.formularios-inscripcion-programa-copy > p').isVisible(),true,'descripción original conservada');
    assert.equal(await card.locator('.formularios-inscripcion-pendiente').isVisible(),true,'encabezado original conservado');
    const buttons=card.locator('.formularios-inscripcion-programa-action > a');assert.equal(await buttons.count(),2);
    const first=await buttons.nth(0).boundingBox(),second=await buttons.nth(1).boundingBox();
    assert(first.height<=48&&second.height<=48);
    assert(second.x>=first.x+first.width+5||second.y>=first.y+first.height+5,'acciones con separaciÃ³n real');
    assert((await buttons.nth(0).getAttribute('href')).includes('action=inscripcion'));
    assert((await buttons.nth(1).getAttribute('href')).includes('ya-estas-inscrito/?programa='));
    assert.equal(await buttons.nth(1).getAttribute('target'),'_blank');assert.equal(await buttons.nth(1).getAttribute('rel'),'noopener');
   }
   assert.equal(await page.locator('body').evaluate(e=>e.scrollWidth<=innerWidth+1),true,'catÃ¡logo sin desbordamiento');
   if(width===1440||width===390)await page.screenshot({path:path.join(output,'programas-'+width+'.png'),fullPage:true});
   await page.goto('https://formularios.test/?vista=programas&compartir=0');assert.equal(await page.locator('a[href*="ya-estas-inscrito"]').count(),0,'compartir continÃºa sujeto al permiso real');
  }
  // La captura interna mantiene exactamente sus estilos y controles.
  await page.setViewportSize({width:1440,height:1000});
  const properties=()=>page.locator('.formularios-detail-hero,input[name="nombre"],button[type="submit"]').evaluateAll(els=>els.map(el=>{
   const s=getComputedStyle(el),r=el.getBoundingClientRect();return {tag:el.tagName,w:r.width,h:r.height,font:s.fontSize,padding:s.padding,color:s.color,background:s.backgroundColor};
  }));
  compact=false;await page.goto('https://formularios.test/?vista=registro');const before=await properties();
  compact=true;await page.goto('https://formularios.test/?vista=registro');assert.deepEqual(await properties(),before,'captura interna sin alteraciones');
  assert.deepEqual(errors,[]);console.log('CHECK_OK visual y funcional: cinco anchos, tarjetas originales conservadas, botones compactos con separación de 6 px, rutas, permisos de compartir y captura interna intactos.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
