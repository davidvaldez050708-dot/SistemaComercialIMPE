// Eventos → atribución DB → conciliador averiado → ReminderController HTTP → campana/toast.
// Sólo BD desechable en el puerto explícito y llamadas/eventos simulados.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),net=require('node:net'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright'),repo=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
(async()=>{
 if(!process.env.IMPE_TEST_MYSQL_PORT)throw Error('Requiere MySQL aislado');
 const seed=JSON.parse(cp.execFileSync('php',[path.join(__dirname,'fixtures/ventas_avisos_seed.php')],{encoding:'utf8'}));
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const base='http://127.0.0.1:'+port,endpoint=base+'/tests/fixtures/ventas_avisos_endpoint.php';
 const server=cp.spawn('php',['-S','127.0.0.1:'+port,'-t',repo],{stdio:'ignore',env:{...process.env,APP_ENV:'testing',IMPE_TEST_AVISOS_HTTP:'1',IMPE_DB_HOST:'127.0.0.1:'+process.env.IMPE_TEST_MYSQL_PORT,IMPE_DB_NAME:seed.database,IMPE_DB_USER:process.env.IMPE_TEST_MYSQL_USER||'root',IMPE_DB_PASSWORD:process.env.IMPE_TEST_MYSQL_PASSWORD||''}});
 let browser;
 try{
  for(let i=0;i<50;i++){try{await fetch(endpoint);break;}catch(_){await new Promise(r=>setTimeout(r,100));}}
  const get=async q=>{const r=await fetch(endpoint+(q||''));assert.equal(r.status,200);return r.json();};
  let j=await get();assert.equal(j.recordatorios.length,1,'Pendiente visible pese a fallo auxiliar');const id=j.recordatorios[0].telefonia_id;
  assert.equal(j.recordatorios[0].titulo,'Resultado pendiente');assert.match(j.recordatorios[0].url,/pbx-pendiente-comercial/);
  assert.equal(j.recordatorios[0].tipo_evento,'resultado_pendiente','No clasificar conversación conectada como perdida');
  assert.equal((await get()).recordatorios[0].telefonia_id,id,'Persistencia e idempotencia');assert.deepEqual((await get('?otro=1')).recordatorios,[],'Otro asesor no hereda el pendiente');
  browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});const page=await browser.newPage({viewport:{width:1366,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.clock.install({time:new Date()});await page.clock.pauseAt(new Date(Date.now()+100));
  await page.route(base+'/vista',r=>r.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><style>${read('public/css/recordatorios.css')}</style><script>window.IMPE_CURRENT_USER_ID=8;</script><div data-reminder-root data-reminder-endpoint="${endpoint}"><span data-reminder-badge></span><div data-reminder-content></div></div><script>${read('public/javascript/recordatorios.js')}</script>`}));
  await page.goto(base+'/vista');await page.locator('[data-telefonia-id="'+id+'"]').waitFor();assert.equal(await page.locator('[data-reminder-badge]').innerText(),'1');
  assert.equal(await page.locator('.reminder-toast').count(),0);await page.clock.runFor(8*60000);await page.locator('.reminder-toast').waitFor({state:'visible'});assert.equal(await page.locator('.reminder-toast').count(),1);
  const box=await page.locator('.reminder-toast').boundingBox();assert.ok(box.y<180,'Toast institucional superior');await page.clock.runFor(7000);
  assert.equal(await page.locator('.reminder-toast').count(),0,'No aviso fijo');
  assert.equal(await (await fetch(endpoint+'?test_action=save')).text(),'OK');assert.deepEqual((await get()).recordatorios,[],'Guardar resultado retira el pendiente real');
  const loaded=await page.evaluate(()=>IMPE_REMINDER_STATUS().loadedCount);await page.evaluate(()=>dispatchEvent(new Event('impe:phone-notices-refresh')));await page.waitForFunction(n=>IMPE_REMINDER_STATUS().loadedCount>n,loaded);
  assert.equal(await page.locator('.topbar-reminder-item').count(),0);await page.clock.runFor(8*60000);assert.equal(await page.locator('.reminder-toast').count(),0,'Resultado resuelto no reaparece');assert.deepEqual(errors,[]);
  console.log('Avisos Ventas: DB + HTTP ReminderController + campana + toast 8 min + resultado, sin duplicados ni avisos ajenos/en curso: OK');
 }finally{if(browser)await browser.close();try{await fetch(endpoint+'?test_action=cleanup');}finally{server.kill();}}
})().catch(e=>{console.error(e);process.exitCode=1});
