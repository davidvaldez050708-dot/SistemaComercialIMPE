const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright'),repo=path.resolve(__dirname,'..');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json')),read=p=>fs.readFileSync(path.join(repo,p),'utf8');
assert.ok(read('public/css/telefonia_ventas_ui.css').replace(/\r\n/g,'\n').startsWith(read('tools/ventas_pr108_hostinger/after/public/css/telefonia_ventas_ui.css').replace(/\r\n/g,'\n')),'CSS compacto Hostinger conservado íntegramente');
const css=fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+['global.css','dashboard.css','responsive_sistema.css'].map(f=>read('public/css/'+f)).join('\n');
const modules=path.dirname(path.resolve(bootstrap));let assets='';for(const w of [400,500,600,700,800]){const p=path.join(modules,'@fontsource/manrope/files/manrope-latin-'+w+'-normal.woff2');if(fs.existsSync(p))assets+=`@font-face{font-family:Manrope;font-weight:${w};src:url(data:font/woff2;base64,${fs.readFileSync(p).toString('base64')})}`;}
const ic=path.join(modules,'bootstrap-icons/font/bootstrap-icons.min.css');if(fs.existsSync(ic))assets+=fs.readFileSync(ic,'utf8').replace(/url\([^)]+\)/g,()=>`url(data:font/woff2;base64,${fs.readFileSync(path.join(modules,'bootstrap-icons/font/fonts/bootstrap-icons.woff2')).toString('base64')})`);
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];let navigations=0;page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://ventas.test/**',r=>{const u=new URL(r.request().url());if(u.pathname.startsWith('/public/javascript/'))return r.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});navigations++;const html=cp.execFileSync('php',[path.join(__dirname,'fixtures/ventas_bandejas_vista.php'),JSON.stringify(Object.fromEntries(u.searchParams)),u.pathname==='/baseline'?'hostinger':''],{encoding:'utf8'});return r.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${assets}${css}main{padding:24px}</style><main>${html}</main>`});});
 const cardStyle=()=>page.locator('.linkage-incoming-card').first().evaluate(e=>{const c=getComputedStyle(e),b=e.getBoundingClientRect();return{padding:c.padding,border:c.border,bg:c.backgroundColor,font:c.fontFamily,gap:c.gap,height:b.height,button:getComputedStyle(e.querySelector('button')).fontSize};});
 await page.goto('http://ventas.test/baseline');await page.evaluate(()=>document.fonts.ready);const original=await cardStyle();
 for(const vista of ['', 'devoluciones','agenda']){
  const url='http://ventas.test/index.php?controller=telefonia&action=misLlamadas'+(vista?'&vista='+vista:'');await page.goto(url);await page.evaluate(()=>document.fonts.ready);
  assert.equal(await page.getByRole('button',{name:'Filtrar',exact:true}).count(),0,'Sin botón Filtrar '+vista);
  if(!vista)assert.deepEqual(await cardStyle(),original,'Diseño compacto Hostinger exacto');
  const names=await page.locator('nav[aria-label="Bandeja comercial"] a').allTextContents();assert.deepEqual(names,['Historial','Por devolver','Programadas']);
  for(const size of [{width:1440,height:1000},{width:1024,height:900},{width:390,height:844},{width:740,height:360}]){
   await page.setViewportSize(size);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Sin scroll horizontal '+vista+' '+size.width);
   if(process.env.IMPE_VENTAS_SCREENSHOTS)await page.screenshot({path:path.join(process.env.IMPE_VENTAS_SCREENSHOTS,'bandejas_'+(vista||'historial')+'_'+size.width+'.png')});
  }
  await page.setViewportSize({width:1440,height:1000});const search=page.locator('input[type=search]');let before=navigations;
  await search.fill('Prospecto 1');await page.waitForURL(u=>u.searchParams.get('q')==='Prospecto 1');assert.equal(navigations,before+1,'Una navegación debounce');assert.equal(new URL(page.url()).pathname,'/index.php','El campo action no sustituye la ruta');await page.waitForFunction(()=>document.activeElement?.name==='q');
  const select=page.locator('form[data-sales-auto-filter] select');await select.selectOption(vista==='agenda'?'VENCIDAS':vista==='devoluciones'?'TODAS':'Saliente');await page.waitForURL(u=>u.searchParams.get('estado')===(vista==='agenda'?'VENCIDAS':vista==='devoluciones'?'TODAS':'Saliente'));
  await page.locator('input[type=search]').fill('');await page.waitForURL(u=>u.searchParams.get('q')==='');
  if(vista==='agenda'){await select.selectOption('TODAS');await page.waitForURL(u=>u.searchParams.get('estado')==='TODAS');}
  assert.equal(await page.getByRole('link',{name:'Siguiente',exact:true}).getAttribute('aria-disabled'),'false',JSON.stringify({vista,url:page.url(),pagination:await page.locator('.linkage-incoming-pagination').innerText()}));
  await page.getByRole('link',{name:'Siguiente',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('pagina')==='2');
  await page.locator('input[type=search]').fill('inexistente');await page.waitForURL(u=>u.searchParams.get('q')==='inexistente');assert.equal(new URL(page.url()).searchParams.has('pagina'),false,'Reinicia página');
  assert.equal(await page.locator('.linkage-incoming-empty-panel').count(),1);assert.match(await page.locator('.linkage-incoming-pagination').innerText(),/0 registros/);assert.equal(await page.locator('.linkage-incoming-pagination a[aria-disabled=true]').count(),2);
 }
 await page.goto('http://ventas.test/index.php?controller=telefonia&action=misLlamadas&pagina=2&pbx_call_id=anterior&extension=150');
 await page.locator('#salesFrom').fill('2026-10-01');await page.waitForURL(u=>u.searchParams.get('desde')==='2026-10-01');assert.equal(new URL(page.url()).searchParams.has('pbx_call_id'),false);assert.equal(new URL(page.url()).searchParams.has('extension'),false);
 await page.locator('#salesUntil').fill('2026-10-19');await page.waitForURL(u=>u.searchParams.get('hasta')==='2026-10-19');
 let before=navigations;await page.locator('#salesFrom').fill('2026-10-25');await page.waitForTimeout(550);assert.equal(navigations,before,'Rango inválido no navega');
 await page.locator('#salesFrom').fill('2026-10-01');await page.waitForTimeout(100);
 assert.deepEqual(errors,[]);console.log('Tres bandejas: Historial Hostinger idéntico, filtros automáticos, fechas, paginación, vacíos y 4 tamaños: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
