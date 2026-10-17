/* Casos adversos WebRTC: DOM transitorio y transferencia sin control Colgar. */
'use strict';
const assert = require('node:assert/strict');
const factory = require('../public/javascript/telefonia_sdk_entrantes_observador.js');
function scenario({showHangupAt = -1, hideHangupAt = -1, ticks = 60}) {
  let clock = 1000, ringing = true, hangup = false, step;
  const events = [];
  const win = {
    MutationObserver: class {observe() {} disconnect() {}},
    setInterval(fn) {step = fn; return 1;},
    clearInterval() {}
  };
  const doc = {body: {}, defaultView: win, querySelector(selector) {
    if (selector === '#zdrm-webphone-phonenumber-input.incoming') return ringing ? {value: '5550000000'} : null;
    if (selector === '.zdrm-webphone-call-ico.zdrm-ringing') return ringing ? {} : null;
    if (selector === '.zdrm-webphone-call-btn-up') return hangup ? {} : null;
    return null;
  }};
  const saved = Date.now;
  Date.now = () => clock;
  try {
    const obs = factory.create(doc, {
      onRinging: () => events.push('ringing'),
      onStopped: () => events.push('stopped'),
      onConnected: () => events.push('connected'),
      onIdle: () => events.push('idle')
    });
    assert.equal(obs.markAnswerRequested(), true);
    ringing = false;
    for (let i=0; i<ticks; i++) {
      if (i===showHangupAt) hangup = true;
      if (i===hideHangupAt) hangup = false;
      clock+=250; step();
    }
    const diagnostic = obs.diagnostic();
    obs.stop();
    return {events, diagnostic};
  } finally {Date.now = saved;}
}
const transient = scenario({showHangupAt:3,hideHangupAt:5,ticks:8});
assert.equal(transient.events.includes('connected'), false, 'Un botón transitorio no demuestra conversación');
const stable = scenario({showHangupAt:3,hideHangupAt:12,ticks:25});
assert.deepEqual(stable.events.filter(v=>v==='connected'), ['connected']);
assert.deepEqual(stable.events.filter(v=>v==='idle'), ['idle']);
const missing = scenario({ticks:35});
assert.equal(missing.events.includes('connected'),false, 'Sin una señal de audio, nunca inventar conexión');
assert.equal(missing.diagnostic.hangupDom,false);
console.log('OK: DOM transitorio, audio observado y DOM ausente sin confirmación falsa');
