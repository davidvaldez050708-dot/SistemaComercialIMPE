const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('assert/strict'),{chromium}=require('playwright');
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
 const errors=[];
 for(const width of [1366,768,390,320]){
  const page=await browser.newPage({viewport:{width,height:900}});page.on('pageerror',e=>errors.push(e.message));
  let total=2670;
  await page.route('http://supervision.test/**',r=>{
   const u=new URL(r.request().url());
   if(u.pathname==='/'||u.pathname==='/index.php'){
    const body=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/supervision_paginacion_vista.php',u.searchParams.get('pagina')||'1',String(total)],{encoding:'utf8'});
    return r.fulfill({contentType:'text/html',body});
   }
   const local=path.resolve('.'+u.pathname),root=process.cwd()+path.sep;
   if(local.startsWith(root)&&fs.existsSync(local))return r.fulfill({contentType:local.endsWith('.css')?'text/css':local.endsWith('.js')?'application/javascript':'application/octet-stream',body:fs.readFileSync(local)});
   return r.fulfill({status:404,body:''});
  });
  for(const current of [1,45,89]){
   await page.goto('http://supervision.test/index.php?pagina='+current);
   const nav=page.locator('.supervision-pagination');await nav.scrollIntoViewIfNeeded();
   assert.equal(await nav.locator('[aria-current=page]').innerText(),String(current));
   assert.ok(await nav.locator('.supervision-page').count()<=9,'Ventana compacta, sin 89 botones');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Sin desbordar a '+width);
   for(const href of await nav.locator('a').evaluateAll(es=>es.map(e=>e.href))){
    const u=new URL(href);assert.equal(u.searchParams.get('buscar'),'Prueba & "institución"');assert.equal(u.searchParams.get('persona_id'),'8');assert.equal(u.searchParams.get('resultado'),'SIN_RESPUESTA');assert.equal(u.searchParams.get('desde'),'2026-11-01');
   }
   if(current===1)assert.equal(await nav.locator('a[rel=prev]').count(),0);
   if(current===89)assert.equal(await nav.locator('a[rel=next]').count(),0);
   if(current===45){assert.ok(await nav.getByRole('link',{name:'Ir a la página 1',exact:true}).count());assert.ok(await nav.getByRole('link',{name:'Ir a la página 89',exact:true}).count());
    assert.equal(await nav.locator('[aria-current=page]').evaluate(e=>getComputedStyle(e).color),'rgb(255, 255, 255)');
   }
  }
  await page.goto('http://supervision.test/');await page.locator('a[rel=next]').click();assert.equal(new URL(page.url()).searchParams.get('pagina'),'2');
  await page.locator('.supervision-pagination a').first().focus();assert.ok(await page.locator('.supervision-pagination a').first().evaluate(e=>e===document.activeElement));
  fs.mkdirSync('tmp',{recursive:true});await page.locator('.supervision-pagination').screenshot({path:'tmp/supervision-paginacion-'+width+'.png'});
  for(const count of [0,1,30]){total=count;await page.goto('http://supervision.test/');assert.equal(await page.locator('.supervision-pagination').count(),0);}
  await page.close();
 }
 assert.deepEqual(errors,[]);console.log('PASS paginación institucional: 89 páginas, extremos, filtros, navegación, teclado, cero/una página y 4 tamaños');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
