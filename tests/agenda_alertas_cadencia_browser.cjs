const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
const source=fs.readFileSync(path.join(root,'public/javascript/agenda_alertas.js'),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});
 try {
  const context=await browser.newContext();
  await context.route('http://localhost/**',route=>{
   const url=route.request().url();
   if(url.includes('agenda_alertas.js'))return route.fulfill({contentType:'text/javascript',body:source});
   if(url.includes('recordatorios.js'))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(root,'public/javascript/recordatorios.js'),'utf8')});
   if(url.endsWith('.mp3'))return route.fulfill({contentType:'audio/mpeg',body:fs.readFileSync(path.join(root,'public/audio/agenda-aviso.mp3'))});
   return route.fulfill({contentType:'text/html',body:'<!doctype html><html><body><button id="agenda">Abrir Agenda</button></body></html>'});
  });
  async function fixture(user=5,real=false){
   const p=await context.newPage();await p.clock.install({time:new Date('2026-11-18T18:00:00Z')});
   await p.goto('http://localhost/agenda');
   await p.evaluate(({user,real})=>{
    window.IMPE_CURRENT_USER_ID=user;window.plays=0;window.visuals=[];window.permissionRequests=0;window.busy=false;
    window.allowAudio=false;document.addEventListener('pointerdown',()=>{window.allowAudio=true;});
    localStorage.setItem('impe:agenda-alertas:'+user+':preferencias',JSON.stringify({sound:false,lead:5,browser:false}));
    if(real){const Native=window.Audio;window.Audio=function(src){const a=new Native(src);window.audioReal=a;return a;};}
    else window.Audio=class extends EventTarget{play(){if(!window.allowAudio)return Promise.reject(new DOMException('Primer gesto requerido','NotAllowedError'));if(window.blockAudio)return Promise.reject(Error('bloqueado'));window.plays++;setTimeout(()=>this.dispatchEvent(new Event('ended')),300);return Promise.resolve();}pause(){}};
    window.Notification=class {static permission='denied';static requestPermission(){window.permissionRequests++;return Promise.resolve('denied');}};
   },{user,real});
   await p.addScriptTag({url:'http://localhost/public/javascript/agenda_alertas.js'});
   await p.evaluate(()=>window.IMPEAgendaAlerts.setPresenter(item=>{
    if(window.busy)return false;
    window.visuals.push(item);window.IMPEAgendaAlerts.onToastShown(item);return true;
   }));
   return p;
  }
  async function ingest(p,item){await p.evaluate(item=>window.IMPEAgendaAlerts.ingest({usuario_id:window.IMPE_CURRENT_USER_ID,servidor_at:Date.now()/1000,horarios:[item]}),item);}
  for(const kind of ['reunion','comentario']){
   const p=await fixture(kind==='reunion'?5:6);await p.click('#agenda');
   const at=await p.evaluate(()=>Date.now()/1000+1800);
   const item={agenda_horario_at:at,agenda_horario_mensaje:'Prueba '+kind,url:'/index.php?controller=agendaReunion&action=index',
    ...(kind==='reunion'?{reunion_id:18}:{agenda_comentario_id:19})};
   const phases=[30,10,5,1,0];
   for(let i=0;i<phases.length;i++){
    if(i)await p.clock.fastForward((phases[i-1]-phases[i])*60000);
    await ingest(p,item);await p.waitForFunction(n=>window.visuals.length===n,i+1);
    await p.waitForFunction(n=>window.plays===n,i+1);
    assert.match(await p.evaluate(()=>window.visuals.at(-1).mensaje),phases[i]?new RegExp('En '+phases[i]+' minuto'):/Comienza ahora/);
    for(let q=0;q<3;q++)await ingest(p,item);
    assert.equal(await p.evaluate(()=>window.visuals.length),i+1,'No repetir fase al consultar');
    assert.equal(await p.evaluate(()=>window.plays),i+1,'Un sonido por fase');
   }
   assert.equal(await p.evaluate(()=>window.permissionRequests),0,'No solicitar activación');
   assert.equal(await p.evaluate(()=>window.IMPEAgendaAlerts.preferences().sound),true,'Ignorar ajuste antiguo desactivado');
   if(kind==='reunion'){
    const q=await fixture(5);await q.click('#agenda');await ingest(q,item);await q.waitForTimeout(50);
    assert.equal(await q.evaluate(()=>window.visuals.length+window.plays),0,'Deduplicar entre pestañas');
   }
  }
  const p=await fixture(7);const item={reunion_id:7,agenda_horario_at:await p.evaluate(()=>Date.now()/1000+600)};
  await ingest(p,item);await p.waitForFunction(()=>window.visuals.length===1);
  assert.equal(await p.evaluate(()=>window.plays),0);
  await p.click('#agenda');await p.waitForFunction(()=>window.plays===1);
  const busy=await fixture(8);await busy.click('#agenda');await busy.evaluate(()=>window.busy=true);
  await ingest(busy,{reunion_id:8,agenda_horario_at:await busy.evaluate(()=>Date.now()/1000+600)});
  await busy.waitForTimeout(50);assert.equal(await busy.evaluate(()=>window.plays),0,'Sin sonido antes del toast');
  await busy.evaluate(()=>window.busy=false);await busy.clock.runFor(1000);await busy.waitForFunction(()=>window.plays===1);
  const real=await fixture(9,true);await real.click('#agenda');
  await ingest(real,{reunion_id:9,agenda_horario_at:await real.evaluate(()=>Date.now()/1000+600)});
  await real.waitForFunction(()=>window.audioReal.currentTime>0&&!window.audioReal.muted);
  assert.equal(await real.evaluate(()=>window.audioReal.volume),0.7);
  const integration=await fixture(10);await integration.click('#agenda');
  await integration.route('http://localhost/pendientes',route=>route.fulfill({json:{ok:true,total:1,recordatorios:[{
   agenda_comentario_id:88,agenda_comentario_evento:'88|nuevo',nombre_entidad:'Comentario de prueba',accion:'Revisar agenda',estado:'normal'
  }]}}));
  await integration.evaluate(()=>document.body.insertAdjacentHTML('beforeend','<div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div>'));
  await integration.addScriptTag({url:'http://localhost/public/javascript/recordatorios.js'});
  await integration.locator('.agenda-comment-toast').waitFor();await integration.waitForFunction(()=>window.plays===1);
  assert.match(await integration.locator('[data-reminder-content]').innerText(),/Comentario de prueba/);
  assert.ok(!fs.readFileSync(path.join(root,'app/views/seguimiento_vinculacion/agenda.php'),'utf8').includes('data-agenda-alertas'),'Sin panel de activación');
  console.log('OK: reuniones y comentarios a 30/10/5/1 minutos, vencimiento, sonido automático al aparecer toast, primer gesto, audio real, deduplicación y campana.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
