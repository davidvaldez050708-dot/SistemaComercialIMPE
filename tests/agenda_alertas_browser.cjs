const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'public/javascript/agenda_alertas.js'), 'utf8');
const html = `<!doctype html><html><body><div data-agenda-alertas>
<input name="agenda_sonido" type="checkbox"><input name="agenda_navegador" type="checkbox">
<select name="agenda_anticipacion">${[5,10,15,30].map(n=>`<option>${n}</option>`).join('')}</select>
<button data-agenda-probar-sonido>Probar</button><p data-agenda-alertas-status></p></div></body></html>`;
(async () => {
 const browser = await chromium.launch({headless:true, channel:process.env.PLAYWRIGHT_CHANNEL || 'chrome'});
 try {
  const context = await browser.newContext();
  await context.route('http://localhost/**', async route => {
   if (route.request().url().includes('agenda_alertas.js')) return route.fulfill({contentType:'text/javascript', body:source});
   if (route.request().url().includes('recordatorios.js')) return route.fulfill({contentType:'text/javascript', body:fs.readFileSync(path.join(root,'public/javascript/recordatorios.js'),'utf8')});
   if (route.request().url().endsWith('.mp3')) return route.fulfill({contentType:'audio/mpeg',body:fs.readFileSync(path.join(root,'public/audio/agenda-aviso.mp3'))});
   return route.fulfill({contentType:'text/html',body:html});
  });
  async function pageFor(user=5, denied=false, storageBlocked=false) {
   const p = await context.newPage();
   await p.clock.install({time:new Date('2026-11-18T18:00:00Z')});
   await p.goto('http://localhost/test');
   await p.evaluate(({user,denied,storageBlocked})=>{
    window.IMPE_CURRENT_USER_ID=user; window.plays=0; window.notices=[];window.visuals=[];window.requests=0;
    class FakeAudio extends EventTarget {play(){if(window.blockPlay)return Promise.reject(Error('Autoplay bloqueado'));if(!this.muted){window.plays++;setTimeout(()=>this.dispatchEvent(new Event('ended')),600);}return Promise.resolve();} pause(){} }
    window.Audio=FakeAudio;
    class FakeNotification {static permission=denied?'denied':'default'; static requestPermission(){window.requests++;this.permission=denied?'denied':'granted';return Promise.resolve(this.permission);} constructor(title,data){window.notices.push({title,...data});} }
    window.Notification=FakeNotification;
    if(storageBlocked)Object.defineProperty(window,'localStorage',{get(){throw new Error('blocked');}});
   },{user,denied,storageBlocked});
   await p.addScriptTag({url:'http://localhost/public/javascript/agenda_alertas.js'});
   await p.evaluate(()=>window.IMPEAgendaAlerts.setPresenter(item=>{window.visuals.push(item);return true;}));
   return p;
  }
  const p = await pageFor();
  assert.equal(await p.evaluate(()=>window.requests),0,'No pedir permisos al cargar');
  assert.equal(await p.evaluate(()=>window.IMPEAgendaAlerts.preferences().lead),10);
  await p.click('[name=agenda_navegador]');
  await p.click('[data-agenda-probar-sonido]');
  await p.evaluate(()=>{window.plays=0;window.IMPEAgendaAlerts.ingest({servidor_at:Date.now()/1000,recordatorios:[],horarios:[]});});
  await p.clock.runFor(4000);
  const at = await p.evaluate(()=>Date.now()/1000+600);
  const event={reunion_id:8,agenda_horario_at:at,agenda_horario_mensaje:'Reunión prueba',url:'/index.php?controller=agendaReunion&action=index&reunion_id=8'};
  await p.evaluate(item=>window.IMPEAgendaAlerts.ingest({servidor_at:Date.now()/1000,horarios:[item]}),event);
  await p.waitForFunction(()=>window.notices.length===1);
  assert.equal(await p.evaluate(()=>window.plays),1);
  assert.equal(await p.evaluate(()=>window.visuals.length),1);
  for(let i=0;i<3;i++)await p.evaluate(item=>window.IMPEAgendaAlerts.ingest({horarios:[item]}),event);
  await p.waitForTimeout(50);
  assert.equal(await p.evaluate(()=>window.notices.length),1,'No duplicar por consultas');
  const q=await pageFor();
  await q.evaluate(item=>window.IMPEAgendaAlerts.ingest({horarios:[item]}),event);
  await q.waitForTimeout(50);
  assert.equal(await q.evaluate(()=>window.notices.length+window.visuals.length),0,'No duplicar entre pestañas');
  await p.selectOption('[name=agenda_anticipacion]','15');
  assert.equal(await p.evaluate(()=>window.IMPEAgendaAlerts.preferences().lead),15);
  const refresh=await pageFor();
  assert.equal(await refresh.evaluate(()=>window.IMPEAgendaAlerts.preferences().lead),15,'Preferencias persistentes');
  const other=await pageFor(7,true);
  assert.equal(await other.evaluate(()=>window.IMPEAgendaAlerts.preferences().lead),10,'Separación por usuario');
  await other.click('[name=agenda_navegador]');
  assert.equal(await other.isChecked('[name=agenda_navegador]'),false,'Permiso denegado');
  await other.evaluate(()=>window.IMPEAgendaAlerts.ingest({horarios:[{reunion_id:99,agenda_horario_at:Date.now()/1000+300}]}));
  await other.waitForFunction(()=>window.visuals.length===1);
  assert.equal(await other.evaluate(()=>window.notices.length),0);
  const old={agenda_comentario_id:1,agenda_autor_id:8,agenda_creado_at:at-1000,nombre_entidad:'Autor',accion:'Comentario antiguo'};
  await p.evaluate(item=>window.IMPEAgendaAlerts.ingest({recordatorios:[item]}),old);
  await p.waitForTimeout(50);
  assert.equal(await p.evaluate(()=>window.notices.length),2,'No anunciar historial; segunda alerta corresponde al cambio a 15 min');
  await p.clock.runFor(4000);
  await p.evaluate(()=>window.IMPEAgendaAlerts.ingest({recordatorios:[{agenda_comentario_id:2,agenda_autor_id:8,agenda_creado_at:Date.now()/1000,nombre_entidad:'Autor',accion:'Comentario nuevo'}]}));
  await p.waitForFunction(()=>window.notices.length===3);
  await p.evaluate(()=>window.IMPEAgendaAlerts.ingest({recordatorios:[{agenda_comentario_id:3,agenda_autor_id:5,agenda_creado_at:Date.now()/1000}]}));
  await p.waitForTimeout(50);
  assert.equal(await p.evaluate(()=>window.notices.length),3,'No avisar al autor');
  const playsBeforeDisable = await p.evaluate(()=>window.plays);
  await p.uncheck('[name=agenda_sonido]');
  await p.evaluate(()=>window.IMPEAgendaAlerts.ingest({horarios:[{reunion_id:44,agenda_horario_at:Date.now()/1000}]}));
  await p.waitForFunction(()=>window.notices.length===4);
  assert.equal(await p.evaluate(()=>window.notices.at(-1).title),'Hora de la actividad');
  assert.equal(await p.evaluate(()=>window.plays),playsBeforeDisable,'Sonido desactivado');
  const blocked=await pageFor(14);
  await blocked.evaluate(()=>window.blockPlay=true);
  await blocked.click('[name=agenda_navegador]');
  await blocked.click('[data-agenda-probar-sonido]');
  await blocked.evaluate(()=>window.IMPEAgendaAlerts.ingest({horarios:[{reunion_id:66,agenda_horario_at:Date.now()/1000}]}));
  await blocked.waitForFunction(()=>window.notices.length===1&&window.visuals.length===1);
  assert.equal(await blocked.evaluate(()=>window.plays),0,'Autoplay rechazado no rompe avisos');
  await blocked.evaluate(()=>{window.blockPlay=false;document.dispatchEvent(new PointerEvent('pointerdown'));});
  await blocked.waitForFunction(()=>window.plays===1);
  await blocked.evaluate(()=>window.IMPEAgendaAlerts.ingest({horarios:[{reunion_id:66,agenda_horario_at:Date.now()/1000}]}));
  assert.equal(await blocked.evaluate(()=>window.plays),1,'Reintentar audio bloqueado sin duplicar notificación');
  const unsupported=await pageFor(15);
  await unsupported.evaluate(()=>delete window.Notification);
  await unsupported.click('[name=agenda_navegador]');
  assert.equal(await unsupported.isChecked('[name=agenda_navegador]'),false);
  const noStorage=await pageFor(9,false,true);
  await noStorage.selectOption('[name=agenda_anticipacion]','5');
  assert.equal(await noStorage.evaluate(()=>window.IMPEAgendaAlerts.preferences().lead),5);
  // Comprobar que Chromium decodifica el MP3 real, sin llamadas externas.
  const decode=await context.newPage();await decode.goto('http://localhost/audio');
  const duration=await decode.evaluate(()=>new Promise((resolve,reject)=>{const a=document.createElement('audio');a.onloadedmetadata=()=>resolve(a.duration);a.onerror=()=>reject(Error('MP3 inválido'));a.src='/public/audio/agenda-aviso.mp3';}));
  assert.ok(duration>0&&duration<2);
  const realAudio = await context.newPage();
  await realAudio.goto('http://localhost/audio-real');
  await realAudio.evaluate(()=>{
   window.IMPE_CURRENT_USER_ID=18;
   const NativeAudio=window.Audio;
   window.Audio=function(src){const a=new NativeAudio(src);window.realAgendaAudio=a;return a;};
  });
  await realAudio.addScriptTag({url:'http://localhost/public/javascript/agenda_alertas.js'});
  await realAudio.click('[data-agenda-probar-sonido]');
  await realAudio.waitForFunction(()=>document.querySelector('[data-agenda-alertas-status]').textContent.includes('Sonido reproducido'));
  assert.equal(await realAudio.evaluate(()=>window.realAgendaAudio.muted),false,'Prueba real sin reproducción muda concurrente');
  assert.equal(await realAudio.evaluate(()=>window.realAgendaAudio.volume),0.7);
  const integration = await pageFor(11);
  await integration.route('http://localhost/pendientes', route=>route.fulfill({json:{ok:true,total:2,servidor_at:Date.now()/1000,
   recordatorios:[{id:28,nombre_entidad:'Seguimiento conservado',accion:'Volver a llamar',estado:'normal'},
    {agenda_comentario_id:88,agenda_comentario_evento:'88|nuevo',agenda_autor_id:8,agenda_creado_at:Date.now()/1000-86400,
     nombre_entidad:'Comentario antiguo conservado',accion:'Revisar informe',estado:'normal'}],
   horarios:[{reunion_id:71,agenda_horario_at:Date.now()/1000+500,agenda_horario_mensaje:'Reunión integrada',url:'/index.php?controller=agendaReunion&action=index&reunion_id=71'}]}}));
  await integration.evaluate(()=>document.body.insertAdjacentHTML('beforeend','<div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div>'));
  await integration.addScriptTag({url:'http://localhost/public/javascript/recordatorios.js'});
  await integration.locator('.topbar-reminder-item').first().waitFor();
  await integration.locator('.reminder-toast').waitFor();
  assert.match(await integration.locator('.reminder-toast').innerText(),/Reunión integrada/);
  assert.match(await integration.locator('[data-reminder-content]').innerText(),/Seguimiento conservado/);
  assert.match(await integration.locator('[data-reminder-content]').innerText(),/Comentario antiguo conservado/);
  assert.equal(await integration.locator('.agenda-comment-toast').count(),0,'Historial visible sin anunciarlo como nuevo');
  for(const mode of ['ausente','fallo']) {
   const fallback=await pageFor(mode==='ausente'?21:22);
   const errors=[];fallback.on('pageerror',e=>errors.push(e.message));
   await fallback.evaluate(mode=>{window.IMPEAgendaAlerts=mode==='ausente'?undefined:{
    ingest(){throw Error('Fallo opcional simulado');},setPresenter(){},suspend(){},preferences(){return {};}
   };},mode);
   await fallback.route('http://localhost/pendientes',route=>route.fulfill({json:{ok:true,total:1,
    recordatorios:[{agenda_comentario_id:99,agenda_comentario_evento:'99|nuevo',nombre_entidad:'Aviso conservado',accion:'Comentario',estado:'normal'}]}}));
   await fallback.evaluate(()=>document.body.insertAdjacentHTML('beforeend','<div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div>'));
   await fallback.addScriptTag({url:'http://localhost/public/javascript/recordatorios.js'});
   await fallback.locator('.agenda-comment-toast').waitFor();
   assert.match(await fallback.locator('[data-reminder-content]').innerText(),/Aviso conservado/);
   assert.deepEqual(errors,[],'Un fallo opcional no bloquea la campana');
  }
  const commercial=await pageFor(23);
  let queries=0,release;
  await commercial.route('http://localhost/comercial', async route=>{
   queries++;
   const sequence=queries;
   if(sequence===1)await new Promise(resolve=>{release=resolve;});
   return route.fulfill({json:{ok:true,comercial_ok:true,total:1,recordatorios:[{
    comercial_id:sequence,actividad_ciclo:1,fecha:'2026-11-18 12:00:00',estado:'normal',
    nombre_entidad:sequence===1?'Resultado obsoleto':'Resultado comercial actualizado',accion:'Seguimiento comercial'
   }]}});
  });
  await commercial.evaluate(()=>document.body.insertAdjacentHTML('beforeend','<div data-reminder-root data-reminder-endpoint="/comercial"><span data-reminder-badge></span><div data-reminder-content></div></div>'));
  await commercial.addScriptTag({url:'http://localhost/public/javascript/recordatorios.js'});
  while(!release)await commercial.waitForTimeout(10);
  await commercial.evaluate(()=>window.dispatchEvent(new CustomEvent('impe:comercial-actividad-refresh')));
  release();
  await commercial.getByText('Resultado comercial actualizado',{exact:false}).waitFor();
  assert.doesNotMatch(await commercial.locator('[data-reminder-content]').innerText(),/Resultado obsoleto/);
  assert.equal(queries,2,'Conservar refresco comercial pendiente sin perder solicitudes');
  console.log('Agenda: preferencias, anticipación, vencimiento, sonido, comentarios, autor, historial, pestañas, permisos y MP3 verificados.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
