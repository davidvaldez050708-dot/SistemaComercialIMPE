// Componentes PHP/CSS/JS reales. Datos simulados: no acredita una llamada real.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright'),repo=path.resolve(__dirname,'..');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+['global.css','dashboard.css','responsive_sistema.css'].map(f=>read('public/css/'+f)).join('\n');
// Recursos del mismo sistema, opcionales fuera de CI; nunca cambian el CSS productivo.
const modules=path.dirname(path.resolve(bootstrap));let assetCss='';
for(const w of [400,500,600,700,800]){const p=path.join(modules,'@fontsource/manrope/files/manrope-latin-'+w+'-normal.woff2');if(fs.existsSync(p))assetCss+=`@font-face{font-family:Manrope;font-style:normal;font-weight:${w};src:url(data:font/woff2;base64,${fs.readFileSync(p).toString('base64')}) format('woff2');}`;}
const icons=path.join(modules,'bootstrap-icons/font/bootstrap-icons.min.css');if(fs.existsSync(icons))assetCss+=fs.readFileSync(icons,'utf8').replace(/url\([^)]+\)/g,()=>`url(data:font/woff2;base64,${fs.readFileSync(path.join(modules,'bootstrap-icons/font/fonts/bootstrap-icons.woff2')).toString('base64')})`);
const render=u=>cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_ventas_historial.php'),JSON.stringify(Object.fromEntries(u.searchParams))],{encoding:'utf8'});
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://ventas.test/**',r=>{const u=new URL(r.request().url());return r.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${assetCss}${css}body{margin:0;background:#f5f7fb}main{padding:24px}</style><main>${render(u)}</main>`});});
 await page.goto('http://ventas.test/index.php?controller=telefonia&action=misLlamadas');
 await page.evaluate(()=>document.fonts.ready);
 for(const size of [{width:1440,height:1000},{width:1024,height:900},{width:390,height:844},{width:740,height:360}]){
  await page.setViewportSize(size);await page.waitForTimeout(100);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Sin scroll horizontal '+size.width);
  const cards=page.locator('[data-sales-call-kind]');assert.equal(await cards.count(),5);
  const first=await cards.first().boundingBox();if(size.width>=1024)assert.ok(first.height<165,'Tarjeta compacta: '+first.height);
  const borders=await cards.nth(1).evaluate(e=>{const c=getComputedStyle(e);return[c.borderLeftWidth,c.borderRightWidth,c.borderLeftColor,c.borderRightColor]});
  assert.equal(borders[0],borders[1]);assert.equal(borders[2],borders[3],'Intento sin borde amarillo lateral');
  assert.equal(await cards.nth(1).locator('[data-sales-detail],[data-sales-audio]').count(),0,'Intento no adquiere audio ni registro PBX');
  assert.equal(await cards.first().locator('[data-sales-audio-state="pendiente_resultado"]').count(),1);
  assert.equal(await cards.nth(2).locator('[data-sales-audio][data-segments="2"]').count(),1);
  assert.equal(await cards.nth(3).locator('[data-sales-audio-state="sin_tramo"]').count(),1);
  assert.equal(await cards.nth(4).locator('[data-sales-audio]').count(),0,'Buzón conserva restricción');
  assert.equal((await cards.nth(2).locator('time').innerText()).trim(),'19/10/2026 · 14:37','Formato sin desplazar la hora local');
  assert.equal(await page.locator('.impe-analyst-call-result').count(),0);
  if(size.width===1440){const controls=[];for(const selector of ['.sales-history-filters .module-search','#salesFilter','#salesFrom','#salesUntil','.sales-history-filters button'])controls.push(await page.locator(selector).boundingBox());assert.ok(Math.max(...controls.map(c=>c.y))-Math.min(...controls.map(c=>c.y))<8,'Cinco filtros alineados en escritorio '+JSON.stringify(controls));const stats=await page.locator('.sales-history-stats>div').evaluateAll(es=>es.map(e=>e.getBoundingClientRect().y));assert.equal(new Set(stats).size,1,'Cinco estadísticas en una fila');}
  if(process.env.IMPE_VENTAS_SCREENSHOTS)await page.screenshot({path:path.join(process.env.IMPE_VENTAS_SCREENSHOTS,'ventas_historial_'+size.width+'.png'),fullPage:true});
 }
 await page.setViewportSize({width:1440,height:1000});await page.locator('#salesSearch').fill('David');await page.locator('#salesFilter').selectOption('Saliente');
 await page.locator('#salesFrom').fill('2026-10-01');await page.locator('#salesUntil').fill('2026-10-19');await page.locator('.sales-history-filters button').click();await page.waitForURL('**q=David**');
 let u=new URL(page.url());for(const [k,v] of Object.entries({q:'David',estado:'Saliente',desde:'2026-10-01',hasta:'2026-10-19'}))assert.equal(u.searchParams.get(k),v);
 await page.getByRole('link',{name:'Siguiente',exact:true}).click();await page.waitForURL('**pagina=2**');u=new URL(page.url());assert.equal(u.searchParams.get('q'),'David');assert.equal(u.searchParams.get('desde'),'2026-10-01');
 assert.deepEqual(errors,[]);console.log('Ventas historial: 4 resoluciones, filtros/paginación, tarjetas compactas, hora local y estados de audio: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});


