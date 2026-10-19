const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const css=fs.readFileSync(path.join(__dirname,'../public/css/telefonia_disponibilidad.css'),'utf8');

(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try {
  for(const width of [320,390,768,1024]) {
   const page=await browser.newPage({viewport:{width,height:640}});
   await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">'+
    '<style>'+css+'</style></head><body>'+
    '<section class="impe-incoming-panel">'+
      '<div class="impe-availability-source" data-availability-state="RECHAZADA">'+
        '<strong>No disponible</strong><p>Continúa atendiendo a la persona. Puedes elegir otro responsable o dejar una devolución.</p>'+
        '<div><button class="persistent-phone-action">Solicitar devolución</button></div>'+
      '</div>'+
    '</section>'+
    '<dialog class="impe-availability-dialog" data-availability-incoming>'+
      '<div class="system-form-modal-header"><h2>¿Puedes atender esta llamada?</h2></div>'+
      '<div class="system-form-modal-body"><div class="impe-availability-summary">Responde en 35 s. Aceptar reserva tu disponibilidad durante 30 s.</div></div>'+
      '<div class="system-form-modal-footer"><button class="persistent-phone-action">No disponible</button><button class="persistent-phone-action is-primary">Puedo atender</button></div>'+
    '</dialog></body></html>');
   await page.locator('.impe-availability-dialog').evaluate(x=>x.showModal());
   const metrics=await page.evaluate(()=>{
    const summary=document.querySelector('.impe-availability-summary'),
     source=document.querySelector('.impe-availability-source'),
     btn=source.querySelector('button'),icon=getComputedStyle(summary,'::before'),
     bc=getComputedStyle(summary),sc=getComputedStyle(source),br=btn.getBoundingClientRect(),
     sr=source.getBoundingClientRect(),dr=document.querySelector('dialog').getBoundingClientRect();
    return {
     clockFlex:bc.display==='flex'&&bc.alignItems==='center',
     clockStatic:icon.position==='static',
     clockSvg:icon.backgroundImage.includes('data:image/svg+xml'),
     clockSize:[icon.width,icon.height],
     summaryBar:bc.borderLeftWidth,
     sourceBar:sc.borderLeftWidth,
     sourceTop:sc.borderTopWidth,
     bg:sc.backgroundColor,
     buttonHeight:br.height,
     panelHeight:sr.height,
     outside:sr.right>innerWidth+1||sr.left<0,
     dialogOutside:dr.left<0||dr.right>innerWidth+1
    };
   });
   assert(metrics.clockFlex&&metrics.clockStatic&&metrics.clockSvg,'Reloj alineado y SVG real ('+width+')');
   assert.deepEqual(metrics.clockSize,['18px','18px'],'Icono consistente ('+width+')');
   assert.equal(metrics.summaryBar,'1px','Sin barra en cuenta regresiva');
   assert.equal(metrics.sourceBar,metrics.sourceTop,'Sin barra lateral en recepción');
   assert.notEqual(metrics.bg,'rgb(255, 248, 239)','Sin fondo amarillo');
   assert(metrics.buttonHeight<=41&&metrics.buttonHeight>=32,'Botón compacto y accesible');
   assert(!metrics.outside&&!metrics.dialogOutside,'Sin desbordamientos');
   if(width>=768)assert(metrics.panelHeight<140,'Tarjeta compacta en escritorio');
   await page.locator('.impe-availability-source').evaluate(x=>{x.dataset.availabilityState='ACEPTADA'});
   assert.equal(await page.locator('.impe-availability-source').evaluate(x=>getComputedStyle(x).borderLeftWidth),'1px','Aceptada sin franja');
   await page.locator('.impe-availability-source').evaluate(x=>{x.dataset.availabilityState='VENCIDA'});
   assert.equal(await page.locator('.impe-availability-source').evaluate(x=>getComputedStyle(x).borderLeftWidth),'1px','Vencida sin franja');
   await page.locator('[data-availability-incoming]').evaluate(x=>{x.dataset.availabilityUrgent='1'});
   assert.equal(await page.locator('.impe-availability-summary').evaluate(x=>getComputedStyle(x).borderLeftWidth),'1px','Urgente sin franja');
   await page.close();
  }
  console.log('UI disponibilidad: reloj centrado, sin franjas, tarjeta neutra/compacta y responsive en 320,390,768,1024: OK');
 } finally {await browser.close();}
})().catch(err=>{console.error(err);process.exitCode=1;});
