const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
const source=fs.readFileSync(path.join(root,'public/javascript/embudo_notificacion_destino.js'),'utf8');
const css=fs.readFileSync(path.join(root,'public/css/embudo_notificacion_destino.css'),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 try{
  const page=await browser.newPage({viewport:{width:1000,height:650}});
  const errors=[];let writes=0;
  page.on('pageerror',e=>errors.push(e.message));
  await page.clock.install();
  await page.route('https://destino.test/**',route=>{
   if(route.request().method()!=='GET')writes++;
   const url=new URL(route.request().url());
   const id=url.searchParams.get('destacar')||'0';
   const absent=id==='999';
   return route.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><style>${css}</style>
    <div style="height:800px"></div><section style="height:300px;overflow:auto">
    <div style="height:600px"></div><article data-asignacion-id="15"><a href="/ficha">Prospecto correcto</a><button>Completar</button></article></section>
    <div role="status" data-notificacion-destino="${id}">${absent?'':'Actividad localizada'}</div><script>${source}</script>`});
  });
  await page.goto('https://destino.test/index.php?p3_seguimiento=3&destacar=15&aviso_actividad=pendiente');
  const card=page.locator('article');
  assert(await card.evaluate(el=>el.classList.contains('impe-notificacion-destacada')));
  assert(await card.evaluate(el=>document.activeElement===el));
  const rect=await card.boundingBox();assert(rect.y>=0 && rect.y<650,'tarjeta visible con scroll interno y externo');
  assert(page.url().includes('p3_seguimiento=3') && !page.url().includes('destacar'),'conserva página y consume contexto');
  assert(await page.locator('[role=status]').isVisible());
  await page.clock.fastForward(10001);
  assert(!await card.evaluate(el=>el.classList.contains('impe-notificacion-destacada')));
  assert(!await card.getAttribute('tabindex'));
  await page.reload();assert(!await card.evaluate(el=>el.classList.contains('impe-notificacion-destacada')));
  await page.goto('https://destino.test/index.php?destacar=999&aviso_actividad=pendiente');
  assert((await page.locator('[role=status]').textContent()).includes('El tablero cambió'));
  await page.goto('https://destino.test/index.php?aviso_actividad=no_disponible');
  assert(!await card.evaluate(el=>el.classList.contains('impe-notificacion-destacada')));
  assert.equal(writes,0);assert.deepEqual(errors,[]);
  console.log('CHECK_OK navegador: tarjeta visible y enfocada, diseño temporal 10s, paginación conservada, recarga sin repetición, carrera de tablero y cero escrituras.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
