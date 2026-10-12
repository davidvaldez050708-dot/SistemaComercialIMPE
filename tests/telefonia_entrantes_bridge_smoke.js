'use strict';
// La identificación de los botones está probada sin depender del SDK remoto.
const assert = require('node:assert/strict');
const factory = require('../public/javascript/telefonia_entrantes_bridge.js');
const calls = [];
function makeButton(color,x,y,label='') {
    return {
        disabled:false,
        style:{color},
        getAttribute(key) {return key==='aria-label' ? label : null;},
        getBoundingClientRect() {return {left:x,top:y,width:64,height:64};},
        querySelectorAll() {return [];},
        click() {calls.push(label || color);}
    };
}
const red=makeButton('rgb(220,10,18)',50,60);
const green=makeButton('rgb(40,185,20)',125,60);
const win={getComputedStyle(el) {
    return {color:el.style.color,fill:el.style.color,stroke:'none',
        backgroundColor:'transparent'};
}};
const doc={
    defaultView:win,
    querySelectorAll(selector) {
        return selector==='iframe'?[]:[red,green];
    }
};
let ringing=true;
let talking=false;
const bridge=factory.create(doc,()=>ringing,()=>talking);
assert.equal(bridge.available(),true);
assert.equal(bridge.answer(),true);
assert.equal(calls.length,1);
assert.equal(calls[0],'rgb(40,185,20)');
ringing=false;
talking=true;
assert.equal(bridge.answer(),false);
assert.equal(bridge.canHangup(),true);
assert.equal(bridge.hangup(),true);
assert.equal(calls[1],'rgb(220,10,18)');
talking=false;
assert.equal(bridge.hangup(),false);
const unknown=factory.create({...doc,querySelectorAll() {return [];}},()=>true,()=>false);
assert.equal(unknown.available(),false);
assert.equal(unknown.answer(),false);
assert.equal(calls.length,2,'no se pulsa ningún botón equivocado');
console.log('Puente entrante WebRTC: OK (contestar, colgar, bloqueo y respaldo)');
