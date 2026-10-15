/* Escenarios sin PBX/BD: el panel no debe crecer, el teléfono identifica
   pero no transfiere automáticamente ni presupone identidad por Caller ID. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Fake {
    constructor(tag = 'div') {
        this.tag = tag;
        this.hidden = true;
        this.children = [];
        this.options = this.children;
        this.value = '';
        this.textContent = '';
        this.style = {};
        this.dataset = {};
        this.attrs = {};
        this.events = {};
        this.classList = {
            add() {},
            toggle() {}
        };
        this.disabled = false;
    }
    appendChild(child) {
        if (child.parent) child.parent.children =
            child.parent.children.filter(x => x !== child);
        child.parent = this;
        this.children.push(child);
        return child;
    }
    replaceChildren(...nodes) {
        this.children = [];
        nodes.forEach(n => this.appendChild(n));
        if (this.tag === 'select') this.value = nodes[0]?.value || '';
    }
    addEventListener(name, cb) { this.events[name] = cb; }
    dispatch(name, payload = {}) { this.events[name]?.(payload); }
    setAttribute(key, value) { this.attrs[key] = value; }
    querySelectorAll() { return this.buttons || []; }
    querySelector() { return null; }
    focus() {}
    getBoundingClientRect() {
        return this.bounds || {left:500,top:5,right:1110,bottom:435,width:610,height:430};
    }
}

function boot(identified) {
    const els = {};
    const selectors = [
        '[data-shell-transfer-toggle]','[data-shell-transfer-panel]',
        '[data-shell-capture-toggle]','[data-shell-capture-panel]',
        '[data-routing-type]','[data-routing-state]',
        '[data-routing-search]','[data-routing-destination]',
        '[data-routing-matches]','[data-routing-matches-list]',
        '[data-routing-feedback]','[data-routing-identification]',
        '[data-routing-search-toggle]','[data-routing-search-wrap]',
        '[data-incoming-panel]','[data-routing-close]',
        '[data-routing-attended]','[data-routing-direct]'
    ];
    selectors.forEach(sel => { els[sel] = new Fake(); });
    const select = sel => els[sel];
    select('[data-routing-type]').value = 'INSTITUCION';
    for(const key of ['[data-routing-type]','[data-routing-state]','[data-routing-destination]']) {
        select(key).tag = 'select';
    }
    const phone = select('[data-incoming-panel]');
    const pane = select('[data-shell-transfer-panel]');
    const capture = select('[data-shell-capture-panel]');
    pane.buttons = [select('[data-routing-attended]'),select('[data-routing-direct]')];
    phone.appendChild(pane);
    phone.appendChild(capture);
    const body = new Fake('body');
    const document = {
        body,
        querySelector: select,
        createElement(tag) { return new Fake(tag); }
    };
    const urls = [], transfers = [], requests = [];
    const data = {
        destinations: [
            {usuario_id:7,extension:'101',estado_id:11,nombre:'Analista G',
                estado_nombre:'Guanajuato',rol:'Analista de Datos',tipo:'ANALISTA_DATOS'},
            {usuario_id:8,extension:'105',estado_id:22,nombre:'Analista M',
                estado_nombre:'Morelos',rol:'Analista de Datos',tipo:'ANALISTA_DATOS'}
        ],
        estados: [{id:11,nombre:'Guanajuato'},{id:22,nombre:'Morelos'}]
    };
    const timers=[];
    const active={id:'call-100-1',direction:'incoming',phone:'+52 777 788 5173'};
    const window = {
        innerWidth:1730,innerHeight:860,
        location:{href:'https://crm.example.org/index.php'},
        IMPE_PHONE_SHELL_CONFIG:{
            userId:2, routingListUrl:'https://crm.example.org/index.php?controller=telefonia&action=destinosTransferencia',
            routingAuthorizeUrl:'https://crm.example.org/index.php?controller=telefonia&action=autorizarTransferencia',
            routingCsrf:'csrf'
        },
        IMPE_TELEPHONY_SHELL:{
            getActiveCall(){return active;},
            requestAuthorizedTransfer(token){transfers.push(token);return true;}
        },
        addEventListener(){},
        clearTimeout(){},
        setTimeout(fn){timers.push(fn);return timers.length;}
    };
    async function fetch(url, opts) {
        requests.push({url:String(url), method:opts?.method || 'GET'});
        if (opts?.method === 'POST') return {ok:true, json:async () => ({
            ok:true,token:'a'.repeat(48)})};
        const q=new URL(String(url)).searchParams;
        const people = q.get('estado_id')
            ? data.destinations.filter(d=>String(d.estado_id)===q.get('estado_id'))
            : data.destinations;
        return {ok:true,json:async()=>({
            ok:true,destinos:people,estados:data.estados,
            identificacion:q.get('telefono_origen') ? identified : [],
            instituciones:[]
        })};
    }
    vm.runInNewContext(
        fs.readFileSync('public/javascript/telefonia_enrutamiento.js','utf8'),
        {window,document,fetch,URL,URLSearchParams,sessionStorage:{
            getItem(){return null;},setItem(){}
        },console},
        {filename:'telefonia_enrutamiento.js'}
    );
    return {els,body,phone,pane,active,timers,transfers,requests};
}
async function flush(){ await new Promise(resolve=>setImmediate(resolve)); }
(async ()=>{
    const original = [{
        seguimiento_id:98,nombre:'Universidad de prueba',
        responsable_id:7,responsable:'Analista G',estado_id:11,
        estado_nombre:'Guanajuato',extension:'101'
    }];
    const recognized=boot(original);
    const e=recognized.els;
    e['[data-shell-transfer-toggle]'].dispatch('click');
    await flush();
    assert.equal(recognized.pane.parent,recognized.body,
        'La transferencia vive fuera del modal telefónico');
    assert.equal(recognized.phone.children.includes(recognized.pane),false,
        'El modal telefónico NO contiene el formulario que lo hacía crecer');
    assert.equal(e['[data-routing-state]'].value,'11',
        'El estado de un teléfono conocido se sugiere automáticamente');
    assert.equal(e['[data-routing-destination]'].value,'7:101:11',
        'Se sugiere el Analista que lleva el expediente');
    assert.equal(e['[data-routing-identification]'].hidden,false);
    assert.equal(e['[data-routing-search-wrap]'].hidden,true,
        'La búsqueda manual debe quedar oculta si el número coincide');
    assert.equal(recognized.requests[0].url.includes('telefono_origen='),true,
        'Se busca con caller ID sin requerir que Tania escriba nombre');
    assert.deepEqual(recognized.transfers,[],'Nunca transferir automáticamente');
    e['[data-routing-attended]'].dispatch('click');
    await flush();
    assert.equal(recognized.requests.some(x=>x.method==='POST'),false,
        'No se autoriza destino hasta confirmar identidad');
    const confirm=e['[data-routing-identification]'].children.find(x=>x.tag==='button');
    assert.ok(confirm,'Debe existir la opción de confirmar la institución');
    confirm.dispatch('click');
    e['[data-routing-attended]'].dispatch('click');
    await flush();
    assert.deepEqual(recognized.transfers,['a'.repeat(48)],
        'Tras confirmar puede solicitar transferencia autorizada');

    const shared=boot([
        original[0],
        {...original[0],seguimiento_id:99,nombre:'Otra institución',estado_id:22,
            estado_nombre:'Morelos',responsable_id:8,responsable:'Analista M',extension:'105'}
    ]);
    shared.els['[data-shell-transfer-toggle]'].dispatch('click');
    await flush();
    assert.equal(shared.els['[data-routing-identification]'].children
        .filter(x=>x.tag==='button').length,2,
        'Número compartido: pedir selección de institución');
    assert.equal(shared.els['[data-routing-state]'].value,'',
        'Número compartido: no predeterminar un territorio');
    assert.deepEqual(shared.transfers,[]);

    const newInstitution=boot([]);
    newInstitution.els['[data-shell-transfer-toggle]'].dispatch('click');
    await flush();
    assert.equal(newInstitution.els['[data-routing-search-wrap]'].hidden,false,
        'Número desconocido: mostrar campo de nombre sin otro clic');
    assert.equal(newInstitution.els['[data-routing-search-toggle]'].hidden,true);
    assert.equal(newInstitution.els['[data-routing-destination]'].value,'',
        'Sin estado aún no debe seleccionar responsable');
    newInstitution.els['[data-routing-state]'].value='11';
    newInstitution.els['[data-routing-state]'].dispatch('change');
    const delayed=newInstitution.timers.pop();
    delayed();
    await flush();
    assert.equal(newInstitution.els['[data-routing-destination]'].value,'7:101:11',
        'Institución nueva: con el estado, seleccionar al único Analista');
    console.log('UI transferencias rápida: OK (sin modal gigante, número único/compartido, estado nuevo, confirmación y solicitud).');
})().catch(e=>{console.error(e);process.exit(1);});
