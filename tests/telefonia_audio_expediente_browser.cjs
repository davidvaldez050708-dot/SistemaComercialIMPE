const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright'),repo=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let checks=0,downloads=0;
 const calls=[{id:1,resultado:'CONTACTADO',duracion_segundos:12,fecha_inicio:'2026-10-18 12:00:00',grabacion_transferida_pbx:'pbx_verified_001'},
 {id:2,resultado:'CONTACTADO',duracion_segundos:6,estado_grabacion:'AUDIO_PENDIENTE',evidencia_grabacion_pendiente:'La centralita aún no ha confirmado el archivo de audio de esta llamada.'},
 {id:3,resultado:'BUZON_VOZ',duracion_segundos:0,estado_grabacion:'SOLO_HISTORIAL'}];
 await page.route('http://audio.test/**',route=>{const u=new URL(route.request().url());if(u.pathname.endsWith('grabacion_transferida_pbx.php')){assert.equal(u.searchParams.get('pbx_call_id'),'pbx_verified_001');if(u.searchParams.has('verificar')){checks++;return route.fulfill({json:{ok:true,disponible:true,segmentos:2}});}downloads++;return route.fulfill({status:404});}
 return route.fulfill({contentType:'text/html',body:`<!doctype html><meta charset="utf-8"><style>${read('public/css/seguimiento_llamadas_expediente.css')}</style><body><nav class="linkage-expediente-section-nav"><span data-expediente-tab="interacciones">Interacciones</span></nav><div class="linkage-expediente-content"><div class="linkage-expediente-pane" data-expediente-pane="interacciones"></div></div><script>window.IMPE_CONSULTAR_LLAMADAS_EXPEDIENTE=async()=>({llamadas:${JSON.stringify(calls)}});</script><script>${read('public/javascript/telefonia_segmentos_player.js')}</script><script>${read('public/javascript/seguimiento_llamadas_expediente.js')}</script></body>`});});
 await page.goto('http://audio.test/index.php?controller=seguimientoVinculacion&action=detalle&id=1');await page.locator('[data-expediente-tab=llamadas]').click();
 const row=id=>page.locator('[data-call-interaction-id="'+id+'"]');await row(1).waitFor();assert.equal(checks,0);assert.equal(downloads,0,'no descarga audio al cargar historial');
 await row(1).getByRole('button',{name:'Consultar grabación'}).click();await row(1).locator('audio').first().waitFor({state:'attached'});assert.equal(checks,1);assert.equal(await row(1).locator('audio').count(),2);
 const src=await row(1).locator('audio').evaluateAll(es=>es.map(e=>e.src));assert.ok(src[0].includes('segmento=0')&&src[1].includes('segmento=1'));assert.equal(downloads,0,'segmentos privados con preload none');
 assert.match(await row(2).textContent(),/Grabación pendiente de confirmar/);assert.match(await row(2).textContent(),/no ha confirmado el archivo/);assert.equal(await row(2).locator('audio,button').count(),0,'pendiente no inventa reproductor');
 assert.equal(await row(3).getByRole('button',{name:'Consultar grabación'}).count(),0,'buzón sin botón de audio');
 assert.deepEqual(errors,[]);console.log('Audio/expediente: dos segmentos verificados, carga diferida, evidencia pendiente y buzón sin audio: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
