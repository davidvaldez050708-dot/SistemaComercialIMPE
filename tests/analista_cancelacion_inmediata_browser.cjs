// Servidor simulado: no llama, no consulta Zadarma ni escribe una base de datos.
const fs=require('fs'),cp=require('child_process'),assert=require('assert/strict'),{chromium}=require('playwright');
const dir=process.env.BOOTSTRAP_DIR||'../impe-denue-estabilidad/tmp/denue-bootstrap';
const view=cp.execFileSync(process.env.PHP_BIN||'php',['tests/fixtures/denue_paginado_vista.php'],{encoding:'utf8'}).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'');
const scripts=['seguimiento_llamada_zadarma.js','seguimiento_llamada_registro_obligatorio.js'].map(n=>fs.readFileSync('public/javascript/'+n,'utf8'));
const setup=`window.IMPE_CURRENT_ROLE_ID=4;window.IMPE_CURRENT_USER_ID=99;
window.state={phase:'idle',active:false};window.listeners=[];window.cleared=[];
window.IMPE_TELEPHONY_PERSISTENT={subscribe:f=>listeners.push(f),getState:()=>state,
probe:()=>Promise.resolve({extension:'101'}),openContext:()=>{},clearFinished:t=>{cleared.push(t);if(state.callToken===t){state={phase:'idle',active:false};listeners.forEach(f=>f(state));}}};
window.emit=s=>{state=s;listeners.forEach(f=>f(s));};
document.addEventListener('DOMContentLoaded',()=>{const p=document.getElementById('offcanvasSeguimientoTrabajo');p.dataset.flowSeguimientoId='10';
const f=p.querySelector('[data-work-interaction-form]');f.querySelector('[name=seguimiento_id]').value='10';
f.addEventListener('submit',e=>e.preventDefault());
document.querySelector('[data-work-toggle-interaction]').onclick=()=>f.classList.remove('d-none');});`;
const html=`<!doctype html><meta charset="utf-8"><style>${fs.readFileSync(dir+'/bootstrap.min.css','utf8')}</style>${view}<a id="go" href="/other">Salir</a>
<script>${fs.readFileSync(dir+'/bootstrap.bundle.min.js','utf8')}</script><script>${setup}</script>${scripts.map(s=>'<script>'+s+'</script>').join('')}`;
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{channel:'chrome'})});try{
const page=await browser.newPage(),errors=[],posts=[];page.on('pageerror',e=>errors.push(e.message));
let providerReady=false,localId=900;
const ref='out_'+'a'.repeat(32),token='inmediata-00001';
await page.route('https://cancel.test/**',async r=>{
 const u=new URL(r.request().url());
 if(u.pathname.endsWith('zadarma_estado_llamada.php'))return r.fulfill({json:{ok:true,call:providerReady?{pbx_call_id:ref,start_time:new Date().toISOString()}:null}});
 if(u.pathname.endsWith('vincular_interaccion_zadarma.php')){
   posts.push(r.request().postData());return r.fulfill({json:{ok:true,interaccion_id:localId,hubo_respuesta:false,verificacion_efectiva:false}});
 }
 return r.fulfill({contentType:'text/html',body:u.pathname==='/other'?'OTHER':html});
});
await page.goto('https://cancel.test/');
const emit=async(token,active,pbx='')=>page.evaluate(({token,active,pbx})=>emit({active,phase:active?'in-progress':'finished',direction:'outgoing',callToken:token,
 checkpointReady:true,afterEventId:100,context:{type:'VINCULACION',seguimientoId:10},
 finalMetadata:active?null:{pbx_call_id:pbx,call_token:token,requested_at:Math.floor(Date.now()/1000),to:'+525555555555',duration:0,status:'canceled'}}),{token,active,pbx});
const save=async id=>page.evaluate(id=>{
 const f=document.querySelector('[data-work-interaction-form]');f.querySelector('[name=resultado]').value='OTRO';f.querySelector('[name=observacion]').value='Me equivoqué';
 f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
 for(const name of ['impe:interaction-exact-id-ready','impe:interaction-informative-saved'])document.dispatchEvent(new CustomEvent(name,{detail:{seguimientoId:10,interaccionId:id}}));
},id);
const blocked=()=>page.evaluate(()=>!document.getElementById('offcanvasSeguimientoTrabajo').dispatchEvent(new Event('hide.bs.offcanvas',{cancelable:true})));
await emit(token,true);await emit(token,false);await page.waitForTimeout(300);
assert.equal(await blocked(),true,'Antes de guardar protege el resultado');
await save(900);await page.waitForTimeout(300);
assert.equal(await blocked(),false,'Guardar libera aun si no existe PBX');
assert.equal(posts.length,0,'No inventa ni vincula otra referencia');
assert.equal(await page.locator('.toast-container').innerText().then(s=>(s.match(/Resultado guardado correctamente/g)||[]).length),1,'Un único éxito para ambos eventos');
let queue=await page.evaluate(()=>JSON.parse(localStorage.getItem('impe:zadarma:pending-link:99:queue-v1')));
assert.equal(queue[token].interactionId,900);assert.equal(queue[token].metadata.pbx_call_id,'');
await emit(token,false);await save(999);
queue=await page.evaluate(()=>JSON.parse(localStorage.getItem('impe:zadarma:pending-link:99:queue-v1')));
assert.equal(queue[token].interactionId,900,'Un estado tardío no cambia el ID ya guardado');
// No hay advertencia nativa tras guardar; no se vuelve a registrar la interacción.
let dialogs=0;page.on('dialog',async d=>{dialogs++;await d.accept();});
await page.locator('#go').evaluate(e=>e.click());await page.waitForURL('**/other');assert.equal(dialogs,0);
await page.goto('https://cancel.test/');await page.waitForTimeout(600);assert.equal(await blocked(),false);
// Después de otra llamada, un evento tardío al mismo número no puede apropiarse del intento anterior.
await emit('siguiente-00002',true);await emit('siguiente-00002',false,ref);localId=901;providerReady=true;
await save(901);await page.waitForTimeout(500);
assert.equal(posts.length,1);assert.ok(posts[0].includes('901'));assert.ok(!posts[0].includes('900'));
queue=await page.evaluate(()=>JSON.parse(localStorage.getItem('impe:zadarma:pending-link:99:queue-v1')));
assert.ok(queue[token]);assert.ok(!queue['siguiente-00002']);assert.equal(await blocked(),false);
// Un identificador que sí llega tarde se concilia tras recargar, sin volver a dar de alta.
providerReady=false;
await emit('tardia-00000004',true);await emit('tardia-00000004',false);await save(903);
await page.waitForTimeout(300);assert.equal(await blocked(),false);
await page.evaluate(()=>{const k='impe:zadarma:pending-link:99:queue-v1',q=JSON.parse(localStorage.getItem(k));
 q['tardia-00000004'].retryAt=0;q['inmediata-00001'].retryAt=Date.now()+60000;localStorage.setItem(k,JSON.stringify(q));});
providerReady=true;localId=903;
await page.reload();await page.waitForTimeout(1000);
assert.equal(posts.length,2);assert.ok(posts[1].includes('903'));
queue=await page.evaluate(()=>JSON.parse(localStorage.getItem('impe:zadarma:pending-link:99:queue-v1')));
assert.ok(!queue['tardia-00000004']);assert.ok(queue[token]);
// Sin almacenamiento disponible no se libera anticipadamente una captura pendiente.
await page.evaluate(()=>{Storage.prototype.setItem=function(){throw new DOMException('Bloqueado','SecurityError');};});
await emit('sin-storage-0003',true);await emit('sin-storage-0003',false);await save(902);
assert.equal(await blocked(),true);
assert.deepEqual(errors,[]);
console.log('PASS: cancelación inmediata OTRO, cola durable, un toast, navegación/recarga sin bucle, llamadas consecutivas, referencia exacta y fallo de almacenamiento seguro');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
