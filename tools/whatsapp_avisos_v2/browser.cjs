const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const dir=__dirname,assets=path.join(dir,'ci_assets');
const read=n=>fs.readFileSync(path.join(dir,n),'utf8');
const css=fs.readFileSync(require.resolve('bootstrap/dist/css/bootstrap.min.css'),'utf8')+'\n'+read('ci_assets/global.css')+'\n'+read('ci_assets/recordatorios.css')+'\n'+read('whatsapp_avisos_globales.css');
const markup=`<!doctype html><html><head><meta charset="utf-8"><style>${css}</style><script>window.IMPE_CURRENT_USER_ID=22;window.IMPE_CSRF_TOKEN='synthetic';window.IMPE_TELEPHONY_PERSISTENT={getState:()=>({active:!!window.testCall})};</script></head><body>
<header><div data-reminder-root data-reminder-endpoint="/reminders"><span data-reminder-badge></span><div data-reminder-content></div></div>
<div data-wa-global data-base-url="http://wa.test/" data-user-id="22"><button class="topbar-reminder-button" data-wa-button><span class="topbar-reminder-badge d-none" data-wa-count>0</span></button><div class="topbar-reminder-menu"><span data-wa-summary></span><div class="topbar-reminder-list" data-wa-list></div><div data-wa-status></div></div></div></header>
<main><input id="work" value="trabajo intacto"></main>
<script>${read('ci_assets/recordatorios.js')}</script><script data-whatsapp-contextual data-base-url="http://wa.test/">${read('whatsapp_contextual.js')}</script><script>${read('whatsapp_avisos_globales.js')}</script></body></html>`;
(async()=>{const browser=await chromium.launch({headless:true});try{
const context=await browser.newContext({viewport:{width:1366,height:900}});
let data={ok:true,total:0,conversaciones:[]},requests=0,opens=0;
const errors=[];
await context.route('http://wa.test/**',async route=>{
 const url=new URL(route.request().url());
 if(url.searchParams.get('action')==='avisosGlobales'){requests++;return route.fulfill({json:data});}
 if(url.searchParams.get('action')==='panel'){opens++;return route.fulfill({contentType:'text/html',body:'<textarea data-whatsapp-message-input></textarea>'});}
 if(url.pathname==='/reminders')return route.fulfill({json:{ok:true,total:0,recordatorios:[],avisos:[]}});
 return route.fulfill({contentType:'text/html',body:markup});
});
const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));
await p.goto('http://wa.test/');await p.waitForFunction(()=>typeof window.IMPE_WHATSAPP_TOAST==='function');
await p.waitForFunction(()=>localStorage.getItem('impe:whatsapp-toast-seen:v3:22')!==null);
const clock=await p.evaluate(()=>window.IMPE_REMINDER_STATUS());
const row={id:5,entrada_id:'100',nombre:'<img src=x onerror=alert(1)>',mensaje:'Mensaje sintético',no_leidos:1};
data={ok:true,total:1,conversaciones:[row]};
async function refresh(page){const before=requests;await page.locator('[data-wa-button]').click();await page.waitForFunction(()=>document.querySelector('[data-wa-count]').textContent==='1');assert.ok(requests>=before);}
await p.evaluate(()=>window.testCall=true);await refresh(p);
assert.equal(await p.locator('[data-whatsapp-conversation].reminder-toast').count(),0);
assert.equal(await p.locator('[data-wa-list] img').count(),0);
await p.evaluate(()=>window.testCall=false);await refresh(p);
const toast=p.locator('.reminder-toast[data-whatsapp-conversation="5"]');await toast.waitFor({state:'visible'});
assert.equal(await p.locator('.reminder-toast').count(),1);
assert.equal(await p.evaluate(()=>window.IMPE_REMINDER_STATUS().lastShownAt),clock.lastShownAt);
assert.equal(await p.evaluate(()=>window.IMPE_REMINDER_STATUS().nextToastAt),clock.nextToastAt);
assert.equal(await toast.locator('img').count(),0);
await toast.locator('.reminder-toast-close').click();await toast.waitFor({state:'detached'});
assert.equal(await p.locator('[data-wa-count]').textContent(),'1');assert.equal(opens,0);
await refresh(p);assert.equal(await toast.count(),0);
const p2=await context.newPage();p2.on('pageerror',e=>errors.push(e.message));await p2.goto('http://wa.test/other');await p2.waitForFunction(()=>document.querySelector('[data-wa-count]').textContent==='1');assert.equal(await p2.locator('.reminder-toast').count(),0);
await p2.close();
data={ok:true,total:1,conversaciones:[{...row,entrada_id:'101'}]};await refresh(p);await toast.waitFor({state:'visible'});
await toast.click();await p.locator('dialog[open] iframe').waitFor();await p.frameLocator('dialog iframe').locator('textarea').fill('borrador');
assert.equal(await p.locator('#work').inputValue(),'trabajo intacto');
await p.locator('dialog header button').click();await p.locator('dialog[open]').waitFor({state:'detached'});
await p.locator('[data-wa-list] [data-whatsapp-conversation="5"]').click();await p.frameLocator('dialog iframe').locator('textarea').waitFor();
assert.equal(await p.frameLocator('dialog iframe').locator('textarea').inputValue(),'borrador');
assert.ok(opens>=2);assert.deepEqual(errors,[]);
console.log('PASS | real installed presenter, active call deferral, safe text, close without read, no duplicate across tabs, unchanged cadence, same chat/draft');
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)});
