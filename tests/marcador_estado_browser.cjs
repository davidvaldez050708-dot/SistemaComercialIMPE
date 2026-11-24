/* Estado de interfaz real: sin llamadas, proveedor ni datos de producción. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'../../browser-legacy-deps/node_modules/playwright');
const root=path.resolve(__dirname,'..'),spec=JSON.parse(fs.readFileSync(path.join(root,'tools/marcador_estado_release.json'),'utf8'));
let installed=fs.readFileSync(path.join(__dirname,'fixtures/telefonia_marcador_instalada_20261124.js.txt'),'utf8').replace(/\r\n/g,'\n');
let patched=installed;
for(const h of spec.files[0].hunks){assert.equal(patched.split(h.before).length-1,1,'Bloque instalado distinto');patched=patched.replace(h.before,h.after);}
const baselineFixture=fs.readFileSync(path.join(__dirname,'gestion_comercial_v3_dialer_guard_browser.cjs'),'utf8');
const fixturePart=baselineFixture.slice(baselineFixture.indexOf('const selectors='),baselineFixture.indexOf('async function test('));
let html=new Function(fixturePart+';return html;')();
html=html.replace('data-call-start-url="/start"','data-result-save-url="/save" data-call-start-url="/start"')
 .replace('<div data-sales-result-select></div>','<select data-sales-result-select><option value="SIN_RESPUESTA">No contestó</option></select>')
 .replace('<script src="/marcador.js">','<table class="telephony-history-table"></table><script src="/marcador.js">');
const origin='http://ventas-estado.test',ref='out_'+'a'.repeat(32),other='out_'+'b'.repeat(32);
const finished=(reference=ref)=>({active:false,phase:'finished',status:'completed',extension:'150',pbxCallId:reference,callToken:'sesion-propia-123456',context:{type:'DIALER'},destination:'527771234567'});
const active=(reference=ref)=>({...finished(reference),active:true,phase:'in-progress',status:'in-progress',message:'Llamada en curso…'});

(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  async function setup(source=patched){
   const page=await browser.newPage();page.setDefaultTimeout(5000);const errors=[];let finishSave=null,saveCount=0;
   page.on('pageerror',e=>{errors.push(e.message);console.error('PAGEERROR',e.message);});
   await page.addInitScript(()=>{
    window.IMPE_PHONE_NUMBERS={key:value=>String(value||'').replace(/[^0-9]/g,'')};
    window.__state={active:false,phase:'ready',extension:'150'};window.__subscribers=[];window.__receipts=[];window.__calls=[];
    window.__setState=state=>{window.__state=state;for(const cb of window.__subscribers)cb(state);};
    window.IMPE_TELEPHONY_PERSISTENT={getState:()=>window.__state,subscribe:cb=>{window.__subscribers.push(cb);cb(window.__state);},probe:async()=>({extension:'150',permite_salientes:true}),startCall:async p=>{window.__calls.push(p);}};
    addEventListener('message',e=>{if(e.origin===location.origin&&e.source!==window&&e.data?.type==='IMPE_SALES_RESULT_SAVED')window.__receipts.push(e.data);});
   });
   await page.route(origin+'/**',async route=>{
    const url=new URL(route.request().url());
    if(url.pathname==='/marcador.js')return route.fulfill({body:source,contentType:'text/javascript'});
    if(url.pathname==='/save'){
     saveCount++;const body=new URLSearchParams(route.request().postData());
     assert.equal(body.get('csrf_token'),'csrf-no-contactar');
     const result=await new Promise(resolve=>{finishSave=resolve;});
     return route.fulfill({status:result.status,json:result.status===200?{ok:true,registro:{id:1,extension:'150',pbx_call_id:body.get('pbx_call_id')}}:{ok:false,mensaje:'Guardado rechazado'}});
    }
    if(url.pathname==='/shell')return route.fulfill({body:'<!doctype html><iframe name="crm" src="/dialer"></iframe>',contentType:'text/html'});
    if(url.pathname==='/dialer')return route.fulfill({body:html,contentType:'text/html'});
    throw new Error('Petición inesperada: '+url.pathname);
   });
   await page.goto(origin+'/shell');const frame=page.frame({name:'crm'});
   await frame.waitForFunction(()=>window.__subscribers.length===1 && document.querySelector('[data-telephony-extension-identity]').textContent.includes('150'));
   await frame.locator('[data-telephony-dial-call]:not(:disabled)').waitFor();
   return {page,frame,errors,ack:status=>{assert.ok(finishSave);finishSave({status});},saveCount:()=>saveCount};
  }
  async function state(test,s){await test.frame.evaluate(s=>window.__setState(s),s);}
  const label=t=>t.frame.locator('[data-telephony-dial-status]'),call=t=>t.frame.locator('[data-telephony-dial-call]');
  // Reproducción sobre la copia instalada: etiqueta activa retenida al volver a reposo.
  let t=await setup(installed);await state(t,active());await state(t,{active:false,phase:'idle',extension:'150'});
  assert.equal(await call(t).isDisabled(),false);assert.equal(await label(t).textContent(),'Llamada en curso…');await t.page.close();
  console.log('REPRODUCIDO: archivo instalado habilita Llamar pero conserva Llamada en curso.');

  t=await setup();await state(t,active());assert.equal(await call(t).isDisabled(),true);
  await t.frame.locator('[data-telephony-dial-hangup]').click();
  assert.equal(await label(t).textContent(),'Finalizando llamada…');
  await state(t,{active:false,phase:'idle',extension:'150',message:'Llamada en curso…'});
  assert.equal(await call(t).isDisabled(),false);assert.equal(await label(t).textContent(),'Listo para marcar.');
  await t.frame.locator('[data-telephony-dial-number]').fill('abc');await call(t).click();
  const validation=await label(t).textContent();assert.match(validation,/teléfono/);
  await state(t,{active:false,phase:'ready',extension:'150'});assert.equal(await label(t).textContent(),validation);
  await state(t,{active:false,phase:'error',message:'Teléfono sin conectar',extension:'150'});assert.equal(await label(t).textContent(),'Teléfono sin conectar');
  await state(t,{active:false,phase:'ready',extension:'150'});assert.equal(await label(t).textContent(),'Listo para marcar.');
  assert.deepEqual(t.errors,[]);await t.page.close();
  console.log('PASS: activa bloqueada; reposo limpia mensajes de llamada; validación manual persiste; errores recuperados.');

  for(const status of [200,503]){
   t=await setup();await state(t,finished());assert.equal(await call(t).isDisabled(),true);
   await t.frame.locator('[data-sales-result-submit]').click();await t.page.waitForTimeout(50);
   assert.equal(t.saveCount(),1);assert.equal(await call(t).isDisabled(),true);
   assert.deepEqual(await t.page.evaluate(()=>window.__receipts),[]);
   t.ack(status);
   await t.frame.waitForFunction(()=>!document.querySelector('[data-sales-result-submit]').disabled);
   assert.equal(await call(t).isDisabled(),status!==200);
   if(status===200){
    await t.page.waitForFunction(()=>window.__receipts.length===1);
    assert.deepEqual(await t.page.evaluate(()=>window.__receipts),[{type:'IMPE_SALES_RESULT_SAVED',ref,extension:'150'}]);
    assert.match(await label(t).textContent(),/Resultado registrado/);
    await state(t,{active:false,phase:'idle',extension:'150'});assert.equal(await label(t).textContent(),'Listo para marcar.');
   }else assert.deepEqual(await t.page.evaluate(()=>window.__receipts),[]);
   assert.deepEqual(await t.frame.evaluate(()=>window.__calls),[]);assert.deepEqual(t.errors,[]);await t.page.close();
  }
  console.log('PASS: guardar en curso mantiene bloqueo; sólo acuse OK habilita y notifica el diálogo exacto; 503 conserva pendiente.');

  t=await setup();await state(t,finished());await t.frame.locator('[data-sales-result-submit]').click();await t.page.waitForTimeout(50);
  await state(t,{...active(other),callToken:'otro-dialogo-654321'});t.ack(200);
  await t.page.waitForFunction(()=>window.__receipts.length===1);
  assert.equal(await call(t).isDisabled(),true);assert.equal((await t.frame.evaluate(()=>window.__state)).pbxCallId,other);
  assert.equal((await t.page.evaluate(()=>window.__receipts))[0].ref,ref);
  assert.deepEqual(t.errors,[]);await t.page.close();
  console.log('PASS: un guardado tardío de la llamada anterior no libera ni altera otra llamada activa.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
