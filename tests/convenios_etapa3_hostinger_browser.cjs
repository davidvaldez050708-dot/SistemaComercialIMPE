const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), zlib = require('node:zlib');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'tools/convenios_detalle_etapa3_release.json'), 'utf8'));
const source = manifest.files.find(r => r.path === 'public/javascript/crear_convocatoria.js').variants[0];
const extension = manifest.files.find(r => r.path === 'public/javascript/crear_convocatoria_v2_extension.js');
const unpack = value => zlib.gunzipSync(Buffer.from(value, 'base64')).toString('utf8');
const bootstrap = process.env.BOOTSTRAP_MODULE_PATH || path.join(root, '../browser-deps/node_modules/bootstrap');
const html = `<!doctype html><html><body>
<main data-crear-convocatoria-module data-convocatoria-base-url="http://impe.test/index.php?controller=convocatoria&action=index">
<input data-convenio-search><select data-convenio-origin-filter><option value="">Todos</option><option value="manual">Manual</option></select>
<input data-convenio-state-filter><button data-convenio-clear-filters>Limpiar</button><span data-convenio-results></span><div data-convenio-empty-filter class="d-none">Sin resultados</div>
<article data-convenio-notification data-convenio-state="22" data-convenio-search-text="Aliado de prueba"><span data-row-text>Aliado de prueba</span><button data-convenio-open><span>Ver detalle</span></button></article>
</main>
<div class="modal" id="modalConvenioCrearConvocatoria" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h2 data-convenio-modal-title></h2></div><div class="modal-body">
<p data-convenio-origin-label></p><p data-convenio-origin-description></p><div data-convenio-modal-programas></div><p data-convenio-modal-notes></p>
<div class="crear-convocatoria-modal-help"><span></span></div></div><div class="modal-footer"><a data-convenio-create-link>Crear convocatoria</a></div>
</div></div></div></body></html>`;

(async () => {
    const browser = await chromium.launch({headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? {channel: process.env.PLAYWRIGHT_CHANNEL} : {})});
    let checks = 0;
    const check = (value, label) => {assert.ok(value, label); console.log('OK ' + label); checks++;};
    try {
        for (const withExtension of [false, true]) for (const kind of ['institucional', 'manual', 'internacional']) {
            const outcomes = [];
            for (const after of [false, true]) {
                const page = await browser.newPage();
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.route('http://impe.test/**', route => route.fulfill({contentType: 'text/html', body: html}));
                await page.goto('http://impe.test/');
                await page.addStyleTag({path: path.join(bootstrap, 'dist/css/bootstrap.min.css')});
                await page.evaluate(kind => {
                    const row = document.querySelector('[data-convenio-notification]');
                    row.dataset.convenioOrigin = kind === 'manual' ? 'manual' : 'institucional';
                    row.dataset.convenio = JSON.stringify({institucion: 'Aliado de prueba', estado: 'Querétaro', estado_id: 22, origen_tipo: row.dataset.convenioOrigin,
                        origen_id: 12, seguimiento_id: kind === 'manual' ? 0 : 12, aliado_internacional_id: kind === 'internacional' ? 75 : 0,
                        notas: 'Nota completa\nSegunda línea <texto>', registro_programas_items: [{codigo: 'bachillerato-286', nombre: 'Bachillerato 286 · Campus A'}, {codigo: 'titulacion', nombre: 'Titulación'}]});
                    window.openedCount = 0;
                    document.addEventListener('impe:convenio-abierto', () => window.openedCount++);
                }, kind);
                await page.addScriptTag({path: path.join(bootstrap, 'dist/js/bootstrap.bundle.min.js')});
                await page.addScriptTag({content: unpack(source[after ? 'target' : 'base'])});
                if (withExtension) await page.addScriptTag({content: unpack(extension[after ? 'target' : 'base'])});
                await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
                const modal = page.locator('#modalConvenioCrearConvocatoria');
                const close = async () => {await page.evaluate(() => bootstrap.Modal.getInstance(document.getElementById('modalConvenioCrearConvocatoria')).hide()); await modal.waitFor({state: 'hidden'});};
                if (after) {
                    await page.locator('[data-row-text]').click();
                    check(await page.evaluate(() => window.openedCount) === 0 && !await modal.isVisible(), 'fila informativa no abre modal ' + kind + '/' + withExtension);
                }
                await page.locator('[data-convenio-open] span').click();
                await modal.waitFor({state: 'visible'});
                const link = modal.locator('[data-convenio-create-link]');
                outcomes.push({href: await link.getAttribute('href'), hidden: await link.evaluate(el => el.hidden)});
                check(await page.evaluate(() => window.openedCount) === 1, 'una apertura por clic ' + kind + '/' + withExtension + '/' + after);
                if (after && withExtension) {
                    check(await modal.locator('.aliados-source-chip').count() === 2, 'programas separados ' + kind);
                    check((await modal.locator('[data-convenio-modal-notes]').textContent()).includes('\nSegunda línea <texto>'), 'notas íntegras ' + kind);
                    check(await modal.locator('texto').count() === 0, 'notas sin interpretar HTML ' + kind);
                }
                await close();
                if (after) for (const key of ['Enter', 'Space']) {
                    const before = await page.evaluate(() => window.openedCount);
                    await page.locator('[data-convenio-open]').press(key); await modal.waitFor({state: 'visible'});
                    check(await page.evaluate(() => window.openedCount) === before + 1, 'teclado ' + key + ' sin duplicación ' + kind + '/' + withExtension); await close();
                }
                await page.locator('[data-convenio-search]').fill('inexistente');
                check(await page.locator('[data-convenio-notification]').evaluate(el => el.classList.contains('d-none')), 'búsqueda conservada ' + kind + '/' + withExtension + '/' + after);
                check(errors.length === 0, 'sin errores JavaScript ' + kind + '/' + withExtension + '/' + after);
                await page.close();
            }
            assert.deepEqual(outcomes[1], outcomes[0]);
            check(true, 'destino y visibilidad de Crear convocatoria intactos ' + kind + '/' + withExtension);
            if (kind === 'internacional' && !withExtension) check(outcomes[1].href.includes('action=internacionales&crear_convocatoria=75'), 'ruta personalizada internacional conservada');
        }
        console.log('TOTAL ' + checks + ' comprobaciones');
    } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
