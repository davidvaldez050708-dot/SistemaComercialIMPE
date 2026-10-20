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
function boot(type = 'navigate', sales = false) {
    const listeners = {};
    const timeouts = new Map();
    const mask = {
        hidden: true,
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
            userId:11,homeUrl:home,loginUrl:login,salesResults:sales
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

for(const vista of ['', 'devoluciones','agenda']){
 data.clear();const url=base+'?controller=telefonia&action=misLlamadas'+(vista?'&vista='+vista:'');
 const sales=boot('navigate',true);sales.navigate(url);
 assert.equal(boot('reload',true).crm.src,url,'F5 conserva la bandeja comercial '+vista);
 sales.navigate(url+'&pbx_call_id=pbx-transitorio&extension=150');
 assert.equal(boot('reload',true).crm.src,url,'F5 no reabre el resultado transitorio '+vista);
}
data.clear();data.set('impe:crm:shell:last-route:11',base+'?controller=telefonia&action=host');
assert.equal(boot('reload',true).crm.src,home,'No restablece endpoints del motor como módulos');

data.clear();
const first = boot();
assert.equal(first.crm.src,home,'Login nueva debe iniciar en Home');
assert.equal(first.mask.hidden,true,'No mostrar pantalla de carga al entrar al CRM');
first.navigate(home);
assert.equal(first.mask.hidden,true,'No dejar capa de carga tras respuesta CRM válida');
assert.equal(typeof first.innerEvents.click,'function','Observar navegación de módulos');

first.innerEvents.click({
    button:0,target:{closest(){return {href:seguimiento,target:'',download:false};}},
    defaultPrevented:false,
    metaKey:false,ctrlKey:false,altKey:false,shiftKey:false
});
assert.equal(first.mask.hidden,true,'No cubrir la vista al cambiar de módulo');
first.navigate(seguimiento);
assert.equal(first.mask.hidden,true,'Debe regresar la interfaz al cargar Seguimiento');
assert.equal(data.get('impe:crm:shell:last-route:11'),seguimiento);

// El iframe conserva navegación nativa: ni clics ni beforeunload ni
// recarga muestran una capa de "Cargando módulo".
first.innerEvents.click({
    button:0,target:{closest(){return {href:expediente,target:'',download:false};}},
    defaultPrevented:false,metaKey:false,ctrlKey:false,altKey:false,shiftKey:false
});
assert.equal(first.mask.hidden,true,'Sin cargador en navegación posterior');
first.doc.defaultView.beforeunload();
assert.equal(first.mask.hidden,true,'Sin cargador al desmontar documento PHP');
first.navigate(expediente);
assert.equal(first.mask.hidden,true,'Sin cargador después de cambiar expediente');
assert.equal(data.get('impe:crm:shell:last-route:11'),expediente);

const reloaded = boot('reload');
assert.equal(reloaded.crm.src,expediente,
    'F5/reload del shell conserva exactamente el último expediente abierto');
reloaded.navigate(expediente);
assert.equal(reloaded.mask.hidden,true);

reloaded.innerEvents.click({
    button:0,target:{closest(){return {href:seguimiento,target:'',download:false};}},
    defaultPrevented:false,metaKey:false,ctrlKey:false,altKey:false,shiftKey:false
});
assert.equal(reloaded.mask.hidden,true,'Sin cargador incluso si después falla');
reloaded.crm.contentWindow.location.href=seguimiento;
reloaded.doc.hasShell=false;
reloaded.crm.events.load();
assert.equal(reloaded.mask.hidden,false,'Página PHP fallida no muestra blanco');
assert.equal(reloaded.mask.els['[data-shell-navigation-retry]'].hidden,false,
    'Debe poder reintentar la misma ruta');
assert.equal(reloaded.crm.src,expediente,'No redirigir automáticamente a Inicio');
assert.equal(data.get('impe:crm:shell:last-route:11'),expediente,
    'La página fallida no corrompe la última ruta válida');
reloaded.doc.hasShell=true;
reloaded.retry();
assert.equal(reloaded.crm.src,seguimiento,'Reintentar recupera el módulo que falló, no Home');
reloaded.navigate(seguimiento);
assert.equal(reloaded.mask.hidden,true);
assert.equal(data.get('impe:crm:shell:last-route:11'),seguimiento);

const freshLogin = boot('navigate');
assert.equal(freshLogin.crm.src,home,'Un nuevo login no hereda expedientes anteriores');
assert.equal(data.get('impe:crm:shell:last-route:11'),undefined,'Limpia URL del acceso anterior');

data.set('impe:crm:shell:last-route:11','https://malicioso.test/index.php?controller=home');
const unsafe = boot('reload');
assert.equal(unsafe.crm.src,home,'No navegar a dominios externos almacenados');
unsafe.navigate(login);
assert.equal(data.get('impe:crm:shell:last-route:11'),undefined,
    'La salida a login limpia la navegación almacenada');

// Si una página tarda demasiado, sí debe mostrarse recuperación,
// pero sin bloquear rutinariamente cada navegación.
const timeoutCase = boot();
timeoutCase.navigate(home);
timeoutCase.innerEvents.click({
    button:0,target:{closest(){return {href:seguimiento,target:'',download:false};}},
    defaultPrevented:false,metaKey:false,ctrlKey:false,altKey:false,shiftKey:false
});
assert.equal(timeoutCase.mask.hidden,true);
const watchdog = [...timeoutCase.timeouts.values()].find(entry=>entry.ms===20000);
assert.ok(watchdog,'Debe existir supervisión de fallo de navegación');
watchdog.fn();
assert.equal(timeoutCase.mask.hidden,false,'Sólo ante fallo aparece recuperación');
assert.equal(timeoutCase.mask.els['[data-shell-navigation-retry]'].hidden,false);
timeoutCase.retry();
assert.equal(timeoutCase.crm.src,seguimiento);
assert.equal(timeoutCase.mask.hidden,true,'El reintento no muestra cargador');

// Navegación cancelada para abrir modal no puede disparar alerta de timeout.
const cancelled = boot();
cancelled.navigate(home);
const cancelledEvent = {
    button:0,target:{closest(){return {href:seguimiento,target:'',download:false};}},
    defaultPrevented:false,metaKey:false,ctrlKey:false,altKey:false,shiftKey:false
};
cancelled.innerEvents.click(cancelledEvent);
cancelledEvent.defaultPrevented = true;
const cancelTimer = [...cancelled.timeouts.values()].find(entry=>entry.ms===0);
assert.ok(cancelTimer);
cancelTimer.fn();
const obsoleteWatchdog = [...cancelled.timeouts.values()].find(entry=>entry.ms===20000);
assert.equal(obsoleteWatchdog,undefined,'Un clic AJAX cancelado no deja temporizador activo');
assert.equal(cancelled.mask.hidden,true,'Clic de modal no debe mostrar pantalla de carga');

console.log('Shell navegación: OK (sin cargadores, F5, módulos, AJAX, error/reintento, URL segura).');
