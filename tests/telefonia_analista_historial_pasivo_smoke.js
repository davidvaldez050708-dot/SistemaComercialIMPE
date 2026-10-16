'use strict';
/**
 * DOM mínimo para la misma tarjeta que renderiza telefonia_shell.js.
 * El módulo pasivo no puede crear una segunda tarjeta ni llamar a Zadarma.
 */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

const script = fs.readFileSync(path.join(
    __dirname, '../public/javascript/telefonia_analista_historial_pasivo.js'
), 'utf8');
assert.ok(!script.includes("createElement('section')"),
    'No volver a crear tarjetas de resultado duplicadas');
assert.ok(!script.includes('zdrmWebPhone'),
    'El historial nunca controla el SDK SIP');
const handlers = {};
const controls = new Map();
const post = [];
const store = new Map();
const toggles = new Set();
let interval = null;
let attributeMutation = null;
let cssNode = null;
let shellContext = 0;
let createdButtons = 0;
let createdContext = 0;
let consistencyInterval = null;

const node = () => {
    const listeners = {};
    const classes = new Set();
    return {
        hidden: false,
        textContent: '',
        innerHTML: '',
        style: {},
        listeners,
        attrs: {},
        className: '',
        classList: {
            add(s) {classes.add(s);},
            remove(s) {classes.delete(s);},
            contains(s) {return classes.has(s);},
            toggle(s, force) {
                const enabled = force === undefined ? !classes.has(s) : Boolean(force);
                if (enabled) classes.add(s); else classes.delete(s);
                return enabled;
            }
        },
        setAttribute(k, v) {this.attrs[k]=v;},
        removeAttribute(k) {delete this.attrs[k];},
        addEventListener(k, cb) {listeners[k]=cb;}
    };
};
const required = [
    '[data-incoming-close-finished]', '[data-incoming-state]',
    '[data-incoming-title]', '[data-incoming-eyebrow]',
    '[data-incoming-number]', '[data-incoming-timer]',
    '[data-incoming-compact-timer]', '[data-incoming-notice]',
    '[data-incoming-minimize]', '[data-incoming-answer]',
    '[data-incoming-decline]', '[data-incoming-mute]',
    '[data-incoming-hangup]', '[data-incoming-answer-compact]',
    '[data-incoming-decline-compact]', '[data-incoming-mute-compact]',
    '[data-incoming-hangup-compact]'
];
for (const name of required) controls.set(name,node());
const actions = {
    hidden:false,
    appended:[],
    appendChild(child) {
        this.appended.push(child);
        for (const name of Object.keys(child.attrs)) {
            controls.set('[' + name + ']',child);
        }
    }
};
const compact = node();
const panel = Object.assign(node(), {
    hidden: true,
    querySelector(selector) {
        if (selector === '.persistent-phone-actions') return actions;
        if (selector === '.persistent-phone-compact-actions') return compact;
        return controls.get(selector) || null;
    }
});
const crm={src:'',addEventListener(type,fn) {
    assert.equal(type,'load');
    handlers['crm-load']=fn;
}};
const runtime={
    IMPE_PHONE_SHELL_CONFIG: {
        userId:7,analystIncomingResults:true,
        incomingResultsUrl:'https://fundacionrededumex.org/index.php?controller=seguimientoVinculacion&action=llamadasEntrantes'
    },
    IMPE_ANALYST_WEBRTC_JOURNAL:{
        enabled:true,csrf:'testcsrf',
        endpoint:'https://fundacionrededumex.org/index.php?controller=seguimientoVinculacion&action=eventoEntranteWebrtc'
    },
    document: {
        body: {},
        head: {
            appendChild(obj) {cssNode=obj;}
        },
        querySelector(selector) {
            assert.equal(selector,'[data-incoming-panel]');
            return panel;
        },
        getElementById(id) {
            assert.equal(id,'impeCrmFrame');
            return crm;
        },
        createElement(tag) {
            if (tag==='style') return node();
            // El contexto institucional se inserta dentro de la misma
            // tarjeta; no se permite construir otra sección telefónica.
            if (tag==='div') {
                createdContext++;
                return node();
            }
            assert.equal(tag,'button','NO se puede crear otra tarjeta telefónica');
            createdButtons++;
            return node();
        }
    },
    MutationObserver: class {
        constructor(cb) {attributeMutation=cb;}
        observe(target, config) {
            assert.equal(target,panel);
            assert.deepEqual(Array.from(config.attributeFilter),['hidden']);
        }
    },
    fetch: async(url, options) => {
        assert.match(url,/eventoEntranteWebrtc/);
        assert.equal(options.method,'POST');
        assert.equal(options.credentials,'same-origin');
        const form = new URLSearchParams(options.body);
        assert.equal(form.get('csrf_token'),'testcsrf');
        post.push({event:form.get('evento'),id:form.get('session_id')});
        return {ok:true,json:async()=>({ok:true})};
    },
    console: {
        warn(...args) {throw Error('Error de registro inesperado '+args.join(' '));}
    },
    addEventListener(type,fn) {handlers[type]=fn;},
    setInterval(fn,ms) {
        if (ms===500) interval=fn;
        else if (ms===600) consistencyInterval=fn;
        else throw new Error('Intervalo telefónico inesperado '+ms);
    },
    localStorage: {
        getItem(k) {return store.get(k)||null;}
    },
    location: {
        origin:'https://fundacionrededumex.org',
        href:'https://fundacionrededumex.org/index.php'
    }
};
vm.runInNewContext(script, {window:runtime, URL, URLSearchParams}, {
    filename:'telefonia_analista_historial_pasivo.js'
});
assert.equal(createdButtons,2,'Agregar las acciones al modal existente');
assert.equal(createdContext,1,'Insertar solo el contexto dentro de la tarjeta');
assert.equal(typeof interval,'function');
assert.equal(typeof consistencyInterval,'function');
assert.equal(typeof attributeMutation,'function');
assert.equal(cssNode.textContent.includes('data-incoming-close-finished'),true);
assert.match(cssNode.textContent, /display:flex!important;flex-direction:row;/,
    'Las acciones finales deben ocupar una misma fila');
