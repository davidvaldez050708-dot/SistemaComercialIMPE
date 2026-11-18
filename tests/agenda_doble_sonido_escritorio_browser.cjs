const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_EXECUTABLE_PATH
  ? {executablePath:process.env.PLAYWRIGHT_EXECUTABLE_PATH} : {channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'})});
 try{
  const context=await browser.newContext();
  await context.route('http://localhost/**',route=>{
   const u=route.request().url();
   for(const name of ['agenda_alertas.js','recordatorios.js'])if(u.includes(name))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(root,'public/javascript',name),'utf8')});
   return route.fulfill({contentType:'text/html',body:'<!doctype html><html><body><main data-agenda-root><button id="agenda">Ver Agenda</button></main><div data-reminder-root data-reminder-endpoint="/pendientes"><span data-reminder-badge></span><div data-reminder-content></div></div></body></html>'});
  });
  async function fixture(user,permission='granted'){
   const p=await context.newPage();await p.clock.install({time:new Date('2026-11-18T18:00:00Z')});await p.goto('http://localhost/agenda');
   await p.evaluate(({user,permission})=>{
    window.IMPE_CURRENT_USER_ID=user;window.plays=[];window.notices=[];window.permissionRequests=0;window.visuals=[];window.allowAudio=false;window.overlap=false;window.testHidden=false;
    Object.defineProperty(document,'hidden',{configurable:true,get:()=>window.testHidden});
    document.addEventListener('pointerdown',()=>{window.allowAudio=true;});
    window.Audio=class extends EventTarget{
     play(){if(!window.allowAudio)return Promise.reject(new DOMException('Gesto requerido','NotAllowedError'));
      if(this.active)window.overlap=true;this.active=true;window.plays.push({at:Date.now(),volume:this.volume});
      setTimeout(()=>{this.active=false;this.dispatchEvent(new Event('ended'));},300);return Promise.resolve();}
     pause(){this.active=false;}
    };
    window.Notification=class{
     static permission=permission;
     static requestPermission(){window.permissionRequests++;this.permission='granted';return Promise.resolve('granted');}
     constructor(title,options){this.entry={title,...options,at:Date.now(),closed:0};window.notices.push(this.entry);}
     close(){this.entry.closed++;}
    };
   },{user,permission});
   await p.addScriptTag({url:'http://localhost/public/javascript/agenda_alertas.js'});
   await p.evaluate(()=>window.IMPEAgendaAlerts.setPresenter(item=>{window.visuals.push(item);window.IMPEAgendaAlerts.onToastShown(item);return true;}));
   return p;
  }
  const p=await fixture(41);await p.click('#agenda');await p.evaluate(()=>window.testHidden=true);
  const event={agenda_comentario_id:701,agenda_horario_at:await p.evaluate(()=>Date.now()/1000+300),agenda_horario_mensaje:'Aviso fuera del sistema',url:'/index.php?controller=agendaReunion&action=index'};
  const feed=()=>p.evaluate(item=>window.IMPEAgendaAlerts.ingest({horarios:[item]}),event);
  await feed();await p.waitForFunction(()=>window.plays.length===2&&window.notices.length===1);
  assert.equal(await p.evaluate(()=>window.visuals.length),0,'En segundo plano, usar notificación de escritorio');
  assert.deepEqual(await p.evaluate(()=>window.plays.map(x=>x.volume)),[1,1]);
  assert.equal(await p.evaluate(()=>window.overlap),false);
  assert.ok(await p.evaluate(()=>window.plays[1].at-window.plays[0].at>=300),'Esperar a que termine el primer sonido');
  assert.equal(await p.evaluate(()=>window.notices[0].requireInteraction),true);
  assert.equal(await p.evaluate(()=>window.notices[0].silent),true,'Evitar un tercer sonido del navegador');
  await feed();await p.clock.runFor(1000);assert.equal(await p.evaluate(()=>window.plays.length),2,'No repetir el par al consultar');
  await p.evaluate(()=>window.testHidden=false);await p.clock.runFor(1000);await p.waitForFunction(()=>window.visuals.length===1);
  assert.equal(await p.evaluate(()=>window.plays.length),2,'Volver al sistema no vuelve a sonar');
  await p.clock.runFor(1000);assert.equal(await p.evaluate(()=>window.notices[0].closed),0,'Permanece más que los 2.6 segundos originales');
  await p.clock.runFor(7000);assert.equal(await p.evaluate(()=>window.notices[0].closed),1,'Cierre programado después del plazo ampliado');
  const permission=await fixture(42,'default');assert.equal(await permission.evaluate(()=>window.permissionRequests),0,'No pedir permiso al cargar');
  await permission.click('#agenda');assert.equal(await permission.evaluate(()=>window.permissionRequests),1);
  await permission.click('#agenda');assert.equal(await permission.evaluate(()=>window.permissionRequests),1,'Pedir una sola vez por usuario');
  const denied=await fixture(43,'denied');await denied.click('#agenda');
  await denied.evaluate(()=>window.IMPEAgendaAlerts.ingest({horarios:[{reunion_id:43,agenda_horario_at:Date.now()/1000+600}]}));
  await denied.waitForFunction(()=>window.plays.length===2&&window.visuals.length===1);
  assert.equal(await denied.evaluate(()=>window.notices.length+window.permissionRequests),0,'Respetar rechazo; conservar aviso interno');
  const internal=await fixture(44);
  await internal.route('http://localhost/pendientes',route=>route.fulfill({json:{ok:true,total:1,recordatorios:[{
   agenda_comentario_id:44,agenda_comentario_evento:'44|nuevo',nombre_entidad:'Comentario visible',accion:'Revisar agenda',estado:'normal'
  }]}}));
  await internal.click('#agenda');await internal.addScriptTag({url:'http://localhost/public/javascript/recordatorios.js'});
  await internal.locator('.agenda-comment-toast').waitFor();await internal.waitForFunction(()=>window.plays.length===2);
  await internal.clock.runFor(5000);assert.equal(await internal.locator('.agenda-comment-toast').count(),1,'Toast sigue visible después de los 2.6 segundos originales');
  await internal.clock.runFor(2000);assert.equal(await internal.locator('.agenda-comment-toast').count(),0,'Toast desaparece después de 6.6 segundos');
  assert.equal(await internal.evaluate(()=>window.plays.length),2);
  console.log('OK ('+browser.version()+'): dos sonidos consecutivos al 100%, notificación de escritorio en segundo plano, duración ampliada, no duplicados al regresar y permisos concedidos/denegados.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
