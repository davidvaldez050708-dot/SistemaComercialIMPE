'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

const source = fs.readFileSync(
    path.join(__dirname, '../public/javascript/telefonia_analista_historial_pasivo.js'),
    'utf8'
);
const listeners = {};
const controls = new Map();
const requests = [];
const storage = new Map();
let polling = null;
let fallback = 0;
let sdkCalls = 0;

const fake = selector => {
    if (!controls.has(selector)) {
        const events = {};
        controls.set(selector, {
            hidden: false,
            textContent: '',
            events,
            addEventListener(type, callback) {events[type] = callback;}
        });
    }
    return controls.get(selector);
};
const element = {
    className: '',
    attributes: {},
    style: {},
    innerHTML: '',
    setAttribute(key, value) {this.attributes[key] = value;},
    querySelector: fake
};
const root = {
    IMPE_PHONE_SHELL_CONFIG: {
        userId: 7, analystIncomingResults: true,
        incomingResultsUrl:
            'https://fundacionrededumex.org/index.php?controller=seguimientoVinculacion&action=llamadasEntrantes'
    },
    IMPE_ANALYST_WEBRTC_JOURNAL: {
        enabled: true, csrf: 'testcsrf',
        endpoint: 'https://fundacionrededumex.org/index.php?controller=seguimientoVinculacion&action=eventoEntranteWebrtc'
    },
    document: {
        body: {appendChild(node) {assert.equal(node, element);}},
        createElement(name) {assert.equal(name, 'section'); return element;},
        getElementById(id) {
            assert.equal(id, 'impeCrmFrame');
            return crm;
        }
    },
    fetch: async (url, options) => {
        assert.equal(options.method, 'POST');
        assert.equal(options.credentials, 'same-origin');
        assert.match(url, /eventoEntranteWebrtc/);
        const form = new URLSearchParams(options.body);
        assert.equal(form.get('csrf_token'), 'testcsrf');
        requests.push({
            event: form.get('evento'), id: form.get('session_id'),
            numero: form.get('numero')
        });
        return {ok:true,json:async()=>({ok:true})};
    },
    console: {warn() {throw new Error('No deberían fallar los POST simulados');}},
    addEventListener(type, callback) {listeners[type] = callback;},
    setInterval(callback, ms) {
        assert.equal(ms, 500);
        polling = callback;
    },
    localStorage: {
        getItem(key) {return storage.get(key) || null;}
    },
    location: {
        href:'https://fundacionrededumex.org/index.php',
        origin:'https://fundacionrededumex.org'
    }
};
const crm = {src: ''};
vm.runInNewContext(source, {window: root, URL, URLSearchParams},
    {filename:'telefonia_analista_historial_pasivo.js'});
assert.equal(typeof polling, 'function');
assert.equal(typeof root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.isObserving,'function');
const id='sdk-l5lm1oo-b1c2d3e';
let tick=Date.now();
function publish(change) {
    const state=Object.assign({
        userId:7, updatedAt:++tick, callToken:id,
        direction:'incoming', context:{type:'SDK_INCOMING',callerName:'Recepcion-Tania'},
        destination:'100', active:true, phase:'incoming-ringing'
    },change);
    state.updatedAt=Date.now();
    fallback += 1;
    state.heartbeatTest=fallback;
    storage.set('impe:telephony:state:7',JSON.stringify(state));
    polling();
}
const drain=async()=>{for(let n=0;n<12;n++) await new Promise(r=>setImmediate(r));};
publish({direction:'outgoing',context:{type:'DIALER'}});
assert.equal(root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.isObserving(),false);
assert.match(element.style.cssText,/display:none/);

publish({active:true,phase:'incoming-ringing'});
assert.equal(root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.isObserving(),true);
publish({phase:'in-progress',active:true});
publish({phase:'ready',active:false,direction:''});
assert.equal(root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.hasFinalized(),true);
assert.equal(element.style.display,'block');
assert.match(fake('[data-webrtc-state]').textContent,/atendida/);
drain().then(async()=>{
    assert.equal(root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.saved(),true);
    assert.equal(fake('[data-webrtc-result]').hidden,false);
    assert.equal(fake('[data-webrtc-retry]').hidden,true);
    assert.deepEqual(requests.map(x=>x.event),
        ['ringing','answered','ringing','answered','ended']);
    fake('[data-webrtc-result]').events.click();
    assert.match(crm.src, /sdk_session_id=sdk-l5lm1oo-b1c2d3e/);
    assert.equal(element.style.display,'none');

    const before=requests.length;
    publish({active:true,phase:'incoming-ringing',callToken:'sdk-l5lm1oo-e7f8g9h'});
    publish({active:false,phase:'ready',callToken:'',direction:''});
    await drain();
    assert.equal(requests.length-before,3);
    assert.equal(fake('[data-webrtc-result]').hidden,true);
    assert.match(fake('[data-webrtc-state]').textContent,/no contestada/);
    fake('[data-webrtc-close]').events.click();
    assert.equal(element.style.display,'none');
    assert.equal(sdkCalls,0);
    console.log('Historial pasivo Analista: OK (solo estados, cierre, registro y PBX aislada)');
}).catch(e=>{console.error(e);process.exitCode=1;});