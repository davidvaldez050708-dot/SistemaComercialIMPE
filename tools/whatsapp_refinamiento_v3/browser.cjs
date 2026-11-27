const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const js=fs.readFileSync(path.join(__dirname,'ci_assets','whatsapp.js'),'utf8');
(async()=>{const browser=await chromium.launch({headless:true});try{
const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
let current=1,requests=0,denied=false;
function row(id,name,text,unread){return '<a data-whatsapp-conversation-item data-conversation-id="'+id+'" data-search="'+name+'" class="whatsapp-conversation-item'+(unread?' is-unread':'')+'"><strong>'+name+'</strong><span>'+text+'</span>'+(unread?'<b>'+unread+'</b>':'')+'</a>'}
await page.route('http://wa.test/**',route=>{
const u=new URL(route.request().url()),a=u.searchParams.get('action');
if(a==='listaViva'){requests++;assert.equal(route.request().method(),'GET');if(denied)return route.fulfill({status:403,body:'denied'});return route.fulfill({json:{ok:true,total:current,html:current===1?row(1,'Prueba 22','Tú: hola',0):row(2,'Prospecto nuevo','Recibido',2)+row(1,'Prueba 22','Tú: hola',0)}})}
if(a==='mensajes')return route.fulfill({json:{ok:true,mensajes:[],estados_salida:[],ventana_servicio_abierta:true}});
return route.fulfill({contentType:'text/html',body:'<!doctype html><style>.d-none{display:none}</style><main data-whatsapp-module data-base-url="http://wa.test/" data-conversation-id="1"><input data-whatsapp-search><span data-whatsapp-list-count></span><div data-whatsapp-conversation-list></div><textarea data-whatsapp-message-input></textarea><div data-whatsapp-messages></div></main><script>'+js+'</script>'})
});
await page.clock.install();
await page.goto('http://wa.test/');await page.getByText('Tú: hola').waitFor();
await page.locator('[data-whatsapp-message-input]').fill('borrador');
await page.locator('[data-whatsapp-search]').fill('Prueba 22');
current=2;await page.clock.runFor(10001);await page.waitForFunction(()=>document.querySelector('[data-whatsapp-list-count]').textContent==='2 activas');
assert.equal(await page.locator('[data-whatsapp-conversation-item]').first().getAttribute('data-conversation-id'),'2');
assert.equal(await page.locator('[data-conversation-id="2"][data-whatsapp-conversation-item]').isVisible(),false);
await page.locator('[data-whatsapp-search]').fill('');
assert.equal(await page.locator('[data-whatsapp-conversation-item]').first().isVisible(),true);
assert.equal(await page.locator('[data-whatsapp-conversation-item]').first().locator('b').textContent(),'2');
assert.equal(await page.locator('[data-whatsapp-message-input]').inputValue(),'borrador');
denied=true;await page.clock.runFor(10001);await page.waitForFunction(()=>document.querySelectorAll('[data-whatsapp-conversation-item]').length===0);
assert.deepEqual(errors,[]);console.log('WA_LISTA_BROWSER_OK | reorder, unread badge, current search, draft, denied scope');
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)})
