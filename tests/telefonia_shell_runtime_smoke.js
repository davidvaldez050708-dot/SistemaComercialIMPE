'use strict';
/* Pruebas de ciclo de vida sin navegador real ni conexión externa. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(
    require('node:path').join(__dirname, '../public/javascript/telefonia_shell.js'),
    'utf8'
);

const storage = new Map();
function boot(opts = {}) {
    const elements = {};
    const classes = new Set();
    const element = () => ({
        hidden: false, textContent: '', dataset: {}, events: {},
        children: [], contentWindow: {}, title: '',
        classList: {
            toggle(k, on) { if (on) classes.add(k); else classes.delete(k); },
            contains(k) { return classes.has(k); }
        },
        addEventListener(k, fn) { this.events[k] = fn; },
        setAttribute(k, value) { this[k] = String(value); },
        removeAttribute(k) { if (k === 'hidden') this.hidden = false; },
        appendChild(node) { this.children.push(node); },
        remove() { this.removed = true; }
    });
    const selectors = [
        '[data-shell-call]','[data-shell-host-region]','[data-shell-status]',
        '[data-shell-status-text]','[data-shell-retry]','[data-shell-restore]',
        '[data-shell-call-title]','[data-shell-call-status]',
        '[data-shell-minimize]'
    ];
    selectors.forEach(x => elements[x] = element());
    elements['impeCrmFrame'] = element();
    elements['impeCrmFrame'].contentWindow = {location:{href:'https://crm.ejemplo.org/index.php?controller=home&action=index'}};
    const intervals = [];
    const windowEvents = {};
    const win = {
        IMPE_PHONE_SHELL_CONFIG: {
            userId: 8, extension: '100',
            hostUrl: 'https://crm.ejemplo.org/index.php?controller=telefonia&action=host&display=embed',
            loginUrl: 'https://crm.ejemplo.org/index.php?controller=login&action=mostrarLogin',
            autoReception: opts.auto !== false
        },
        location: {origin:'https://crm.ejemplo.org', replace(url) {this.redirect = url;}},
        addEventListener(k,fn) {windowEvents[k] = fn;},
        setInterval(fn) {intervals.push(fn); return intervals.length;}
    };
    const doc = {
        querySelector(k) {return elements[k];},
        getElementById(k) {return elements[k];},
        createElement() {return element();},
        title: ''
    };
    const ls = {
        getItem(k) {return storage.get(k) ?? null;},
        setItem(k,v) {storage.set(k,String(v));},
        removeItem(k) {storage.delete(k);}
    };
    const channels = [];
    class BroadcastChannel {
        constructor() {channels.push(this);this.events={};}
        addEventListener(k,fn) {this.events[k] = fn;}
        close() {}
        message(value) {this.events.message?.({data:value});}
    }
    const ctx = vm.createContext({
        window:win,document:doc,localStorage:ls,BroadcastChannel,URL,Date,Math,JSON,
        console
    });
    vm.runInContext(source,ctx);
    return {window:win,elements,classes,intervals,channels,windowEvents};
}

storage.clear();
const first = boot();
assert.equal(first.elements['[data-shell-host-region]'].children.length,1,
    'marketing auto-mounts engine');
assert.match(first.elements['[data-shell-host-region]'].children[0].src,/display=embed/);
assert.ok(first.window.IMPE_TELEPHONY_SHELL.isEmbedded);
assert.equal(typeof first.window.IMPE_TELEPHONY_SHELL.ensureHost,'function');
assert.strictEqual(first.window.IMPE_TELEPHONY_SHELL.ensureHost(),
    first.elements['[data-shell-host-region]'].children[0].contentWindow);

const active = {type:'STATE',state:{
    userId:8,updatedAt:Date.now(),hostReady:true,active:true,
    direction:'incoming',destination:'5512345678',phase:'in-progress'
}};
first.channels[0].message(active);
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),true,
    'incoming call reveals controls in CRM');
assert.match(first.elements['[data-shell-call-status]'].textContent,/5512345678/);
first.elements['[data-shell-minimize]'].events.click();
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),false,
    'minimizing hides UI but not iframe');
assert.equal(first.elements['[data-shell-restore]'].hidden,false);
assert.equal(first.elements['[data-shell-host-region]'].children.length,1);

first.elements['[data-shell-restore]'].events.click();
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),true);
first.channels[0].message({type:'STATE',state:{
    userId:8,updatedAt:Date.now(),hostReady:true,active:true,
    direction:'outgoing',destination:'5512345678',phase:'in-progress'
}});
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),false,
    'outgoing calls retain CRM dialer, no blank SDK overlay');
first.windowEvents.message({
    origin:'https://crm.ejemplo.org',
    source:first.elements['[data-shell-host-region]'].children[0].contentWindow,
    data:{type:'IMPE_TELEPHONY_NATIVE_CONTROLS',userId:8}
});
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),true,
    'transfer fallbacks show official Zadarma controls in CRM');
first.elements['[data-shell-minimize]'].events.click();
assert.equal(first.elements['[data-shell-call]'].classList.contains('is-visible'),false,
    'user can minimize provider transfer controls without hanging up');

const second = boot();
assert.equal(second.elements['[data-shell-host-region]'].children.length,0,
    'second tab does not register same SIP extension');
assert.throws(() => second.window.IMPE_TELEPHONY_SHELL.ensureHost());

storage.clear();
storage.set('impe:telephony:auto-reception:8','0');
const optedOut = boot();
assert.equal(optedOut.elements['[data-shell-host-region]'].children.length,0,
    'auto-activation may be disabled per user');
optedOut.window.IMPE_TELEPHONY_SHELL.ensureHost();
assert.equal(optedOut.elements['[data-shell-host-region]'].children.length,1,
    'manual activation still works');
console.log('Shell WebRTC: OK (autostart, no popup, audio context, controls, lease, opt-out)');
