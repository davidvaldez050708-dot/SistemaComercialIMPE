const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const browsers = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const browserName = process.env.IMPE_SIDEBAR_BROWSER || 'chromium';
const root = path.resolve(__dirname, '..');
const deps = process.env.IMPE_BROWSER_DEPS || path.resolve(root, '../browser-deps/node_modules');
const php = process.env.PHP_BINARY || 'php';
const base = 'http://127.0.0.1:8158/';
const out = process.env.IMPE_SIDEBAR_OUTPUT || path.join(root, 'tmp/sidebar-capturas');
const env = {...process.env, APP_ENV:'testing', IMPE_BASE_URL:base, IMPE_DB_HOST:'127.0.0.1:1'};
const render = query => {
    const result = spawnSync(php, [path.join(__dirname, 'sidebar_plegable_fixture.php'), query], {env,cwd:root,encoding:'utf8'});
    assert.equal(result.status, 0, result.stderr);
    assert.ok(!/PHP (Warning|Fatal|Notice|Deprecated)|impe_mysql/.test(result.stderr + result.stdout), result.stderr);
    return result.stdout;
};
const url = (perfil='admin', modulo='resumen', usuario=101) => base + '?' + new URLSearchParams({perfil,modulo,usuario});
let browser, checks = 0;
const check = (value, message) => {assert.ok(value,message); checks++;};

