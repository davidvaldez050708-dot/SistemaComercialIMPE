// Integración real: Bootstrap, estilos compartidos y el transformador global de Marketing.
// Todas las llamadas y el guardado son simulados: no requiere cuentas ni una centralita.
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const bootstrap = process.env.BOOTSTRAP_MODULE_PATH || path.dirname(require.resolve('bootstrap/package.json'));
const repo = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(repo, p), 'utf8');
const out = process.env.MARKETING_BROWSER_OUTPUT;
if (out) fs.mkdirSync(out, {recursive:true});
let view = cp.execFileSync('php', [path.join(__dirname, 'fixtures/marketing_recepcion_vista.php')], {encoding:'utf8'});
for (const [key,file] of [['history','historial_recepcion_marketing.php'],['recording','grabacion_recepcion_marketing.php'],['result','resultado_recepcion_marketing.php']]) {
    view = view.replace(new RegExp(`data-${key}-url="[^"]*"`), `data-${key}-url="http://recepcion.test/api/${file}"`);
}
const cssFiles = ['global.css','dashboard.css','marketing_selectores_uniformes.css',
    'seguimiento_llamadas_expediente.css','seguimiento_llamadas_player.css',
    'telefonia_recepcion_marketing.css','responsive_sistema.css'];
const css = cssFiles.map(p => p === 'telefonia_recepcion_marketing.css' && process.env.MARKETING_DEPLOY_CSS
    ? fs.readFileSync(process.env.MARKETING_DEPLOY_CSS, 'utf8') : read('public/css/'+p)).join('\n');
