/* Prueba de eventos reales del observador DOM sin navegador ni Zadarma.
 * node tests/telefonia_sdk_observador_transferencia.test.js
 */
'use strict';
const assert = require('node:assert/strict');
const observer = require('../public/javascript/telefonia_sdk_entrantes_observador.js');
const callbacks = [];
let ringing = true;
let hangup = false;
let clock = 1_000;
let intervalFn;
const fakeWindow = {
  MutationObserver: class { observe() {} disconnect() {} },
  setInterval(fn) { intervalFn = fn; return 1; },
  clearInterval() {}
};
const doc = {
  body: {},
  defaultView: fakeWindow,
  querySelector(selector) {
    if (selector === '#zdrm-webphone-phonenumber-input.incoming') {
      return ringing ? {value: '+527777885173'} : null;
    }
    if (selector === '.zdrm-webphone-call-ico.zdrm-ringing') {
      return ringing ? {} : null;
    }
    if (selector === '.zdrm-webphone-call-btn-up') {
      return hangup ? {} : null;
    }
    return null;
  }
};
const originalNow = Date.now;
Date.now = () => clock;
try {
  const hooks = {
    onRinging: () => callbacks.push('ringing'),
    onConnected: () => callbacks.push('connected'),
    onStopped: () => callbacks.push('stopped'),
    onIdle: () => callbacks.push('idle')
  };
  const session = observer.create(doc, hooks);
  assert.deepEqual(callbacks, ['ringing']);
  assert.equal(session.markAnswerRequested(), true);

  // Al contestar se retira la marca .incoming, pero aparece Colgar.
  ringing = false;
  hangup = true;
  intervalFn();
  intervalFn();
  assert.deepEqual(callbacks, ['ringing', 'connected']);
  assert.equal(session.isConnected(), true);

  // El fin real del audio retira Colgar; no antes.
  hangup = false;
  for (let i=0; i<7; i++) intervalFn();
  assert.equal(callbacks.includes('idle'), false);
  intervalFn();
  assert.deepEqual(callbacks, ['ringing', 'connected', 'idle']);
  assert.equal(session.isConnected(), false);

  // No repetir cierre por sondeos adicionales.
  for (let i=0; i<10; i++) intervalFn();
  assert.equal(callbacks.filter(x => x==='idle').length, 1);
  session.stop();
  console.log('OK: timbrado -> audio -> cierre WebRTC observado una vez');
} finally {
  Date.now = originalNow;
}
