// Presentador real, reloj y red controlados. No utiliza BD, cuentas ni red del CRM.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const source = fs.readFileSync(path.join(__dirname, '../public/javascript/recordatorios.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../public/css/recordatorios.css'), 'utf8');
const minute = 60000;
const pending = { id: 9, accion: 'Volver a llamar', nombre_entidad: 'Institución de prueba',
    fecha: '2026-11-20 13:00:00', estado: 'proxima', etiqueta: 'Próxima', tipo_anticipo: '3H' };
async function refresh(page) {
    const before = await page.evaluate(() => IMPE_REMINDER_STATUS().loadedCount);
    await page.evaluate(() => dispatchEvent(new Event('impe:phone-notices-refresh')));
    await page.waitForFunction(n => IMPE_REMINDER_STATUS().loadedCount > n, before);
}
(async () => {
    const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage({ timezoneId: 'America/Mexico_City' });
        const errors = [], posts = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.clock.install({ time: new Date('2026-11-20T16:00:00Z') });
        await page.clock.pauseAt(new Date('2026-11-20T16:00:01Z'));
        let items = [];
        await page.route('http://anticipos.test/**', route => {
            if (route.request().method() !== 'GET') { posts.push(route.request().url()); return route.abort(); }
            if (new URL(route.request().url()).pathname === '/pendientes') {
                // Simula un aviso ya marcado como enviado en BD: nunca devuelve avisos.
                return route.fulfill({ json: { ok: true, total: items.length, recordatorios: items, avisos: [] } });
            }
            return route.fulfill({ contentType: 'text/html', body: `<!doctype html><style>${css}</style>
                <h1>Agenda del Analista</h1>
                <div data-reminder-root data-reminder-endpoint="/pendientes">
                    <span data-reminder-badge></span><div data-reminder-content></div>
                </div><script>
                window.IMPE_CURRENT_USER_ID=5; window.audioStarts=0;
                window.AudioContext=class {
                    constructor(){this.state='running';this.currentTime=0;this.destination={};}
                    createOscillator(){return {frequency:{},connect(){},disconnect(){},start(){window.audioStarts++},stop(){}};}
                    createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){}},connect(){},disconnect(){}};}
                };</script><script>${source}</script>` });
        });
        await page.goto('http://anticipos.test/');
        await page.waitForFunction(() => IMPE_REMINDER_STATUS().loadedCount > 0);
        await page.locator('h1').click();
        items = [{ ...pending }];
        await refresh(page);
        await page.locator('.reminder-toast').waitFor({ state: 'visible' });
        assert.equal(await page.evaluate(() => audioStarts), 3);
        assert.equal(await page.locator('[data-reminder-badge]').textContent(), '1');
        assert.equal(await page.locator('.topbar-reminder-item').count(), 1);
        const start = await page.evaluate(() => IMPE_REMINDER_STATUS().startedAt);
        assert.ok(await page.evaluate(s => Date.now() - s < 8 * 60000, start), 'Se muestra antes del turno de ocho minutos');
        await page.clock.runFor(7000);
        await refresh(page);
        assert.equal(await page.locator('.reminder-toast').count(), 0, 'El polling no repite el mismo anticipo');
        for (const [tipo, advance, expected] of [['1H', 120 * minute, 6], ['10M', 50 * minute, 9]]) {
            items = [{ ...pending, tipo_anticipo: tipo }];
            await page.clock.fastForward(advance);
            await refresh(page);
            await page.locator('.reminder-toast').waitFor({ state: 'visible' });
            assert.equal(await page.evaluate(() => audioStarts), expected, tipo + ': toast con sonido antes de vencer');
            assert.ok(await page.evaluate(() => Date.now() < new Date('2026-11-20T13:00:00').getTime()));
            await page.clock.runFor(7000);
        }
        await page.reload();
        await page.waitForFunction(() => IMPE_REMINDER_STATUS().loadedCount > 0);
        assert.equal(await page.locator('.reminder-toast').count(), 0, 'Navegar no duplica el intervalo ya mostrado');
        await page.locator('h1').click();
        // Otro pendiente llega mientras hay toast: se presenta al terminar los siete segundos.
        items = [{ ...pending, id: 10, tipo_anticipo: '10M' }, { ...pending, id: 11, tipo_anticipo: '10M' }];
        await refresh(page);
        assert.equal(await page.evaluate(() => audioStarts), 3);
        await page.clock.runFor(7000);
        assert.equal(await page.evaluate(() => audioStarts), 6);
        assert.equal(await page.locator('.reminder-toast').count(), 1, 'Sin superponer toasts');
        items = [{ ...pending, estado: 'vencida', tipo_anticipo: 'VENCIDA' }];
        await page.clock.fastForward(11 * minute);
        await refresh(page);
        assert.equal(await page.evaluate(() => audioStarts), 6, 'Los vencidos no suenan');
        assert.equal(await page.locator('.topbar-reminder-item').count(), 1, 'El vencido conserva su campana');
        assert.deepEqual(errors, []);
        assert.deepEqual(posts, []);
        console.log('OK navegador: 3H/1H/10M con toast+audio+campana, recuperación sin avisos, deduplicación al navegar, cola de siete segundos y vencidas silenciosas.');
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
