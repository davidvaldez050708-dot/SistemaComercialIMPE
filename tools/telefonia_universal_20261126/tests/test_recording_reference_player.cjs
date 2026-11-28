'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class Element {
 constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.attributes={};this.style={setProperty(){}};this.listeners={};this.paused=true;this.currentTime=0;this.duration=NaN;}
 append(...nodes){this.children.push(...nodes);}appendChild(node){this.children.push(node);return node;}
 replaceChildren(...nodes){this.children=nodes;}setAttribute(k,v){this.attributes[k]=v;}addEventListener(k,v){this.listeners[k]=v;}
 pause(){this.paused=true;}play(){this.paused=false;return Promise.resolve();}
}
const document={createElement:tag=>new Element(tag),createDocumentFragment:()=>new Element('fragment'),baseURI:'https://crm.fixture.invalid/'};
const find=(node,tag)=>[...(node.tag===tag?[node]:[]),...node.children.flatMap(c=>find(c,tag))];
const box={window:{},document,location:{origin:'https://crm.fixture.invalid'},URL};vm.createContext(box);
vm.runInContext(fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_segmentos_player.js','utf8'),box);
const host=new Element('host'),a='17490000000000001234567890',b='17490000000000001234567891.123456789012345678901';
let checks=0;
assert.equal(box.window.IMPE_TELEPHONY_SEGMENTS.create(host,'https://crm.fixture.invalid/audio?verificar=1',2,[{indice:3,referencia:a},{indice:7,referencia:b}]),true);checks++;
const audios=find(host,'audio'),downloads=find(host,'a');
assert.equal(audios.length,2);assert.equal(new URL(audios[0].src).searchParams.get('grabacion'),a);assert.equal(new URL(audios[1].src).searchParams.get('grabacion'),b);checks+=3;
assert.equal(new URL(audios[0].src).searchParams.get('segmento'),'3');assert.equal(new URL(audios[1].src).searchParams.has('verificar'),false);checks+=2;
assert.equal(new URL(downloads[1].href).searchParams.get('grabacion'),b);assert.equal(new URL(downloads[1].href).searchParams.get('download'),'1');checks+=2;
assert.equal(audios[0].preload,'none');assert.equal(audios[1].preload,'none');checks+=2;
assert.equal(box.window.IMPE_TELEPHONY_SEGMENTS.create(new Element('host'),'https://external.fixture.invalid/audio',1,[{referencia:a}]),false);checks++;
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_recepcion_marketing.js','utf8');
const from=source.indexOf('        function node(tag, className, content) {'),to=source.indexOf('        function drawRow',from);assert(from>=0&&to>from);
const adapter={document,URL,window:{location:{href:document.baseURI}},root:{dataset:{recordingUrl:'/prueba_telefonia/api/grabacion_recepcion_marketing.php'}}};
vm.createContext(adapter);vm.runInContext(source.slice(from,to)+'\nrow=recordingRow;',adapter);
const data={pbx_call_id:'out_fixture_recording',tipo:'Saliente',estado:'Completada',tiene_grabacion:true};
const row=new Element('tr'),detail=adapter.row(data,0,row),button=find(row,'button')[0];
assert.equal(new URL(button.dataset.marketingPbxAudio).pathname,'/prueba_telefonia/api/grabacion_recepcion_marketing.php');
assert.equal(Object.hasOwn(button.attributes,'data-sales-recording-toggle'),false);assert(find(detail,'div').some(n=>Object.hasOwn(n.dataset,'marketingPbxAudios')));checks+=3;
const transferred=new Element('tr');adapter.row({...data,estado:'Transferida'},1,transferred);
assert.equal(new URL(find(transferred,'button')[0].dataset.marketingPbxAudio).pathname,'/prueba_telefonia/api/grabacion_transferida_pbx.php');checks++;
const excluded=new Element('tr');assert.equal(adapter.row({...data,excluir_grabacion:true},2,excluded),null);assert.equal(find(excluded,'button').length,0);checks+=2;
console.log(`PASS ${checks} actual audio player/Reception adapter checks: exact long recording reference survives seek/download, sparse ordinals retained for compatibility, same-origin/preload guards, one shared segmented player and original transfer/no-contact paths. Synthetic DOM; no binary audio/network.`);
