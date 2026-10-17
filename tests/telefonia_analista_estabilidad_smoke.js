/* Pruebas de invariantes críticas; ejecutar: node tests/telefonia_analista_estabilidad_smoke.js */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const host = read('public/javascript/telefonia_host.js');
const shell = read('public/javascript/telefonia_shell.js');
const journal = read('public/javascript/telefonia_analista_historial_pasivo.js');
const form = read('public/javascript/seguimiento_entrantes_analista.js');
const view = read('app/views/seguimiento_vinculacion/llamadas_entrantes.php');
const service = read('app/services/TelefoniaEntrantesAnalistaService.php');

assert.match(host, /previouslyConnected\s*=\s*samePbxCall\s*&&\s*state\.active\s*&&\s*state\.phase\s*===\s*'in-progress'/);
assert.match(host, /answered\s*=\s*pbxAnswered\s*\|\|\s*sdkAnswered\s*\|\|\s*previouslyConnected/);
assert.match(shell, /crm\.src\s*=\s*url\.toString\(\)/);
const action = shell.match(/analystResultButton\?\.addEventListener\('click',[\s\S]*?\n    \}\);/);
assert.ok(action, 'Existe botón de registro del analista');
assert.doesNotMatch(action[0], /dismissAnalystResult\(\)/, 'Navegar no descarta el resultado pendiente');
assert.match(journal, /state\.phase === 'in-progress' \|\| state\.status === 'in-progress'/);
assert.match(journal, /active && pbxIncoming/, 'La identidad PBX no finaliza la sesión SDK');
assert.match(service, /\$pbx\['sdk_session_id'\]/, 'Conservar identificador SDK conciliado');
assert.match(view, /data-pbx-call-id=/, 'Publicar referencia PBX para recuperar el formulario');
assert.match(form, /el\.dataset\.pbxCallId === focusId/, 'Resolver formulario por ambos IDs');
console.log('OK: 9 invariantes de telefonía del analista');
