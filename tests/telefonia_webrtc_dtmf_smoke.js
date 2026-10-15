'use strict';
const assert = require('node:assert/strict');
const {create} = require('../public/javascript/telefonia_webrtc_dtmf.js');

const sent = [];
let currentIce = 'connected';
class Peer {
    constructor() {
        this.iceConnectionState = currentIce;
        this.connectionState = 'connected';
        this.signalingState = 'stable';
        this.track = {kind:'audio',readyState:'live'};
        this.dtmf = {
            canInsertDTMF: true,
            insertDTMF(tones, duration, gap) {
                sent.push({tones, duration, gap});
            }
        };
    }
    addEventListener() {}
    getSenders() { return [{track:this.track,dtmf:this.dtmf}]; }
}
const view={RTCPeerConnection:Peer, Proxy, Reflect};
const bridge=create(view);
assert.equal(bridge.install(),true);
assert.equal(bridge.install(),true);
const a=new view.RTCPeerConnection();
assert.equal(bridge.trackedCount(),1);
assert.ok(a instanceof Peer,'conserva el constructor nativo');
assert.equal(bridge.send('#101#'),true);
assert.deepEqual(sent,[{tones:'#101#',duration:180,gap:100}]);
assert.equal(bridge.send('*103#'),true);
assert.equal(bridge.send('#1#'),false);
assert.equal(bridge.send('#101#;alert()'),false);
assert.equal(sent.length,2,'jamás se envían tonos inválidos');
a.track.readyState='ended';
assert.equal(bridge.send('#101#'),false,'track terminado');
a.track.readyState='live';
a.dtmf.canInsertDTMF=false;
assert.equal(bridge.send('#101#'),false,'telephony event no negociado');
a.dtmf.canInsertDTMF=true;
currentIce='connected';
const b=new view.RTCPeerConnection();
assert.equal(bridge.send('#101#'),false,'no elegir entre dos llamadas');
b.signalingState='closed';
assert.equal(bridge.send('#101#'),true,'tras cerrar otra conexión');
a.iceConnectionState='disconnected';
assert.equal(bridge.send('#101#'),false,'sin conexión ICE');

const fs = require('node:fs');
const host = fs.readFileSync('public/javascript/telefonia_host.js','utf8');
const routing = fs.readFileSync('public/javascript/telefonia_enrutamiento.js','utf8');
const viewPhp = fs.readFileSync('app/views/telefonia/host.php','utf8');
assert.ok(viewPhp.includes("telefonia_webrtc_dtmf.js"));
assert.ok(viewPhp.indexOf('telefonia_webrtc_dtmf.js') <
          viewPhp.indexOf('telefonia_host.js'));
assert.ok(host.includes("dtmfBridge.install()"));
assert.ok(host.indexOf("dtmfBridge.install()") < host.indexOf("await loadScript(sdkLibUrl"));
assert.ok(host.includes("dtmf.send(tones)"));
assert.ok(!host.slice(host.indexOf('const sendTransferDtmf'), host.indexOf('const ejecutarTransferenciaAutorizada')).includes('mostrarHostEntrante('));
assert.ok(!routing.includes('Abrir teclado Zadarma'));
assert.ok(!routing.includes('showProviderControls?.()'));
assert.ok(routing.includes('transferSubmittedAt'));
console.log('Transferencia RFC2833 y modal CRM: OK (autorización, envío WebRTC, errores, sin widget externo)');