const calls = [
    {pbx_call_id:'out_transfer',tipo:'Saliente',fecha:'2026-10-16 21:46:00',numero:'5551234567',estado:'Transferida',estado_cierre:'Completada',segundos:8,destino_transferencia:'101',tiene_grabacion:false,excluir_grabacion:true,grabacion_transferida:false,finalizada:true,resultado:'BUZON',resultado_etiqueta:'Buzón de voz',nota:'Se escuchó un buzón antes de transferir.'},
    {pbx_call_id:'out_voicemail',tipo:'Saliente',fecha:'2026-10-16 21:15:00',numero:'5557654321',estado:'Completada',segundos:6,destino_transferencia:'',tiene_grabacion:true,finalizada:true,requiere_resultado:true}
];
const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
${process.env.MARKETING_PREVIEW_FONTS ? '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">' : ''}
<style>${fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')}\n${css}
body{background:#f4f7fb}.preview-title{font-size:12px;color:#718097;margin-bottom:12px}
#reference{position:fixed;left:-10000px;width:500px;top:0}
</style></head><body><main class="admin-content"><p class="preview-title">Vista previa · datos de ejemplo</p>${view}</main>
<div id="reference" class="system-form-modal"><h5 class="system-form-modal-title">Seguimiento</h5><label class="form-label">Resultado</label><select class="form-select" data-native-select><option>Resultado</option></select><button class="btn btn-system-save">Guardar</button></div>
<div hidden><select id="global-select-control"><option>Control del transformador global</option></select></div>
<script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8')}</script>
<script>window.IMPE_CAN_CALL_OUT=true;window.IMPE_TELEPHONY_PERSISTENT={probe:async()=>({permite_salientes:true}),getState:()=>({active:false}),openDialer:()=>true};</script>
<script>${read('public/javascript/selectores_uniformes.js')}</script>
<script>${read('public/javascript/telefonia_recepcion_marketing.js')}</script></body></html>`;

(async () => {
    const browser = await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL ? {channel:process.env.PLAYWRIGHT_CHANNEL} : {})});
    try {
        const page = await browser.newPage({viewport:{width:1440,height:1000}});
        const errors=[];
        page.on('pageerror', e=>errors.push(e.message));
        let saves=0,failSave=false,releaseSave=null,historyReads=0;
        await page.route('http://recepcion.test/**', async route => {
            const url = route.request().url();
            if (url.includes('historial_recepcion_marketing.php')) {
                historyReads++;
                return route.fulfill({json:{ok:true,recepcion:{configurada:true,extension:'100',total:0,atendidas:0,perdidas:0,salientes:2,transferidas:1,sin_contacto:0,llamadas:calls}}});
            }
            if (url.includes('resultado_recepcion_marketing.php')) {
                if (failSave) return route.fulfill({status:403,json:{ok:false,mensaje:'Sólo puedes registrar llamadas de tu extensión.'}});
                const data=new URLSearchParams(route.request().postData());
                assert.equal(data.get('csrf_token'),'preview-csrf');
                assert.equal(data.get('pbx_call_id'),'out_voicemail');
                await new Promise(resolve=>{releaseSave=resolve;});
                saves++;
                const registro={resultado:data.get('resultado'),resultado_etiqueta:'No disponible',nota:data.get('nota'),
                    excluir_grabacion:true,tiene_grabacion:false,grabacion_transferida:false,grabacion_salida_pendiente:false};
                Object.assign(calls[1],registro,{requiere_resultado:false});
                return route.fulfill({json:{ok:true,registro}});
            }
            return route.fulfill({contentType:'text/html',body:html});
        });
        await page.goto('http://recepcion.test/');
        await page.evaluate(()=>document.fonts.ready);
        // Recepción en Mis llamadas mantiene el historial visible por defecto.
        // Los fixtures antiguos conservan el botón plegable.
        const toggle=page.locator('[data-marketing-reception-details-toggle]');
        if(await toggle.count())await toggle.click();
        await page.waitForFunction(()=>document.querySelectorAll('[data-marketing-result]').length===1,
            {timeout:10000}).catch(async error=>{
            const info=await page.evaluate(()=>({
                root:Boolean(document.querySelector('[data-marketing-reception]')),
                rows:document.querySelector('[data-marketing-reception-rows]')?.innerText?.slice(0,800),
                results:document.querySelectorAll('[data-marketing-result]').length,
                dataUrl:document.querySelector('[data-marketing-reception]')?.getAttribute('data-history-url')
            }));
            throw new Error('Recepción no mostró resultado en simulación: '+JSON.stringify({info,errors})+' '+error.message);
        });
        assert.equal(await page.locator('thead th').count(),9);
        const firstRow=page.locator('[data-marketing-reception-rows] > tr').first();
        assert.equal(await firstRow.locator('td').nth(4).innerText(),'Transferida','un único estado en su columna');
        assert.ok((await firstRow.locator('td').nth(5).innerText()).startsWith('Buzón de voz'),'resultado en columna independiente');
        assert.equal(await firstRow.locator('[data-marketing-result]').count(),0,'resultado registrado sin edición');
        assert.equal(await firstRow.locator('td').last().innerText(),'Solo historial','buzón sin audio de transferencia');
        assert.equal(await firstRow.locator('td').last().locator('button').count(),0);
        assert.equal(await page.getByText('Consultar grabación',{exact:true}).count(),1,'conservar audio de llamada respondida aún sin clasificar');
        assert.equal(await page.getByText('Editar resultado',{exact:true}).count(),0);
        assert.equal(await page.locator('[data-marketing-reception-callback-status]').count(),0,'sin bloque azul de avisos');
        assert.equal(await page.locator('#global-select-control.uniform-select-native').count(),1,'el transformador global sí se ejecuta');
        assert.equal(await page.locator('.marketing-reception-panel select[data-native-select]').count(),3);
        assert.equal(await page.locator('.marketing-reception-panel .uniform-select').count(),0,'recepción usa los controles de Seguimiento');
        const controls=page.locator('.marketing-reception-filters input,.marketing-reception-filters select,.marketing-reception-refresh');
        const boxes=await controls.evaluateAll(els=>els.map(e=>{const r=e.getBoundingClientRect();return {y:r.y,h:r.height};}));
        assert.equal(new Set(boxes.map(b=>b.y)).size,1,'filtros alineados');
        assert.ok(boxes.every(b=>b.h===42));
        const filter=page.locator('[data-marketing-reception-filter]');
        assert.equal(await filter.locator('option').count(),7,'filtro compacto de siete opciones');
        await filter.selectOption('Transferida');
        assert.equal(await page.locator('[data-marketing-result]').count(),0);
        await filter.selectOption('respondidas');
        assert.equal(await page.locator('[data-marketing-result]').count(),1);
        await filter.selectOption('no-completadas');
        assert.equal(await page.locator('[data-marketing-result]').count(),0);
        await filter.selectOption('');
        if(out) await page.screenshot({path:path.join(out,'filtros-escritorio.png'),fullPage:true});
        await filter.selectOption('pendientes');
        const modal=page.locator('[data-marketing-result-dialog]');
        const select=page.locator('[data-marketing-result-select]');
        const opened=()=>page.waitForFunction(()=>document.querySelector('[data-marketing-result-dialog]').classList.contains('show') && document.activeElement===document.querySelector('[data-marketing-result-select]'));
        const closed=()=>page.waitForFunction(()=>!document.querySelector('.modal-backdrop') && !document.body.classList.contains('modal-open'));
        await page.locator('[data-marketing-result]').click();
        await opened();
        assert.equal(await select.inputValue(),'');
        await select.evaluate(e=>e.blur());
        const styles=await page.evaluate(()=>{
            const result=[];
            for (const selector of ['.system-form-modal-title','.form-label','.form-select','.btn-system-save']) {
                const a=getComputedStyle(document.querySelector('#modalResultadoRecepcionMarketing '+selector));
                const b=getComputedStyle(document.querySelector('#reference '+selector));
                for (const prop of ['fontSize','fontWeight','borderRadius','backgroundColor']) result.push([selector,prop,a[prop],b[prop]]);
            }
            return result;
        });
        styles.forEach(([selector,prop,a,b])=>assert.equal(a,b,`estilo compartido ${selector} ${prop}`));
        if(out) await page.screenshot({path:path.join(out,'modal-escritorio.png')});
        await select.focus();
        await select.press('End');
        assert.equal(await select.inputValue(),'SIN_CLASIFICAR','última opción accesible por teclado');
        await page.keyboard.press('Tab');
        await page.keyboard.press('Escape');
        await closed();
        assert.equal(await page.locator('[data-marketing-result]').evaluate(e=>e===document.activeElement),true,'foco devuelto al botón de origen');

        // Ventanas cortas: rueda dentro del cuerpo, encabezado/pie siempre accesibles.
        for (const size of [{width:820,height:460},{width:390,height:600},{width:740,height:360}]) {
            await page.setViewportSize(size);
            await page.locator('[data-marketing-result]').click();
            await opened();
            const bounds=await modal.evaluate(e=>{
                const box=s=>{const r=e.querySelector(s).getBoundingClientRect();return {top:r.top,bottom:r.bottom,left:r.left,right:r.right};};
                return {header:box('.modal-header'),footer:box('.modal-footer'),body:box('.modal-body'),overflow:e.scrollHeight>e.clientHeight};
            });
            assert.ok(bounds.header.top>=0 && bounds.footer.bottom<=size.height,`encabezado y pie dentro de ${size.width}x${size.height}`);
            assert.ok(bounds.footer.left>=0 && bounds.footer.right<=size.width);
            assert.equal(bounds.overflow,false,'el modal completo no necesita scroll');
            if(size.height<=360) {
                const body=modal.locator('.modal-body');
                assert.equal(await body.evaluate(e=>e.scrollHeight>e.clientHeight),true,'sólo el cuerpo tiene scroll');
                await body.hover();await page.mouse.wheel(0,240);
                await page.waitForFunction(()=>document.querySelector('#modalResultadoRecepcionMarketing .modal-body').scrollTop>0);
                const after=await modal.locator('.modal-footer').boundingBox();
                assert.equal(after.y,bounds.footer.top,'pie fijo al desplazar con rueda');
            }
            if(out) await page.screenshot({path:path.join(out,`modal-${size.width}x${size.height}.png`)});
            await modal.locator('.btn-close').click();await closed();
        }
        await page.setViewportSize({width:1440,height:1000});
        await page.locator('[data-marketing-result]').click();await opened();
        await select.selectOption('NO_DISPONIBLE');
        await page.locator('[data-marketing-result-note]').fill('Locución del operador; reintentar.');
        failSave=true;
        await page.locator('[data-marketing-result-save]').click();
        const errorToast=page.locator('.system-toast-error');
        await errorToast.waitFor({state:'visible'});
        await page.waitForFunction(()=>document.querySelector('.system-toast-error.show:not(.showing)'));
        await page.waitForFunction(()=>getComputedStyle(document.querySelector('.system-toast-error')).opacity==='1');
        assert.equal(await modal.evaluate(e=>e.classList.contains('show')),true,'error conserva formulario');
        assert.equal(await page.locator('[data-marketing-result-note]').inputValue(),'Locución del operador; reintentar.');
        assert.equal(await errorToast.getAttribute('role'),'alert');
        assert.equal(await errorToast.evaluate(e=>getComputedStyle(e.closest('.toast-container')).position),'fixed');
        const errorLayer=await errorToast.evaluate(e=>{
            const r=e.getBoundingClientRect();
            return {opacity:getComputedStyle(e).opacity,container:getComputedStyle(e.parentElement).zIndex,
                above:document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)?.closest('.toast')===e};
        });
        assert.equal(errorLayer.opacity,'1');
        assert.equal(errorLayer.above,true,'toast de error visible sobre el fondo del modal');
        if(out) await page.screenshot({path:path.join(out,'toast-error.png')});
        await errorToast.evaluate(e=>window.bootstrap.Toast.getOrCreateInstance(e).hide());
        await errorToast.waitFor({state:'detached'});
        failSave=false;
        const beforeReads=historyReads;
        await page.locator('[data-marketing-result-save]').click();
        await page.waitForFunction(()=>document.querySelector('[data-marketing-result-save]').disabled);
        assert.equal(await modal.locator('.btn-close').isDisabled(),true);
        await page.keyboard.press('Escape');
        assert.equal(await modal.evaluate(e=>e.classList.contains('show')),true,'no se cierra durante el guardado');
        releaseSave();
        await closed();
        await page.waitForFunction(()=>document.querySelectorAll('[data-marketing-result]').length===0);
        assert.equal(saves,1);
        assert.ok(historyReads>beforeReads,'historial actualizado después de cerrar');
        await filter.selectOption('');
        assert.equal(await page.locator('[data-marketing-result]').count(),0,'ningún resultado guardado se ofrece para editar');
        assert.equal(await page.getByText('Consultar grabación',{exact:true}).count(),0,'resultado negativo oculta grabación al guardar');
        assert.equal(await page.getByText('Solo historial',{exact:true}).count(),2);
        const successToast=page.locator('.system-toast:not(.system-toast-error)');
        await successToast.waitFor({state:'visible'});
        await page.waitForFunction(()=>document.querySelector('.system-toast:not(.system-toast-error).show:not(.showing)'));
        await page.waitForFunction(()=>getComputedStyle(document.querySelector('.system-toast:not(.system-toast-error)')).opacity==='1');
        assert.equal(await successToast.innerText(),'Resultado guardado.');
        const toastBounds=await successToast.boundingBox();
        assert.ok(toastBounds.x>1000 && toastBounds.y<100,'toast en la esquina superior derecha');
        if(out) await page.screenshot({path:path.join(out,'resultado-definitivo-toast.png'),fullPage:true});
        await successToast.evaluate(e=>window.bootstrap.Toast.getOrCreateInstance(e).hide());
        await successToast.waitFor({state:'detached'});
        await page.evaluate(()=>{
            const fake=document.createElement('button');fake.dataset.marketingResult='out_voicemail';
            document.querySelector('[data-marketing-reception-rows] td').appendChild(fake);fake.click();fake.remove();
        });
        assert.equal(await modal.evaluate(e=>e.classList.contains('show')),false,'rechazar apertura de resultado guardado aunque se inyecte un botón');
        assert.deepEqual(errors,[],'sin errores JavaScript');
        console.log('OK: Seguimiento, filtro compacto, columnas independientes, resultado definitivo, toast de éxito/error, 4 tamaños, rueda y teclado.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
