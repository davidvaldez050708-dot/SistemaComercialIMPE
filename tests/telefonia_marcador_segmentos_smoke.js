const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const source = name => fs.readFileSync(path.join(root, name), 'utf8');
const dialer = source('public/javascript/telefonia_teclado.js');
const marketing = source('public/javascript/telefonia_recepcion_marketing.js');
const analyst = source('public/javascript/seguimiento_entrantes_analista.js');
const common = source('public/javascript/telefonia_segmentos_player.js');
const footer = source('app/views/layout/dashboard_footer.php');

assert(dialer.includes("if (next.active &&"), 'Un evento inactivo no debe cerrar el marcador');
assert(dialer.includes("['incoming-ringing', 'in-progress'].includes(String(next.phase || ''))"), 'Cerrar sólo durante una entrante real');
assert(dialer.includes("current?.active && current.direction === 'incoming' &&"), 'No confundir estado entrante finalizado con llamada activa');
assert(marketing.includes('IMPE_TELEPHONY_SEGMENTS?.create'), 'Recepción usa el reproductor uniforme');
assert(analyst.includes('IMPE_TELEPHONY_SEGMENTS?.create'), 'Analista usa el reproductor uniforme');
assert(!marketing.includes('audio.controls = true'), 'Marketing no usa reproductor nativo');
assert(!analyst.includes('audio.controls = true'), 'Analista no usa reproductor nativo');
assert(common.includes('linkage-call-custom-player'), 'Reutilizar el diseño del CRM');
assert(common.includes('linkage-call-player-progress'), 'Conservar progreso accesible');
assert(common.includes("audio.addEventListener('ended'"), 'Avanzar al siguiente segmento');
assert(common.includes("if (a!==audio") === false, 'No heredar controles antiguos');
assert(footer.indexOf("'telefonia_segmentos_player.js'") < footer.indexOf("'seguimiento_entrantes_analista.js'"),
    'Reproductor cargado antes del módulo de Analista');
assert(footer.includes("'telefonia_segmentos_player.js'"), 'La biblioteca debe estar cargada');
console.log('Marcador y audio transferido: OK (13 comprobaciones estáticas)');
