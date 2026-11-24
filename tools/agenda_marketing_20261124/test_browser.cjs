const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=process.cwd(),out=path.join(root,'output/agenda_marketing_20261124');
const result=JSON.parse(fs.readFileSync(path.join(out,'response.json'),'utf8'));
const engine=fs.readFileSync(path.join(__dirname,'base/agenda_alertas.js'),'utf8');
const source=fs.readFileSync(path.join(out,'live/public/javascript/convocatoria_notificaciones.js'),'utf8');
const css=fs.readFileSync(path.join(root,'public/css/recordatorios.css'),'utf8');
const now=result.servidor_at*1000;
const comment={...result.recordatorios[0],agenda_creado_at:Math.floor(now/1000)+3};
const empty=()=>({...structuredClone(result),notificaciones:[],recordatorios:[],avisos:[],horarios:[],no_leidas:0});
const withComment=(item)=>({...empty(),recordatorios:[item],notificaciones:[{...item,id:0,pendiente_operativo:true,titulo:item.nombre_entidad,mensaje:item.accion}],no_leidas:1});
let checks=0;
async function fixture(browser,options={}) {
 const context=options.context||await browser.newContext();
 const page=await context.newPage();let data=options.data||empty();const posts=[],errors=[];
 await page.clock.install({time:new Date(now)});await page.clock.pauseAt(new Date(now+1000));
 await page.addInitScript(blocked=>{
  window.__plays=0;window.__attempts=0;window.__audioUrls=[];window.__blockedAudio=blocked;
  window.Audio=class extends EventTarget {
   constructor(src){super();this.src=src;window.__audioUrls.push(src);}
   play(){window.__attempts++;if(window.__blockedAudio){const e=Error('Autoplay bloqueado');e.name='NotAllowedError';return Promise.reject(e);}window.__plays++;setTimeout(()=>this.dispatchEvent(new Event('ended')),250);return Promise.resolve();}
   pause(){}
  };
 },Boolean(options.blocked));
 page.on('pageerror',e=>errors.push(e.message));
 const bell=options.noMarketing?'':`<div data-convocatoria-notification-root data-endpoint="/index.php?controller=convocatoriaNotificacion&action=pendientes" data-read-endpoint="/leer" data-csrf-token="token-prueba"><button id="campana">Campana</button><span data-convocatoria-notification-badge></span><div data-convocatoria-notification-content></div></div>`;
 const html=`<!doctype html><meta charset="utf-8"><style>${css}</style><script>window.IMPE_CURRENT_USER_ID=7;window.IMPE_CSRF_TOKEN='token-prueba';</script><script src="/public/javascript/agenda_alertas.js"></script><script src="/public/javascript/convocatoria_notificaciones.js"></script><h1>Marketing</h1>${bell}`;
 await page.route('https://marketing.test/**',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(url.pathname.endsWith('/agenda_alertas.js'))return route.fulfill({contentType:'text/javascript',body:engine});
  if(url.pathname.endsWith('/convocatoria_notificaciones.js'))return route.fulfill({contentType:'text/javascript',body:source});
  if(request.method()==='POST') {
   posts.push({url:url.href,body:new URLSearchParams(request.postData()),token:request.headers()['x-csrf-token']});
   if(options.failRead)return route.fulfill({status:503,json:{ok:false}});
   data=empty();return route.fulfill({json:{ok:true}});
  }
  if(url.searchParams.get('action')==='pendientes') {
   const reply=structuredClone(data);reply.servidor_at=await page.evaluate(()=>Math.floor(Date.now()/1000));
   return route.fulfill({json:reply});
  }
  return route.fulfill({contentType:'text/html',body:html});
 });
 await page.goto('https://marketing.test/');
 if(!options.noMarketing)await page.waitForFunction(()=>document.querySelector('[data-convocatoria-notification-root]').dataset.agendaMarketingReady==='1');
 async function advance(ms){await page.clock.runFor(ms);await new Promise(r=>setTimeout(r,15));}
 async function audio(){for(let i=0;i<6;i++)await advance(250);}
 async function refresh(){await page.evaluate(()=>dispatchEvent(new CustomEvent('impe:agenda-comments-refresh')));await new Promise(r=>setTimeout(r,30));}
 return {page,context,errors,posts,advance,audio,refresh,set(value){data=value},async close(){assert.deepEqual(errors,[]);await page.close();if(!options.context)await context.close();}};
}
(async()=>{const browser=await chromium.launch({headless:true,channel:'chrome'});try{
 let f=await fixture(browser);await f.advance(3000);f.set(withComment(comment));await f.refresh();
 await f.page.waitForSelector('[data-agenda-marketing-toast]');await f.audio();
 assert.equal(await f.page.evaluate(()=>__plays),2,'Mismo audio de Agenda reproducido dos veces');
 assert.equal(await f.page.locator('[data-convocatoria-notification-badge]').textContent(),'1');
 assert.match(await f.page.locator('[data-convocatoria-notification-content]').textContent(),/Nuevo comentario autorizado/);
 assert.equal(f.posts.length,0,'Presentar y sonar no marca leído');
 assert.match((await f.page.evaluate(()=>__audioUrls))[0],/\/public\/audio\/agenda-aviso\.mp3$/);
 await f.advance(9000);await f.refresh();await f.audio();assert.equal(await f.page.evaluate(()=>__plays),2,'No repetir por sondeo');
 await f.page.screenshot({path:path.join(out,'marketing-comentario.png')});
 await f.close();checks++;

 f=await fixture(browser,{data:withComment({...comment,agenda_creado_at:Math.floor(now/1000)-60})});
 await f.advance(3000);assert.equal(await f.page.locator('[data-agenda-marketing-toast]').count(),0,'No reanunciar historial inicial');assert.equal(await f.page.evaluate(()=>__plays),0);await f.close();checks++;

 f=await fixture(browser);await f.advance(3000);f.set(withComment({...comment,agenda_autor_id:7}));await f.refresh();await f.audio();assert.equal(await f.page.evaluate(()=>__plays),0,'Sin sonido para comentarios propios');await f.close();checks++;

 f=await fixture(browser,{blocked:true});await f.advance(3000);f.set(withComment(comment));await f.refresh();await f.audio();
 assert.equal(await f.page.evaluate(()=>__plays),0);assert.equal(await f.page.evaluate(()=>__attempts),1);
 await f.page.evaluate(()=>window.__blockedAudio=false);await f.page.locator('h1').click();await f.audio();
 assert.equal(await f.page.evaluate(()=>__plays),2,'Tras interacción se recupera el audio existente');await f.close();checks++;

 const scheduled={...empty(),horarios:[{...comment,agenda_horario_at:Math.floor(now/1000)+61,agenda_horario_mensaje:'Comentario programado'}]};
 f=await fixture(browser,{data:scheduled});await f.audio();assert.equal(await f.page.evaluate(()=>__plays),2,'Aviso programado antes de la hora');
 await f.advance(60000);await f.audio();assert.equal(await f.page.evaluate(()=>__plays),4,'Sonido a la hora conservando horarios');await f.close();checks++;

 f=await fixture(browser);await f.advance(3000);f.set(withComment({...comment,agenda_solo_aviso:true}));await f.refresh();await f.audio();
 await f.page.locator('[data-agenda-marketing-toast] .convocatoria-activation-toast-close').click();
 assert.equal(f.posts.length,0,'Cerrar aviso no marca leído');
 await f.page.locator('.topbar-reminder-item').click();await new Promise(r=>setTimeout(r,40));
 assert.equal(f.posts.length,1);assert.equal(f.posts[0].body.get('id'),'92');assert.equal(f.posts[0].token,'token-prueba');
 assert.equal(new URL(f.page.url()).pathname,'/','Receptor sin Agenda permanece en su campana');await f.close();checks++;

 f=await fixture(browser,{failRead:true});await f.advance(3000);f.set(withComment({...comment,agenda_solo_aviso:true}));await f.refresh();await f.audio();
 await f.page.locator('.topbar-reminder-item').click();await f.page.waitForSelector('[data-agenda-comment-read-error]');
 assert.equal(await f.page.locator('.topbar-reminder-item').count(),1,'Fallo de lectura conserva el pendiente');await f.close();checks++;

 const conv={id:11,leida:0,titulo:'Convocatoria original',mensaje:'Se conserva',url:'index.php?controller=convocatoria'};
 f=await fixture(browser);await f.advance(3000);const combo=withComment(comment);combo.notificaciones.unshift(conv);combo.no_leidas=2;f.set(combo);await f.refresh();await f.audio();
 assert.equal(await f.page.locator('[data-agenda-marketing-toast]').count(),0,'Respeta el aviso original de convocatorias');
 await f.advance(9000);await f.audio();assert.equal(await f.page.evaluate(()=>__plays),2);assert.equal(await f.page.locator('[data-agenda-marketing-toast]').count(),1);await f.close();checks++;

 const context=await browser.newContext();const a=await fixture(browser,{context}),b=await fixture(browser,{context});
 await Promise.all([a.advance(3000),b.advance(3000)]);a.set(withComment(comment));b.set(withComment(comment));await Promise.all([a.refresh(),b.refresh()]);await Promise.all([a.audio(),b.audio()]);
 assert.equal((await a.page.evaluate(()=>__plays))+(await b.page.evaluate(()=>__plays)),2,'Sin duplicar audio entre pestañas');await a.close();await b.close();await context.close();checks++;

 f=await fixture(browser,{noMarketing:true});await f.advance(3000);assert.equal(await f.page.evaluate(()=>__plays),0,'No altera otros perfiles');await f.close();checks++;
 console.log('OK: '+checks+' escenarios de Marketing con el motor real de Agenda, audio instrumentado, campana, horarios, permisos, lecturas y deduplicación.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
