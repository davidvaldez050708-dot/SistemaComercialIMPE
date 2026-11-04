const fs=require('fs'),cp=require('child_process'),assert=require('assert/strict'),{chromium}=require('playwright');
const source=fs.readFileSync('public/javascript/seguimiento_llamada_registro_obligatorio.js','utf8');
const bridge=fs.readFileSync('public/javascript/seguimiento_llamada_zadarma.js','utf8');
assert.ok(bridge.includes('callToken: token,\n                        pbxCallId: String(metadata.pbx_call_id'));
const view=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/denue_paginado_vista.php'],{encoding:'utf8'}).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'');
const bootstrapDir=process.env.BOOTSTRAP_DIR||'../impe-denue-estabilidad/tmp/denue-bootstrap';
const css=fs.readFileSync(bootstrapDir+'/bootstrap.min.css','utf8'),bs=fs.readFileSync(bootstrapDir+'/bootstrap.bundle.min.js','utf8');
const setup=`window.IMPE_CURRENT_ROLE_ID=4;window.IMPE_CURRENT_USER_ID=99;
window.calls=[];window.state=null;window.listeners=[];window.notices=[];
window.IMPE_TELEPHONY_NOTIFY=(m)=>notices.push(m);
window.IMPE_TELEPHONY_PERSISTENT={subscribe:f=>listeners.push(f),getState:()=>state,openContext:()=>calls.push('open')};
window.emit=s=>{state=s;listeners.forEach(f=>f(s));};
document.addEventListener('DOMContentLoaded',()=>{const p=document.getElementById('offcanvasSeguimientoTrabajo');p.dataset.flowSeguimientoId='10';
 document.querySelector('[data-work-toggle-interaction]').onclick=()=>document.querySelector('[data-work-interaction-form]').classList.remove('d-none');});`;
const child=`<!doctype html><meta charset="utf-8"><style>${css}</style>${view}<a id="navigate" href="/other">Otro módulo</a><button data-work-follow data-work-follow-id="22" id="otherRecord">Otro expediente</button><script>${bs}</script><script>${setup}</script><script>${source}</script>`;
const parent='<a id="parentNavigate" href="/other">Salir de capa persistente</a><iframe id="crm" src="/child" style="width:1200px;height:900px"></iframe>';
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
const page=await browser.newPage({viewport:{width:1440,height:1100}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.route('http://analista.test/**',r=>r.fulfill({contentType:'text/html',body:r.request().url().endsWith('/child')?child:r.request().url().endsWith('/other')?'NAVIGATED':parent}));
await page.goto('http://analista.test/');let frame=page.frames().find(f=>f.url().endsWith('/child'));
const call=(token,active)=>({active,phase:active?'in-progress':'finished',callToken:token,context:{type:'VINCULACION',seguimientoId:10},finalMetadata:active?null:{pbx_call_id:'pbx-'+token,call_token:token}});
await frame.evaluate(s=>emit(s),call('llamada-prueba-01',true));
await frame.evaluate(()=>{bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasSeguimientoTrabajo')).show();document.querySelector('[data-work-interaction-form]').classList.remove('d-none');});
await frame.locator('[data-work-interaction-form] [name=observacion]').fill('Notas capturadas durante la llamada');
await frame.locator('[data-work-interaction-form] [name=persona_atendio]').fill('Persona de prueba');
const blocked=()=>frame.evaluate(()=>{const p=document.getElementById('offcanvasSeguimientoTrabajo');const e=new Event('hide.bs.offcanvas',{cancelable:true});return !p.dispatchEvent(e);});
assert.equal(await blocked(),true,'No cerrar durante llamada');
await frame.locator('#navigate').evaluate(e=>e.click());assert.ok(frame.url().endsWith('/child'));
await page.locator('#parentNavigate').click();assert.ok(page.url().endsWith('/'));
assert.equal(await frame.locator('#otherRecord').evaluate(e=>!e.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}))),true);
assert.equal(await frame.locator('[data-work-interaction-form]').evaluate(e=>!e.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))),true,'No guardar antes de terminar');
await frame.evaluate(s=>emit(s),call('llamada-prueba-01',false));await page.waitForTimeout(350);
assert.equal(await frame.locator('[name=observacion]').inputValue(),'Notas capturadas durante la llamada');
assert.equal(await blocked(),true,'No cerrar después de colgar');
// La fecha/resumen y un reset del panel no destruyen la captura.
await frame.evaluate(()=>{document.querySelector('[data-work-interaction-form]').reset();document.dispatchEvent(new CustomEvent('impe:flow-updated'));});await page.waitForTimeout(50);
assert.equal(await frame.locator('[name=observacion]').inputValue(),'Notas capturadas durante la llamada');
// Una salida forzada conserva la obligación y captura en la sesión del usuario.
page.once('dialog',d=>d.accept());await frame.goto('http://analista.test/child');await page.waitForTimeout(400);
assert.equal(await blocked(),true,'Pendiente sobrevive recarga');assert.equal(await frame.locator('[name=observacion]').inputValue(),'Notas capturadas durante la llamada');
const event=async(name,detail)=>frame.evaluate(({name,detail})=>document.dispatchEvent(new CustomEvent(name,{detail})),{name,detail});
await event('impe:interaction-exact-id-ready',{seguimientoId:22,interaccionId:900});
await event('impe:interaction-informative-saved',{seguimientoId:10,interaccionId:901});
assert.equal(await blocked(),true,'Guardar interacción no equivale a vincular PBX');
await frame.evaluate(()=>{const t=document.createElement('div');t.className='toast';t.textContent='Interacción registrada';document.body.append(t);});
assert.equal(await blocked(),true,'Un toast no libera');
for(const detail of [
 {seguimientoId:10,interaccionId:901,callToken:'otra-llamada',provider:'ZADARMA'},
 {seguimientoId:22,interaccionId:901,callToken:'llamada-prueba-01',provider:'ZADARMA'},
 {seguimientoId:10,interaccionId:902,callToken:'llamada-prueba-01',provider:'ZADARMA'}
]){await event('impe:telephony-call-linked',detail);assert.equal(await blocked(),true);}
await event('impe:telephony-call-linked',{seguimientoId:10,interaccionId:901,callToken:'llamada-prueba-01',provider:'ZADARMA'});
assert.equal(await blocked(),false,'Liberar sólo el vínculo exacto');
assert.equal(await frame.evaluate(()=>sessionStorage.getItem('impe:analista:registro-obligatorio:99')),null);
await frame.evaluate(s=>emit(s),call('llamada-prueba-01',false));assert.equal(await blocked(),false,'Evento tardío no reabre una llamada vinculada');
await frame.locator('#navigate').evaluate(e=>e.click());await page.waitForTimeout(100);assert.ok(frame.url().endsWith('/other'));
assert.deepEqual(errors,[]);console.log('PASS panel real + shell: captura activa, cierre, navegación, otro expediente, recarga, reset, guardado incompleto, toast ajeno y vínculo exacto');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
