const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({channel:'msedge'});
 try {
  const page=await browser.newPage();
  let loads=0;
  const errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.addInitScript(()=>{window.IMPE_CURRENT_USER_ID=22;});
  await page.route('http://embudo.test/**',async route=>{
   const p=new URL(route.request().url()).pathname;
   if(p==='/board.js')return route.fulfill({path:path.resolve('public/javascript/embudo_comercial_v3_operativo.js'),contentType:'application/javascript'});
   if(p==='/bootstrap.js')return route.fulfill({path:path.resolve('../browser-legacy-deps/node_modules/bootstrap/dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
   loads++;
   return route.fulfill({contentType:'text/html',body:'<!doctype html><main class="impe-embudo--v3-operativo"><div class="impe-embudo-board"></div></main><script src="/bootstrap.js"></script><script src="/board.js"></script>'});
  });
  await page.goto('http://embudo.test/');
  await page.evaluate(()=>localStorage.setItem('impe:embudo:resultado-guardado:22',JSON.stringify({usuarioId:22,asignacionId:1,creada:Date.now()+1})));
  assert.equal(await page.evaluate(()=>window.dispatchEvent(new CustomEvent('impe:embudo:confirmacion-resultado',{
   cancelable:true,detail:{usuarioId:22,asignacionId:1}
  }))),false,'el tablero se hace cargo del único aviso');
  assert.equal(await page.evaluate(()=>window.dispatchEvent(new CustomEvent('impe:embudo:confirmacion-resultado',{
   cancelable:true,detail:{usuarioId:23,asignacionId:1}
  }))),true,'otro usuario no silencia el aviso');
  const toast=page.locator('#impe-v3o-avisos .system-toast.show');
  await toast.waitFor();
  assert.match(await toast.innerText(),/Resultado comercial guardado/);
  assert.ok(loads>=3,'incluye comprobación y recarga');
  await page.waitForTimeout(2000);
  assert.equal(await toast.count(),1,'se alcanza a leer después de recargar');
  await toast.waitFor({state:'detached'});
  await page.reload();
  assert.equal(await page.locator('.system-toast').count(),0,'se consume una sola vez');
  assert.deepEqual(errors,[]);
  console.log('OK toast visible tras refrescar, duración y consumo único');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
