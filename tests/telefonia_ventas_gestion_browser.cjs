// Vistas y scripts reales. Transporte y API simulados; no acredita una llamada Zadarma.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright'),repo=path.resolve(__dirname,'..');
const bs=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const css=fs.readFileSync(path.join(bs,'dist/css/bootstrap.min.css'),'utf8')+['global.css','dashboard.css','telefonia.css','telefonia_marcador.css','responsive_sistema.css'].map(f=>read('public/css/'+f)).join('\n');
const view=mode=>`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><body><script>window.IMPE_CURRENT_USER_ID=8;window.testReady=true;window.IMPE_TELEPHONY_PERSISTENT={getState:()=>({hostReady:window.testReady,active:false}),subscribe:()=>{},probe:async()=>({extension:'150',permite_salientes:true}),startCall:async p=>window.testCall=p};window.IMPE_TELEPHONY_NOTIFY=(m,e)=>window.testNotice=m;</script>${cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_ventas_gestion.php'),mode],{encoding:'utf8'})}<script>${read('public/javascript/'+(mode==='agenda'?'telefonia_marcador.js':mode==='programadas'?'telefonia_ventas_resultados.js':'telefonia_ventas_devoluciones.js'))}</script></body>`;
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let payload,preparations=0,mode='agenda';
 await page.route('http://ventas.test/**',async route=>{const u=new URL(route.request().url()),action=u.searchParams.get('action');
  if(action==='guardarContacto'){payload=Object.fromEntries(new URLSearchParams(route.request().postData()));return route.fulfill({json:{ok:true,contacto:{id:1,nombre:payload.nombre,telefono:'+525551112233',correo:payload.correo,observacion:payload.observacion,proxima_accion_at:payload.proxima_accion_at||null}}});}
  if(action==='completarProgramacionVenta'){payload=Object.fromEntries(new URLSearchParams(route.request().postData()));return route.fulfill({json:{ok:true}});}
  if(action==='eliminarContacto')return route.fulfill({json:{ok:true}});
  if(action==='devolucionVenta'){preparations++;return route.fulfill({json:{ok:true,intento:{id:20,numero:'+525551112233',estado:'PREPARADO',pbx_call_id:null,llamada:null,created_at:new Date().toISOString().slice(0,19).replace('T',' ')}}});}
  return route.fulfill({contentType:'text/html',body:view(mode)});
 });
 await page.goto('http://ventas.test/');await page.locator('[data-telephony-contact-edit]').click();
 assert.equal(await page.locator('[data-telephony-contact-email]').inputValue(),'maria@example.test');
 await page.locator('[data-telephony-contact-name]').fill('María Pérez');await page.locator('[data-telephony-contact-email]').fill('nuevo@example.test');await page.locator('[data-telephony-contact-note]').fill('Llamar mañana');
 await page.locator('[data-telephony-contact-save]').click();await page.waitForFunction(()=>document.querySelector('[data-contact-name]').textContent==='María Pérez');
 assert.equal(payload.contacto_id,'1');assert.equal(payload.correo,'nuevo@example.test');assert.equal(payload.observacion,'Llamar mañana');
 await page.locator('[data-telephony-contact-use]').click();assert.equal(await page.locator('[data-telephony-dial-number]').inputValue(),'+525551112233');
 page.on('dialog',d=>d.accept());await page.locator('[data-telephony-contact-remove]').click();await page.locator('[data-telephony-contact-row]').waitFor({state:'detached'});
 mode='devoluciones';await page.goto('http://ventas.test/');await page.evaluate(()=>window.testReady=false);await page.locator('[data-sales-callback-start]').click();assert.equal(preparations,0,'Sin WebRTC no prepara ni marca');
 await page.evaluate(()=>window.testReady=true);await page.locator('[data-sales-callback-start]').click();await page.waitForFunction(()=>window.testCall);
 assert.equal(preparations,1);assert.equal(await page.evaluate(()=>testCall.destination),'+525551112233');assert.equal(await page.evaluate(()=>testCall.context.type),'DIALER');assert.equal(await page.evaluate(()=>testCall.context.venta_devolucion),20);
 for(const size of [{width:1366,height:900},{width:390,height:600},{width:740,height:360}]){await page.setViewportSize(size);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Sin scroll horizontal '+size.width);}
 mode='programadas';await page.goto('http://ventas.test/');
 await Promise.all([page.waitForRequest(r=>r.url().includes('completarProgramacionVenta')),page.locator('[data-sales-program-complete]').click()]);
 assert.equal(payload.origen,'agenda');assert.equal(payload.id,'3');assert.equal(payload.fecha,'2026-10-19 10:00:00');assert.equal(payload.resultado,undefined,'Cerrar recordatorio no envía un resultado falso');
 assert.deepEqual(errors,[]);console.log('Ventas D: agenda real editable, payload completo, usar/eliminar, devolución y bloqueo sin WebRTC; responsive: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
