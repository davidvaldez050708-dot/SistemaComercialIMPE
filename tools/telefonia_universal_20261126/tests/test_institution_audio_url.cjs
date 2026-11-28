'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/seguimiento_llamadas_expediente.js','utf8');
const start=source.indexOf('    function urlAudioExpediente('),end=source.indexOf('    // Asegura',start);
assert(start>=0&&end>start);
const box={URL,document:{baseURI:'https://crm.fixture.invalid/'}};vm.createContext(box);vm.runInContext(source.slice(start,end),box);
let checks=0;const data={id:123,proveedor:'ZADARMA',tiene_grabacion:true,grabacion_url:'https://foreign.invalid/audio'};
let url=box.urlAudioExpediente(data,'');assert.equal(url.origin,'https://crm.fixture.invalid');assert.equal(url.pathname,'/prueba_telefonia/api/grabacion_interaccion.php');assert.equal(url.searchParams.get('interaccion_id'),'123');assert.equal(url.searchParams.get('verificar'),'1');assert.equal(url.searchParams.has('pbx_call_id'),false);checks+=5;
for(const patch of [{id:0},{id:'../private'},{id:1.5},{id:Number.MAX_SAFE_INTEGER+1},{proveedor:'ZADARMA_WEBRTC'},{excluir_grabacion:true},{tiene_grabacion:false}]){assert.equal(box.urlAudioExpediente({...data,...patch},''),null);checks++;}
url=box.urlAudioExpediente(data,'out_fixture_call');assert.equal(url.pathname,'/prueba_telefonia/api/grabacion_transferida_pbx.php');assert.equal(url.searchParams.get('pbx_call_id'),'out_fixture_call');assert.equal(url.searchParams.has('interaccion_id'),false);checks+=3;
url=box.urlAudioExpediente({...data,grabacion_transferida_institucion:true,seguimiento_id:7},'out_fixture_call');assert.equal(url.pathname,'/prueba_telefonia/api/grabacion_transferida_institucion.php');assert.equal(url.searchParams.get('seguimiento_id'),'7');checks+=2;
console.log(`PASS ${checks} actual institution audio URL adapter checks; shared player paths, original transfer routes, no foreign URL or invalid native ID. No browser/network.`);
