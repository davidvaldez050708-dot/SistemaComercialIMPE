'use strict';
// Simulación de DOM mínima, sin acceso real a micrófono o Zadarma.
const assert = require('node:assert/strict');
require('../public/javascript/telefonia_teclado.js');
const factory = globalThis.IMPE_TELEPHONY_DIALER;
assert.equal(factory.normalize('55 1234 5678'),'525512345678');
assert.equal(factory.normalize('+52 (55) 1234-5678'),'525512345678');
assert.equal(factory.normalize('100'),'');
assert.equal(factory.normalize('555-123'),'');
assert.equal(factory.normalize('texto'),'');
assert.equal(factory.normalize('0000000000'),'');
assert.equal(factory.normalize('52<script>'),'');
assert.equal(factory.normalize('0012345678'),'520012345678'); // Validación estructural, no existencia del número.

function element() {
    const c = new Set();
    return {
        hidden:false,disabled:false,value:'',textContent:'',dataset:{},
        events:{},
        classList:{
            add(x){c.add(x);},remove(x){c.delete(x);},
            toggle(x,y){if(y)c.add(x);else c.delete(x);},
            contains(x){return c.has(x);}
        },
        setAttribute(k,v){this[k]=v;},
        addEventListener(k,fn){this.events[k]=fn;},
        focus(){this.focused=true;},
        appendChild(n){(this.children??=[]).push(n);},
        querySelector(){return null;},
        querySelectorAll(){return[];}
    };
}
const created=[];
const listeners={};
globalThis.document={
    activeElement:{focus(){}},
    createElement(tag){
        const e=element();e.tagName=tag;e.innerHTML='';
        const nodes=new Map();
        e.querySelector=k=>{
            if(!nodes.has(k))nodes.set(k,element());
            return nodes.get(k);
        };
        e.querySelectorAll=k=>k==='[data-dialer-key]'
            ? ['1','2','3','4','5','6','7','8','9','+','0','⌫'].map(n=>{
                const o=element();o.dataset.dialerKey=n;return o;
            }) : [];
        created.push(e);
        return e;
    },
    addEventListener(k,fn){listeners[k]=fn;}
};
const apiCalls=[];
let status={active:false};
const mockApi={
    async probe(){return {permite_salientes:true};},
    async startCall(p){apiCalls.push(p);},
    getState(){return status;},
    toggleMute(){},
    hangup(){},
    transferCall(ext,attended){apiCalls.push({transfer:ext,attended});}
};
const root=element();
const dial=factory.create({
    root,extension:'100',getApi(){return mockApi;},
    getState(){return status;},hasOutgoingRole(){return true;}
});
const e=dial.element;
const el=s=>e.querySelector(s);
function tick(){return new Promise(resolve=>setImmediate(resolve));}

(async ()=>{
    dial.open({
        number:'55 1234 5678',
        context:{type:'MARKETING_CALLBACK',llamada_origen:'out_123'}
    });
    await tick();
    assert.equal(el('[data-dialer-input]').value,'55 1234 5678');
    assert.equal(apiCalls.length,0,'abrir devolución no inicia llamada sin confirmación');
    assert.equal(el('[data-dialer-call]').disabled,false);
    assert.equal(el('[data-dialer-context]').hidden,false);
    assert.equal(dial.isManagingCall(),true);
    await el('[data-dialer-form]').events.submit({preventDefault(){}});
    assert.equal(apiCalls.length,1);
    assert.equal(apiCalls[0].destination,'525512345678');
    assert.equal(apiCalls[0].context.type,'MARKETING_CALLBACK');
    assert.equal(apiCalls[0].context.llamada_origen,'out_123');

    status={active:true,direction:'outgoing',status:'in-progress',
        destination:'525512345678',message:'En conversación'};
    dial.sync(status);
    assert.equal(el('[data-dialer-form]').hidden,true);
    assert.equal(el('[data-dialer-live]').hidden,false);
    el('[data-dialer-transfer]').events.click();
    assert.equal(el('[data-dialer-transfer-panel]').hidden,false);
    el('[data-dialer-transfer-input]').value='101';
    await el('[data-dialer-transfer-direct]').events.click();
    await tick();
    assert.deepEqual(apiCalls[1],{transfer:'101',attended:false});
    dial.close();
    assert.equal(el('[data-dialer-restore]').hidden,false);
    assert.equal(dial.isManagingCall(),true);
    el('[data-dialer-restore]').events.click();
    assert.equal(dial.isManagingCall(),true);
    dial.sync({active:false,phase:'finished',direction:'outgoing'});
    dial.close();
    assert.equal(dial.isManagingCall(),false);

    const restricted=factory.create({
        root,extension:'101',getApi(){return mockApi;},
        getState(){return {active:false};},
        hasOutgoingRole(){return false;}
    });
    restricted.open({number:'5511112222'});
    await tick();
    assert.equal(restricted.element.querySelector('[data-dialer-call]').disabled,true);
    assert.equal(apiCalls.length,2,'sin permiso no se marca');
    assert.equal(restricted.element.querySelector('[data-dialer-state]').classList.contains('is-error'),true);
    console.log('Marcador telefónico: OK (validación, confirmación, transferencia, minimizar, permisos)');
})().catch(e=>{console.error(e);process.exitCode=1;});
