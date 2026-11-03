// Vistas reales con datos ficticios: no servidor SQL, SDK ni servicios remotos.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {spawnSync}=require('node:child_process');
const browsers=require('playwright'),kind=process.env.IMPE_PANEL_BROWSER||'chromium';
const root=path.resolve(__dirname,'..'),deps=process.env.IMPE_BROWSER_DEPS||path.resolve(root,'../browser-deps/node_modules');
const php=process.env.PHP_BINARY||'php',base='http://127.0.0.1:8159/';
const out=path.join(root,'tmp/prospectos-panel');
let browser,checks=0,slow=0,fail=false,requests=[];
function check(ok,message){assert.ok(ok,message);checks++;}
function render(query){
    const r=spawnSync(php,[path.join(__dirname,'prospectos_panel_visual_fixture.php'),query],{
        cwd:root,encoding:'utf8',env:{...process.env,APP_ENV:'testing',IMPE_BASE_URL:base,IMPE_DB_HOST:'127.0.0.1:1'}});
    assert.equal(r.status,0,r.stderr);assert.ok(!/PHP (Warning|Fatal|Notice|Deprecated)/.test(r.stdout+r.stderr),r.stdout+r.stderr);
    return r.stdout;
}
(async()=>{
    fs.mkdirSync(out,{recursive:true});
    browser=await browsers[kind].launch({headless:true,...(kind==='chromium'&&process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    const context=await browser.newContext({viewport:{width:1440,height:1000}}),errors=[];
    await context.route('**/*',async route=>{
        const request=route.request(),url=new URL(request.url()),name=url.pathname;
        assert.equal(request.method(),'GET','La presentación nunca envía escrituras');
        if(url.origin!==new URL(base).origin)return route.abort();
        if(name==='/fixture/bootstrap.css')return route.fulfill({path:path.join(deps,'bootstrap/dist/css/bootstrap.min.css'),contentType:'text/css'});
        if(name==='/fixture/icons.css')return route.fulfill({path:path.join(deps,'bootstrap-icons/font/bootstrap-icons.min.css'),contentType:'text/css'});
        if(name==='/fixture/fonts.css')return route.fulfill({body:fs.readFileSync(path.join(deps,'@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/',base+'fixture/fonts/'),contentType:'text/css'});
        if(name.startsWith('/fixture/fonts/')&&path.basename(name).startsWith('manrope-'))return route.fulfill({path:path.join(deps,'@fontsource/manrope/files',path.basename(name)),contentType:'font/woff2'});
        if(name.endsWith('.woff2'))return route.fulfill({path:path.join(deps,'bootstrap-icons/font/fonts/bootstrap-icons.woff2'),contentType:'font/woff2'});
        if(name.startsWith('/public/'))return route.fulfill({path:path.join(root,name),contentType:name.endsWith('.css')?'text/css':'application/javascript'});
        if(url.searchParams.get('panel')==='1'){
            requests.push(url.href);
            const delay=slow;if(delay)await new Promise(resolve=>setTimeout(resolve,delay));
            if(fail)return route.fulfill({status:503,body:'No disponible'});
        }
        return route.fulfill({body:render(url.search.slice(1)),contentType:'text/html'});
    });
    context.on('page',page=>page.on('pageerror',e=>errors.push(e.message)));
    const page=await context.newPage();page.setDefaultTimeout(10000);
    const home=base+'index.php?controller=prospecto&action=index';
    const layer=page.locator('[data-prospecto-ficha-capa]'),panel=page.locator('[data-prospecto-ficha-panel]');
    const open=async index=>{
        await page.locator('[data-prospecto-ficha]').nth(index||0).click();
        await page.locator('[data-prospecto-ficha-contenido]').waitFor();
        await page.waitForFunction(()=>{
            const panel=document.querySelector('[data-prospecto-ficha-panel]');
            return Math.abs(panel.getBoundingClientRect().right-innerWidth)<1;
        });
        await page.locator('[data-prospecto-ficha-contenido]').evaluate(el=>Promise.all(el.getAnimations().map(a=>a.finished.catch(()=>{}))));
    };
    const close=async()=>{
        await page.locator('.prospecto-ficha-cerrar').click();
        await layer.waitFor({state:'hidden'});
    };
    await page.goto(home);
    await page.evaluate(()=>{
        document.body.style.overflow='scroll';document.body.style.paddingRight='7px';document.body.style.minHeight='1600px';
        window.movimientos=[];
        document.querySelector('[data-prospecto-ficha-panel]').addEventListener('transitionrun',e=>window.movimientos.push(e.propertyName));
    });
    const mainBefore=await page.locator('main').boundingBox();
    const first=page.locator('[data-prospecto-ficha]').first();
    await open();
    check(page.url()===home,'Abrir conserva la ruta y filtros del listado');
    check(await panel.getAttribute('role')==='dialog'&&await panel.getAttribute('aria-modal')==='true','Diálogo accesible');
    check(await panel.evaluate(el=>getComputedStyle(el).transitionDuration)==='0.3s','Duración de despliegue igual al offcanvas');
    check(await page.evaluate(()=>window.movimientos.includes('transform')),'Hay una transición real de desplazamiento');
    check(Math.abs((await page.locator('main').boundingBox()).width-mainBefore.width)<1,'Abrir no desplaza ni estrecha el fondo');
    const width=(await panel.boundingBox()).width;
    check(Math.abs(width-(await page.locator('#referenciaSeguimiento').boundingBox()).width)<1&&Math.abs(width-460)<1,'Ancho igual al Panel de trabajo real');
    check(await panel.locator('.central-preference').first().innerText()==='Lun, Mar, Vie · 07:00–22:00 (hora local)','Disponibilidad original intacta');
    check(await panel.innerText().then(t=>t.includes('2026-11-02 09:00:00')),'Fecha de México intacta');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Sin desbordamiento horizontal');
    await page.screenshot({path:path.join(out,'ficha-escritorio.png')});
    await panel.getByRole('link',{name:'Consultar datos originales'}).click();
    await page.waitForFunction(()=>document.querySelector('[data-prospecto-ficha-cuerpo]').scrollTop>0);
    check(await page.locator('[data-prospecto-ficha-capa]').count()===1,'La navegación interna reutiliza el panel');
    await page.keyboard.press('Escape');
    check(!(await layer.isHidden()),'El cierre conserva el panel durante su animación');
    await layer.waitFor({state:'hidden'});
    check(await first.evaluate(el=>document.activeElement===el),'Escape devuelve el foco al origen');
    check(await page.evaluate(()=>document.body.style.overflow==='scroll'&&document.body.style.paddingRight==='7px'),'Restauración exacta del scroll y padding anteriores');
    await open(1);
    await page.locator('.prospecto-ficha-fondo').click({position:{x:20,y:120}});
    await layer.waitFor({state:'hidden'});
    check(await page.locator('[data-prospecto-ficha]').nth(1).evaluate(el=>document.activeElement===el),'Clic exterior cierra y restaura foco');
    // Una respuesta lenta no debe reabrir una ficha cerrada ni sustituir otra.
    slow=650;
    await first.click();
    await page.locator('.prospecto-ficha-cerrar').click();
    await layer.waitFor({state:'hidden'});
    slow=0;
    await open(1);
    await page.waitForTimeout(700);
    check(await panel.locator('.prospecto-ficha-identidad h2').innerText()==='Persona Ficticia 2','Respuesta vieja descartada');
    // Un cierre y reapertura antes del fin de transición no hereda temporizadores.
    await page.evaluate(()=>{
        document.querySelector('.prospecto-ficha-cerrar').click();
        document.querySelector('[data-prospecto-ficha]').click();
    });
    await page.waitForTimeout(450);
    check(!(await layer.isHidden())&&await panel.innerText().then(t=>t.includes('Persona Ficticia 1')),'Reapertura rápida permanece visible con la ficha correcta');
    await page.locator('.prospecto-ficha-cerrar').focus();await page.keyboard.press('Shift+Tab');
    check(await panel.evaluate(el=>el.contains(document.activeElement)),'El foco de teclado permanece en el panel');
    await close();
    fail=true;await first.click();
    await page.getByText('No fue posible consultar la ficha.',{exact:false}).waitFor();
    check(await page.locator('.prospecto-ficha-mensaje').isVisible(),'Error de lectura recuperable');
    await close();fail=false;
    for(const width of [1920,1024,768,767,600,390,320]){
        await page.setViewportSize({width,height:844});await open();
        const actual=(await panel.boundingBox()).width,reference=(await page.locator('#referenciaSeguimiento').boundingBox()).width;
        check(Math.abs(actual-reference)<1,'Medida de Seguimiento a '+width+' px: '+actual+' / '+reference);
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Sin overflow a '+width+' px');
        if(width===390)await page.screenshot({path:path.join(out,'ficha-movil.png')});
        await close();
    }
    // La tabla nueva aparece con transición sin modificar filtros ni peticiones.
    await page.setViewportSize({width:1440,height:1000});
    await page.selectOption('#centralLimite','50');
    await page.waitForURL(/limite=50/);
    check(await page.locator('[data-central-results] .central-table-wrap').evaluate(el=>getComputedStyle(el).animationName)==='prospectos-contenido-entrada','Filtros automáticos con entrada del listado');
    await page.locator('.central-tabs a').filter({hasText:'Meta Ads'}).click();
    check(await page.locator('.prospectos-central').first().evaluate(el=>getComputedStyle(el).animationName)==='prospectos-contenido-entrada','Cambio de sección con entrada suave');
    await page.emulateMedia({reducedMotion:'reduce'});
    await open();
    check(await panel.evaluate(el=>getComputedStyle(el).transitionDuration)==='0s','Movimiento reducido sin despliegue animado');
    check(await page.locator('[data-prospecto-ficha-contenido]').evaluate(el=>getComputedStyle(el).animationName)==='none','Movimiento reducido sin animaciones de contenido');
    await page.locator('.prospecto-ficha-cerrar').click();
    check(await layer.isHidden(),'Movimiento reducido cierra sin espera artificial');
    check(requests.every(url=>new URL(url).searchParams.get('panel')==='1'),'Consulta reutiliza la ruta autorizada de la ficha');
    check(errors.length===0,'Sin errores JavaScript: '+errors.join('; '));
    await context.close();
    console.log('PASS '+checks+' comprobaciones visuales, movimiento, cierre, carreras, responsive y datos intactos. Sin SQL ni PBX.');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();});