(async () => {
    browser = await browsers[browserName].launch({headless:true,...(browserName === 'chromium' && process.env.PLAYWRIGHT_CHANNEL ? {channel:process.env.PLAYWRIGHT_CHANNEL} : {})});
    fs.mkdirSync(out,{recursive:true});
    const context = await browser.newContext({viewport:{width:1440,height:1000}});
    const errors=[];
    const serve = route => {
        const request=route.request(), parsed=new URL(request.url());
        assert.equal(request.method(),'GET','El plegado no envía formularios ni comandos al servidor');
        if(parsed.origin!==new URL(base).origin) return route.abort();
        const name=parsed.pathname;
        if(name==='/fixture/bootstrap.css') return route.fulfill({path:path.join(deps,'bootstrap/dist/css/bootstrap.min.css'),contentType:'text/css'});
        if(name==='/fixture/bootstrap.js') return route.fulfill({path:path.join(deps,'bootstrap/dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'});
        if(name==='/fixture/icons.css') return route.fulfill({path:path.join(deps,'bootstrap-icons/font/bootstrap-icons.min.css'),contentType:'text/css'});
        if(name==='/fixture/fonts.css') return route.fulfill({body:fs.readFileSync(path.join(deps,'@fontsource/manrope/latin.css'),'utf8').replaceAll('./files/',base+'fixture/fonts/'),contentType:'text/css'});
        if(name.startsWith('/fixture/fonts/') && path.basename(name).startsWith('manrope-')) return route.fulfill({path:path.join(deps,'@fontsource/manrope/files',path.basename(name)),contentType:'font/woff2'});
        if(name.endsWith('.woff2')) return route.fulfill({path:path.join(deps,'bootstrap-icons/font/fonts/bootstrap-icons.woff2'),contentType:'font/woff2'});
        if(name.startsWith('/public/')) {
            const file=path.join(root,decodeURIComponent(name));
            return route.fulfill({path:file,contentType:({'.js':'application/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml','.ico':'image/x-icon'})[path.extname(file)]});
        }
        if(name==='/engine') return route.fulfill({contentType:'text/html',body:'<script>window.engineIdentity=Math.random();</script>Motor aislado de prueba'});
        if(name==='/shell') return route.fulfill({contentType:'text/html',body:'<style>html,body{margin:0;height:100%}#crm{width:100%;height:100%;border:0}#engine{position:absolute;width:1px;height:1px}</style><iframe id="engine" src="/engine"></iframe><iframe id="crm" src="/?perfil=asesor&modulo=tabla&usuario=333"></iframe>'});
        return route.fulfill({body:render(parsed.search.slice(1)),contentType:'text/html'});
    };
    await context.route('**/*', serve);
    context.on('page', page => page.on('pageerror', error=>errors.push(error.message)));
    const page=await context.newPage();
    const button = target=>target.locator('[data-sidebar-toggle]');
    const main = target=>target.locator('.admin-main').boundingBox();
    const noOverflow = target=>target.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);
    const layout = async (target,closed) => {
        const box=await main(target);
        check(Math.abs(box.x-(closed?0:270))<=1,'Margen del contenido correcto');
        check(await noOverflow(target),'Sin desbordamiento horizontal del documento');
        check(await button(target).getAttribute('aria-expanded')===(closed?'false':'true'),'Estado accesible correcto');
        check(await target.locator('.offcanvas-backdrop').count()===0,'Escritorio sin backdrop móvil');
        check(await target.locator('.topbar-actions').evaluate(el=>{
            const rect=el.getBoundingClientRect();
            return getComputedStyle(el).visibility==='visible' && rect.width>0 && rect.right<=innerWidth+1;
        }),'Los accesos rápidos permanecen visibles dentro del encabezado');
    };
    for(const [i,perfil] of ['admin','director','clave','analista','asesor','marketing'].entries()) {
        await page.goto(url(perfil,i===0?'usuarios':i===1?'roles':'tabla',200+i));
        check(await button(page).isVisible(),'Control disponible para '+perfil);
        await layout(page,false);
        const links=await page.locator('.admin-sidebar-fixed a').evaluateAll(items=>items.map(a=>a.getAttribute('href')));
        await button(page).click(); await layout(page,true);
        check(await page.locator('.admin-sidebar-fixed a').evaluateAll(items=>items.map(a=>a.getAttribute('href'))).then(after=>JSON.stringify(after)===JSON.stringify(links)),'Plegar conserva enlaces y permisos');
        await button(page).click(); await layout(page,false);
    }
    await page.goto(url('analista','tabla',444));
    const nav=page.locator('.admin-sidebar-fixed .sidebar-navigation');
    await nav.evaluate(el=>{el.scrollTop=120;});
    const scroll=await nav.evaluate(el=>el.scrollTop);
    await page.locator('#notaLayout').fill('Trabajo sin perder');
    const original=await main(page);
    await page.screenshot({path:path.join(out,'escritorio-abierto.png')});
    await button(page).focus(); await page.keyboard.press('Enter'); await layout(page,true);
    check((await main(page)).width>original.width+260,'El contenido gana el espacio del menú');
    check(await page.locator('#notaLayout').inputValue()==='Trabajo sin perder','No se recarga ni se pierde el contenido en edición');
    await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    await page.screenshot({path:path.join(out,'escritorio-plegado.png')});
    await button(page).click();
    check(await nav.evaluate(el=>el.scrollTop)===scroll,'El plegado conserva el desplazamiento del menú');
    await button(page).click();
    await page.getByRole('button',{name:'Abrir diálogo de prueba'}).click();
    const modal=page.locator('#modalLayoutPrueba'); await modal.waitFor({state:'visible'});
    const modalBox=await modal.locator('.modal-dialog').boundingBox(); check(modalBox.width<=760+1,'Los diálogos conservan su ancho');
    await modal.getByRole('button',{name:'Cerrar',exact:true}).last().click();
    await page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));
    await page.goto(url('analista','resumen',444)); await layout(page,true);
    await page.reload(); await layout(page,true);
    // El móvil conserva su offcanvas y no modifica la preferencia de escritorio.
    await page.setViewportSize({width:390,height:844});
    await button(page).click();
    await page.waitForFunction(()=>document.querySelector('#mobileSidebar').classList.contains('show'));
    await page.screenshot({path:path.join(out,'movil-menu.png')});
    check(await noOverflow(page),'Menú móvil sin desbordamiento');
    await page.keyboard.press('Escape');
    await page.waitForFunction(()=>!document.querySelector('.offcanvas-backdrop') && !document.querySelector('#mobileSidebar.show, #mobileSidebar.hiding, #mobileSidebar.showing') && document.body.style.overflow!=='hidden');
    await button(page).click();
    await page.waitForFunction(()=>document.querySelector('#mobileSidebar').classList.contains('show'));
    await page.setViewportSize({width:1440,height:1000});
    await page.waitForFunction(()=>!document.querySelector('.offcanvas-backdrop') && !document.querySelector('#mobileSidebar.show, #mobileSidebar.hiding, #mobileSidebar.showing') && document.body.style.overflow!=='hidden');
    await layout(page,true);
    check(await page.evaluate(()=>document.body.style.overflow!=='hidden'),'Redimensionar no deja bloqueado el scroll');
    for(const width of [992,1024,1280,1920]) {
        await page.setViewportSize({width,height:1000}); await layout(page,true);
        await button(page).click(); await layout(page,false);
        await button(page).click(); await layout(page,true);
    }
    await page.setViewportSize({width:1440,height:1000});
    // Preferencia por usuario, sincronización entre pestañas y navegación con teclado.
    const other=await context.newPage(); await other.goto(url('analista','tabla',444)); await layout(other,true);
    await button(page).focus(); await page.keyboard.press('Space');
    await other.waitForFunction(()=>!document.documentElement.classList.contains('sidebar-desktop-collapsed')); await layout(other,false);
    await other.goto(url('asesor','tabla',445)); await layout(other,false);
    await button(page).click(); await layout(page,true); await layout(other,false);
    // Fallo de localStorage: el control sigue funcionando sin lanzar errores.
    const blocked=await browser.newContext({viewport:{width:1440,height:1000}});
    await blocked.addInitScript(()=>{Storage.prototype.getItem=function(){throw new DOMException('Bloqueado','SecurityError');};Storage.prototype.setItem=function(){throw new DOMException('Bloqueado','SecurityError');};});
    await blocked.route('**/*', serve);
    const noStorage=await blocked.newPage(); noStorage.on('pageerror',error=>errors.push(error.message));
    await noStorage.goto(url('marketing','tabla',777)); await layout(noStorage,false);
    await button(noStorage).click(); await layout(noStorage,true);
    await button(noStorage).click(); await layout(noStorage,false);
    await blocked.close();
    // La disposición vive dentro del iframe del CRM y no cambia su motor hermano.
    await other.goto(base+'shell'); const frame=other.frameLocator('#crm');
    await frame.locator('[data-sidebar-toggle]').waitFor();
    const engine=other.frames().find(f=>f.url()===base+'engine');
    const identity=await engine.evaluate(()=>window.engineIdentity);
    await frame.locator('[data-sidebar-toggle]').click();
    check(await engine.evaluate(()=>window.engineIdentity)===identity,'El motor hermano no se recarga al plegar');
    check(await frame.locator('.admin-main').evaluate(el=>el.getBoundingClientRect().x===0),'Plegado dentro del CRM embebido');
    check(errors.length===0,'Sin errores JavaScript: '+errors.join('; '));
    console.log('PASS '+checks+' comprobaciones de layout, roles, persistencia, teclado, móvil e iframe. Sin SQL ni PBX.');
    await context.close();
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();});
