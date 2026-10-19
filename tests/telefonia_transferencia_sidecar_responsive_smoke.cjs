/* Regresión de geometría del sidecar: sin DOM real ni llamadas a PBX. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const js = fs.readFileSync('public/javascript/telefonia_enrutamiento.js', 'utf8');
const css = fs.readFileSync('public/css/telefonia_enrutamiento.css', 'utf8');
const shell = fs.readFileSync('app/views/telefonia/shell.php', 'utf8');

const prefix = '    const positionSidecar = pane => {';
const suffix = '    const repositionSidecars = () => {';
assert.equal(js.split(prefix).length, 2, 'Una sola función de posicionamiento');
assert.equal(js.split(suffix).length, 2, 'Una sola función de reposicionamiento');
const body = js.split(prefix)[1].split(suffix)[0];
const geometry = prefix + body + '\nthis.positionSidecar = positionSidecar;';

const scenarios = [
  { label: 'teléfono pequeño', inner: [320, 480], visible: [320, 480, 0, 0] },
  { label: 'teléfono habitual', inner: [390, 844], visible: [390, 844, 0, 0] },
  { label: 'aparece teclado', inner: [390, 844], visible: [390, 340, 0, 270] },
  { label: 'zoom y teclado', inner: [390, 844], visible: [288, 270, 27, 215] },
  { label: 'paisaje estrecho', inner: [667, 320], visible: [667, 320, 0, 0] },
  { label: 'tableta', inner: [768, 1024], visible: [768, 1024, 0, 0] },
  { label: 'escritorio', inner: [1440, 900], visible: [1440, 900, 0, 0] }
];

for (const {label, inner: [innerWidth, innerHeight], visible: [w, h, offsetLeft, offsetTop]} of scenarios) {
    const win = {innerWidth, innerHeight,
        visualViewport: {width:w, height:h, offsetLeft, offsetTop}};
    const doc = {getBoundingClientRect:() => ({left:innerWidth-465,right:innerWidth-45,top:120})};
    const env = {window:win, phonePanel:doc};
    vm.runInNewContext(geometry, env);
    for (const target of ['transferencia', 'captura']) {
        const pane = {hidden:false, style:{}};
        env.positionSidecar(pane);
        const x=Number.parseFloat(pane.style.left), y=Number.parseFloat(pane.style.top);
        const width=Number.parseFloat(pane.style.width), height=Number.parseFloat(pane.style.maxHeight);
        assert(x >= offsetLeft + 7, label+': el panel sale por izquierda');
        assert(y >= offsetTop + 7, label+': la X sale por arriba');
        assert(x + width <= offsetLeft + w - 7, label+': el panel sale por derecha');
        assert(y + height <= offsetTop + h - 7, label+': el panel sale por abajo');
        assert(width > 0 && height > 0, label+': dimensiones útiles');
        if (label === 'escritorio') assert(x < innerWidth - 465, 'En escritorio se coloca junto al teléfono');
    }
}
assert(js.includes("window.visualViewport?.addEventListener('resize', repositionSidecars)"), 'reacciona al teclado/zoom');
assert(js.includes("window.visualViewport?.addEventListener('scroll', repositionSidecars)"), 'reacciona al desplazamiento del viewport');
assert(css.includes('overflow-x: hidden;'), 'sin desbordamiento horizontal');
assert(css.includes('-webkit-overflow-scrolling: touch;'), 'scroll táctil');
assert(css.includes('position: sticky;'), 'cabecera fija');
assert(css.includes('width: 40px;') && css.includes('height: 40px;'), 'cierre táctil');
assert(shell.includes('data-routing-close'), 'botón de cierre original intacto');
assert(shell.includes('data-routing-attended'), 'solicitud de disponibilidad intacta');
console.log('Sidecar móvil: 7 viewports, teclado, zoom, escritorio, scroll y cierre: OK');