assert.match(cssNode.textContent, /flex:1 1 0!important;min-width:0;min-height:48px;/,
    'Registrar y Cerrar deben tener tamaños proporcionados');
assert.match(cssNode.textContent, /@media \(max-width:420px\)/,
    'En celulares pequeños los botones se apilan con el mismo ancho');
assert.match(cssNode.textContent, /data-analyst-webrtc-finished="1"/,
    'La corrección CSS debe estar aislada al final de la llamada');
assert.equal(panel.hidden,true);
assert.equal(controls.get('[data-incoming-register-finished]').hidden,true);
const status=sel=>controls.get(sel);
const sleep=async()=> {
    for(let n=0;n<15;n++) await new Promise(resolve=>setImmediate(resolve));
};
const callId='sdk-l5lm1oo-b1c2d3e';
function publish(fields={}) {
    const data=Object.assign({
        userId:7,updatedAt:Date.now(),
        direction:'incoming',context:{type:'SDK_INCOMING',callerName:'Recepcion-Tania'},
        destination:'100',callToken:callId,active:true,
        phase:'incoming-ringing'
    },fields);
    data.heartbeatTest=++shellContext;
    store.set('impe:telephony:state:7',JSON.stringify(data));
    interval();
}
publish({direction:'outgoing',context:{type:'DIALER'}});
assert.equal(runtime.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.isObserving(),false);
assert.equal(panel.hidden,true,'El módulo nunca interfiere con las salientes');

publish();
publish({phase:'in-progress'});
publish({active:false,phase:'ready',direction:''});
assert.equal(runtime.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.hasFinalized(),true);
assert.equal(panel.hidden,false,'EL MISMO modal permanece visible al colgar');
assert.equal(panel.attrs['data-analyst-webrtc-finished'],'1');
assert.equal(status('[data-incoming-title]').textContent,'Llamada finalizada');
assert.equal(status('[data-incoming-hangup]').hidden,true);
assert.equal(status('[data-incoming-answer]').hidden,true);
assert.equal(status('[data-incoming-timer]').textContent.length,5);
assert.equal(status('[data-incoming-close-finished]').hidden,false);
panel.hidden=true; // emula render periódico del shell antiguo
attributeMutation();
assert.equal(panel.hidden,false,'El render del shell no puede ocultar el resumen');

(async()=>{
    await sleep();
    assert.equal(runtime.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.saved(),true);
    assert.equal(status('[data-incoming-register-finished]').hidden,false);
    assert.equal(status('[data-incoming-retry-save]').hidden,true);
    assert.deepEqual(post.map(x=>x.event),
        ['ringing','answered','ringing','answered','ended']);
    status('[data-incoming-register-finished]').listeners.click();
    assert.match(crm.src,/sdk_session_id=sdk-l5lm1oo-b1c2d3e/);
    assert.equal(panel.hidden,true);
    assert.equal(runtime.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.hasFinalized(),false);

    publish({callToken:'sdk-l5lm1oo-e7f8g9h'});
    publish({callToken:'',active:false,phase:'ready',direction:''});
    await sleep();
    assert.equal(status('[data-incoming-register-finished]').hidden,true);
    assert.equal(status('[data-incoming-close-finished]').hidden,false);
    assert.equal(runtime.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.hasFinalized(),true);
    status('[data-incoming-close-finished]').listeners.click();
    assert.equal(panel.hidden,true);
    assert.equal(runtime.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG.hasFinalized(),false);
    // Dos llamadas: la segunda no se marca contestada.
    assert.deepEqual(post.map(x=>x.event).slice(-3),['ringing','ringing','missed']);
    console.log('Modal Analista: OK (mismo panel, estilos existentes, cerrar, resultado, persistencia y SIP intacto)');
})().catch(e=>{console.error(e);process.exitCode=1;});