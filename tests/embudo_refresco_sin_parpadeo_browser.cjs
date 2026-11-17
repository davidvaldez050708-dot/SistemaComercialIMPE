// UI integration: real scripts, server-rendered snapshots, no database or calls.
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const fixture=require('./fixtures/seguimiento_acciones_v3_html.cjs');
const app='http://refresco-v3.test';
const inicio=app+'/index.php?controller=embudoComercial&action=index';
const fuente=path.join(__dirname,'../public/javascript');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});
 try{
  const page=await browser.newPage({viewport:{width:1280,height:900}});
  page.setDefaultTimeout(7000);page.setDefaultNavigationTimeout(7000);
  let docs=0,lecturas=0,posts=0,revision=7,etapa='POTENCIAL',pendiente=false,modo='normal';
  const errores=[];
  page.on('pageerror',e=>errores.push(e.message));
  page.on('request',req=>{if(req.isNavigationRequest() && req.frame()===page.mainFrame())docs++;});
  const render=url=>{
   const q=url.searchParams.get('buscar')||'';
   let html=fixture(etapa,pendiente?'REPROGRAMAR':'PROGRAMAR')
    .replace('data-version="7"',`data-version="${revision}"`)
    .replace('data-v3-fecha-pendiente="2099-01-01T10:00"','data-v3-fecha-pendiente="2099-01-02T10:00"')
    .replace('<div class="impe-embudo-board">',`<form data-embudo-v3-auto-filter action="/index.php"><input name="controller" type="hidden" value="embudoComercial"><input name="action" type="hidden" value="index"><input type="search" name="buscar" value="${q}"></form><a class="impe-embudo-reset" href="${inicio}">Limpiar</a><div class="impe-embudo-board-info">Revisión ${revision}: ${q}</div><div class="impe-embudo-board"><section class="impe-v3o-columna" data-etapa="${etapa}"><div class="impe-embudo-lane-list">`)
    .replace('</div><dialog id="impe-v3o-dialog">','</div></section></div><dialog id="impe-v3o-dialog">')
    .replace('<button data-v3-gestionar>', '<button data-embudo-marcar="7775083225">Llamar</button><button class="impe-v3o-gestion-boton" popovertarget="opciones-1">Opciones</button><div id="opciones-1" class="impe-v3o-gestion-opciones" popover="auto">Gestiones</div><button data-v3-gestionar>')
    .replace('</main>',`<nav class="impe-v3o-paginacion"><a href="${inicio}&pagina=2">Siguiente</a></nav></main>`)
    .replace('<script src="/bootstrap.js"></script>','')
    .replace('<script src="/tablero.js"></script>','<script src="/tablero.js"></script><script src="/filtros.js"></script>');
   return html.replace('<main ', '<div id="telefono-persistente">Teléfono estable</div><style>.impe-embudo-lane-list{height:260px;overflow:auto}.impe-v3o-card{min-height:500px}</style><main ');
  };
  await page.addInitScript(()=>{
   window.IMPE_CURRENT_USER_ID=22;window.IMPE_CSRF_TOKEN='fixture';window.instancia=String(Math.random());
   window.llamadas=0;window.telefono={getState:()=>({active:Boolean(window.llamadaActiva)}),openDialer:()=>window.llamadas++};
   window.IMPE_TELEPHONY_PERSISTENT=window.telefono;
  });
  await page.route(app+'/**',async route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.pathname==='/tablero.js')return route.fulfill({path:path.join(fuente,'embudo_comercial_v3_operativo.js')});
   if(url.pathname==='/filtros.js')return route.fulfill({path:path.join(fuente,'embudo_comercial_v3_filtros.js')});
   if(url.pathname.endsWith('.css'))return route.fulfill({body:''});
   if(req.method()==='POST'){
    posts++;assert.equal(req.headers()['x-csrf-token'],'fixture');
    const b=new URLSearchParams(req.postData());assert.equal(b.get('version'),String(revision));
    etapa='SEGUIMIENTO';pendiente=true;revision++;
    return route.fulfill({json:{ok:true}});
   }
   lecturas++;
   if(modo==='fallo')return route.fulfill({status:503,body:'Fallo temporal'});
   if(modo==='login')return route.fulfill({body:'<html>Iniciar sesión</html>',contentType:'text/html'});
   if(!req.isNavigationRequest())await new Promise(r=>setTimeout(r,url.searchParams.get('buscar')==='lento'?500:150));
   try{return await route.fulfill({body:render(url),contentType:'text/html'});}catch(e){if(!req.failure())throw e;}
  });
  await page.goto(inicio);
  const instancia=await page.evaluate(()=>window.instancia);
  await page.evaluate(()=>{
   window.telefonoOriginal=document.getElementById('telefono-persistente');window.blancos=0;
   window.mirar=()=>{if(!document.querySelector('.impe-embudo-board .impe-v3o-card'))window.blancos++;requestAnimationFrame(window.mirar);};
   requestAnimationFrame(window.mirar);
  });
  await page.locator('[data-v3-actividad]').click();
  await page.locator('[name=operacion]').selectOption('PROGRAMAR');
  await page.locator('[name=fecha]').fill('2099-01-02T10:00');
  await page.locator('[name=motivo]').fill('Prueba de próxima acción.');
  await page.locator('#impe-v3o-actividad-dialog [type=submit]').click();
  await page.locator('.system-toast.show').waitFor();
  assert.equal(posts,1);assert.equal(docs,1);
  assert.equal(await page.locator('.impe-v3o-card').first().getAttribute('data-etapa'),'SEGUIMIENTO');
  assert.equal(await page.locator('[data-v3-actividad]').getAttribute('data-version'),'8');
  assert.equal(await page.locator('.system-toast.show').count(),1);
  await page.locator('.system-toast .btn-close').click();
  console.log('PRUEBA: New cards');
  // New cards retain their controls, and have exactly one listener each.
  await page.locator('[data-embudo-marcar]').click();assert.equal(await page.evaluate(()=>window.llamadas),1);
  await page.getByRole('button',{name:'Opciones',exact:true}).click();
  assert.equal(await page.locator('#opciones-1').evaluate(el=>el.matches(':popover-open')),true);
  await page.getByRole('button',{name:'Opciones',exact:true}).click();
  await page.locator('[data-v3-actividad]').click();
  await page.locator('[name=operacion]').selectOption('REPROGRAMAR');
  assert.equal(await page.locator('[name=fecha]').inputValue(),'2099-01-02T10:00');
  await page.locator('[name=fecha]').fill('2099-01-03T10:00');
  await page.locator('[name=motivo]').fill('Prueba siguiente actualización.');
  await page.locator('#impe-v3o-actividad-dialog [type=submit]').click();
  await page.locator('.system-toast.show').waitFor();assert.equal(posts,2);assert.equal(docs,1);
  await page.locator('.system-toast .btn-close').click();
  console.log('PRUEBA: Background result');
  // Background result update preserves scroll, shell, and the phone instance.
  await page.locator('.impe-embudo-lane-list').evaluate(el=>el.scrollTop=190);
  revision++;
  await page.evaluate(()=>{localStorage.setItem('impe:embudo:resultado-guardado:22',JSON.stringify({usuarioId:22,asignacionId:1,creada:Date.now()+1}));window.dispatchEvent(new Event('focus'));});
  await page.locator('.system-toast.show').waitFor();
  assert.equal(await page.locator('[data-v3-actividad]').getAttribute('data-version'),'10');
  assert.equal(await page.locator('.impe-embudo-lane-list').evaluate(el=>el.scrollTop),190);
  assert.equal(await page.evaluate(()=>window.instancia),instancia);
  assert.equal(await page.evaluate(()=>window.telefonoOriginal===document.getElementById('telefono-persistente') && window.IMPE_TELEPHONY_PERSISTENT===window.telefono),true);
  await page.locator('.system-toast .btn-close').click();
  const antes=lecturas;await page.waitForTimeout(1200);assert.equal(lecturas,antes,'un resultado se consume una vez');
  console.log('PRUEBA: Network and invalid');
  // Network and invalid/login responses keep the existing board, no false success.
  for(const fallo of ['fallo','login']){
   modo=fallo;
   const texto=await page.locator('.impe-embudo-board-info').textContent();
   const rechazo=await page.evaluate(async()=>{try{await window.IMPE_EMBUDO_V3.actualizar();return false;}catch(_){return true;}});
   assert.equal(rechazo,true);assert.equal(await page.locator('.impe-embudo-board-info').textContent(),texto);
   assert.equal(docs,1);assert.equal(await page.locator('.system-toast.show').count(),0);
  }
  modo='normal';
  console.log('PRUEBA: Filters and pagination');
  // Filters and pagination fetch fragments; older responses cannot replace newer data.
  await page.locator('[name=buscar]').fill('actual');
  await page.waitForURL(/buscar=actual/);
  assert.match(await page.locator('.impe-embudo-board-info').textContent(),/actual/);
  await page.evaluate(async()=>{
   const a=window.IMPE_EMBUDO_V3.actualizar('/index.php?controller=embudoComercial&action=index&buscar=lento',true);
   const b=window.IMPE_EMBUDO_V3.actualizar('/index.php?controller=embudoComercial&action=index&buscar=reciente',true);
   await Promise.all([a,b]);
  });
  assert.match(page.url(),/buscar=reciente/);assert.equal(await page.locator('[name=buscar]').inputValue(),'reciente');
  await page.getByRole('link',{name:'Limpiar',exact:true}).click();
  await page.waitForURL(inicio);assert.equal(await page.locator('[name=buscar]').inputValue(),'');
  await page.getByRole('link',{name:'Siguiente',exact:true}).click();await page.waitForURL(/pagina=2/);
  await page.goBack();await page.waitForTimeout(300);assert.equal(page.url(),inicio);
  await page.locator('[name=buscar]').fill('actual');
  await page.waitForURL(/buscar=actual/);
  await page.getByRole('link',{name:'Limpiar',exact:true}).click();await page.waitForURL(inicio);
  console.log('PRUEBA: Active calls');
  // Active calls and an open form postpone refresh; closing them resumes once.
  await page.evaluate(()=>{window.llamadaActiva=true;localStorage.setItem('impe:embudo:resultado-guardado:22',JSON.stringify({usuarioId:22,asignacionId:1,creada:Date.now()+1}));window.dispatchEvent(new Event('focus'));});
  const activo=lecturas;await page.waitForTimeout(1000);assert.equal(lecturas,activo);
  await page.evaluate(()=>window.llamadaActiva=false);
  await page.locator('[data-v3-actividad]').click();const abierto=lecturas;
  await page.waitForTimeout(1000);assert.equal(lecturas,abierto);
  revision++;
  await page.evaluate(()=>document.getElementById('impe-v3o-actividad-dialog').close());
  await page.locator('.system-toast.show').waitFor();
  assert.equal(await page.locator('[data-v3-actividad]').getAttribute('data-version'),String(revision));
  assert.equal(await page.evaluate(()=>window.blancos),0,'ningún frame sin tablero visible');
  assert.equal(await page.evaluate(()=>window.instancia),instancia);
  assert.equal(docs,1,'todo el flujo conserva el documento y teléfono');assert.deepEqual(errores,[]);
  console.log('CHECK_OK refresco parcial: actividades, llamadas, controles, scroll, teléfono, filtros, navegación, errores y concurrencia sin pantalla blanca.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});


