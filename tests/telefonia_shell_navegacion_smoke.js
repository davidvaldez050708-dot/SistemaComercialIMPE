'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const code = fs.readFileSync('public/javascript/telefonia_shell_navegacion.js', 'utf8');
const data = new Map();
const base = 'https://crm.example.test/index.php';
const home = base + '?controller=home&action=index';
const seguimiento = base + '?controller=seguimientoVinculacion&action=estado';
const expediente = base + '?controller=seguimientoVinculacion&action=detalle&id=14';
const login = base + '?controller=login&action=mostrarLogin';
function boot(type = 'navigate') {
    const listeners = {};
    const timeouts = new Map();
    const mask = {
        hidden: false,
        classes: new Set(),
        classList: {
            toggle(name, add) {
                if (add) mask.classes.add(name); else mask.classes.delete(name);
            }
        },
        els: {},
        querySelector(selector) {
            return this.els[selector] || null;
        }
    };
    mask.els['[data-shell-navigation-message]'] = {textContent:''};
    mask.els['[data-shell-navigation-retry]'] = {hidden:true,events:{}};
    mask.els['[data-shell-navigation-retry]'].addEventListener = function (ev,fn) {this.events[ev]=fn;};
    const innerEvents = {};
    const doc = {
        body: {querySelector(selector) {
            return selector === '.admin-shell' ? doc.hasShell !== false ? {} : null : null;
        }},
        addEventListener(event,fn) {innerEvents[event] = fn;},
        defaultView: {addEventListener(event,fn) {this[event] = fn;}}
    };
    const crm = {
        events:{}, contentDocument:doc,
        contentWindow: {location:{href:'about:blank'}},
        addEventListener(ev,fn) {this.events[ev]=fn;},
        set src(value) {
            this._src=value;
            this.contentWindow.location.href=value;
        },
        get src() {return this._src;}
    };
    const win = {
        location:{href:base+'?controller=telefonia&action=shell',origin:'https://crm.example.test'},
        IMPE_PHONE_SHELL_CONFIG:{
            userId:11,homeUrl:home,loginUrl:login
        },
        performance:{getEntriesByType() {return [{type}];}},
        addEventListener(event,fn) {listeners[event]=fn;},
        setTimeout(fn,ms) {timeouts.set('t'+timeouts.size,{fn,ms}); return 't'+(timeouts.size-1);},
        clearTimeout(id) {timeouts.delete(id);}
    };
    const storage = {
        getItem(key) {return data.get(key) ?? null;},
        setItem(key,val) {data.set(key,String(val));},
        removeItem(key) {data.delete(key);}
    };
    const docRoot={getElementById(id) {return id==='impeCrmFrame'?crm:null;},
        querySelector(selector) {return selector==='[data-shell-navigation-mask]'?mask:null;}};
    vm.runInNewContext(code,{
        window:win,document:docRoot,URL,sessionStorage:storage,Date,console
    },{timeout:2000});
    return {
        crm,mask,doc,innerEvents,listeners,timeouts,
        navigate(url) {
            crm.contentWindow.location.href=url;
            crm.events.load();
        },
        retry:() => mask.els['[data-shell-navigation-retry]'].events.click(),
        message:() => mask.els['[data-shell-navigation-message]'].textContent
    };
}

data.clear();
const first = boot();
assert.equal(first.crm.src,home,'Login nueva debe iniciar en Home');
assert.equal(first.mask.hidden,false,'Mostrar carga mientras se construye el CRM');
first.navigate(home);
assert.equal(first.mask.hidden,true,'No dejar capa de carga tras respuesta CRM válida');
assert.equal(typeof first.innerEvents.click,'function','Observar navegación de módulos');

first.innerEvents.click({
    button:0,target:{closest(){return {href:seguimiento,target:'',download:false};}},
    defaultPrevented:false,
    metaKey:false,ctrlKey:false,altKey:false,shiftKey:false
});
assert.equal(first.mask.hidden,false,'La ruta debe cubrir flash blanco desde el clic');
first.navigate(seguimiento);
assert.equal(first.mask.hidden,true,'Debe regresar la interfaz al cargar Seguimiento');
assert.equal(data.get('impe:crm:shell:last-route:11'),seguimiento);

const reloaded = boot('reload');
assert.equal(reloaded.crm.src,seguimiento,
    'F5/reload del shell conserva Seguimiento y no inicia Home');
reloaded.navigate(seguimiento);
assert.equal(reloaded.mask.hidden,true);

reloaded.innerEvents.click({
    button:0,target:{closest(){return {href:expediente,target:'',download:false};}},
    defaultPrevented:false,metaKey:false,ctrlKey:false,altKey:false,shiftKey:false
});
reloaded.crm.contentWindow.location.href=expediente;
reloaded.doc.hasShell=false;
reloaded.crm.events.load();
assert.equal(reloaded.mask.hidden,false,'Página PHP fallida no muestra blanco');
assert.equal(reloaded.mask.els['[data-shell-navigation-retry]'].hidden,false,
    'Debe poder reintentar la misma ruta');
assert.equal(reloaded.crm.src,seguimiento,'No redirigir automáticamente a Inicio');
assert.equal(data.get('impe:crm:shell:last-route:11'),seguimiento,
    'La página fallida no corrompe la última ruta válida');
reloaded.doc.hasShell=true;
reloaded.retry();
assert.equal(reloaded.crm.src,expediente,'Reintentar recupera la vista, no Home');
reloaded.navigate(expediente);
assert.equal(reloaded.mask.hidden,true);
assert.equal(data.get('impe:crm:shell:last-route:11'),expediente);

const freshLogin = boot('navigate');
assert.equal(freshLogin.crm.src,home,'Un nuevo login no hereda expedientes anteriores');
assert.equal(data.get('impe:crm:shell:last-route:11'),undefined,'Limpia URL del acceso anterior');

data.set('impe:crm:shell:last-route:11','https://malicioso.test/index.php?controller=home');
const unsafe = boot('reload');
assert.equal(unsafe.crm.src,home,'No navegar a dominios externos almacenados');
unsafe.navigate(login);
assert.equal(data.get('impe:crm:shell:last-route:11'),undefined,
    'La salida a login limpia la navegación almacenada');

console.log('Shell navegación: OK (login, F5, módulos, error/recuperación, URL segura).');
