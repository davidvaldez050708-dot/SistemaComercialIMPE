const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium,webkit}=require('playwright');
const root=path.resolve(__dirname,'..'),bootstrap=path.dirname(require.resolve('bootstrap/package.json')),icons=path.dirname(require.resolve('bootstrap-icons/package.json'));
(async()=>{
 const browser=await(process.env.SALES_BROWSER==='webkit'?webkit:chromium).launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{});
 try {
  const p=await browser.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));let html='';
  await p.route('**/*',r=>{
   const u=new URL(r.request().url());if(u.hostname!=='pendientes.test')return r.abort();
   if(u.pathname==='/')return r.fulfill({contentType:'text/html',body:html});
   const f=u.pathname==='/bootstrap.css'?path.join(bootstrap,'dist/css/bootstrap.min.css'):u.pathname.startsWith('/icons/')?path.join(icons,'font',u.pathname.slice(7)):path.join(root,u.pathname);
   return fs.existsSync(f)?r.fulfill({path:f}):r.abort();
  });
  for(const view of ['programadas','devoluciones'])for(const state of ['TODAS',view==='programadas'?'VENCIDAS':'VENCIDA'])for(const width of [1366,390,320]){
   html=execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'ventas_pendientes_vista_fixture.php'),view,state],{encoding:'utf8'});
   assert(!/Warning:|Fatal error/.test(html));await p.setViewportSize({width,height:900});await p.goto('http://pendientes.test/');
   assert.equal(await p.locator('.linkage-call-history-card').count(),state==='TODAS'?(view==='programadas'?2:3):1);
   assert.equal(await p.getByText('Acción vencida',{exact:true}).count(),1);
   assert.equal(await p.locator(view==='programadas'?'[data-sales-dial]':'[data-sales-callback-start]').count(),state==='TODAS'?2:1);
   assert(!(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)),`${view} ${state} ${width}: sin desbordamiento`);
   if(process.env.SALES_SCREENSHOT_DIR&&state==='TODAS'&&width===390)await p.screenshot({path:path.join(process.env.SALES_SCREENSHOT_DIR,`pendientes_${view}.png`),fullPage:true});
  }
  assert.deepEqual(errors,[]);console.log('Pendientes: 12 vistas, filtro de vencidas, acciones existentes, texto escapado y móvil OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
