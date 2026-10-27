// Ejecuta el módulo completo: apertura, identificación de llamada y rechazo de guardado.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const callbacks={},nodes={},storage=new Map();let shown=0,hidden=0,replaced=false;
const node=()=>({hidden:false,disabled:false,innerHTML:'Guardar resultado',textContent:'',value:'',type:'text'});
for(const key of ['[data-sales-feedback]','[data-sales-submit]','[data-sales-fields]','[data-sales-form-number]','[data-sales-form-state]'])nodes[key]=node();
const elements=['csrf_token','pbx_call_id','extension','prospecto_nombre','correo','resultado','observacion'].map(name=>Object.assign(node(),{name}));
for(const el of elements)elements[el.name]=el;
elements.namedItem=name=>elements[name]||null;
const form={elements,reset(){for(const el of elements)el.value='';},querySelector:s=>nodes[s]||null,querySelectorAll:()=>elements,reportValidity:()=>true,addEventListener:(key,fn)=>callbacks[key]=fn};
const modal={querySelector:()=>form,classList:{contains:()=>shown>0},dispatchEvent(){},addEventListener(){}};
const root={dataset:{detailUrl:'/detalle',saveUrl:'/guardar',autoCall:'pbx-cancelada-fixture',autoExtension:'150'},querySelector:()=>null,addEventListener(){}};
const message='La llamada seleccionada del 27/10/2026 a las 10:23 figura como «Cancelada», sin conexión confirmada.';
const call={pbx_call_id:'pbx-cancelada-fixture',extension:'150',numero:'Número de prueba',fecha:'2026-10-27 10:23:35',finalizada:true,conectada:false,estado_pbx:'Cancelada',registro:null};
const location={href:'https://crm.example.test/index.php',origin:'https://crm.example.test',replace(){replaced=true;}};
let requestBody;
const window={IMPE_CURRENT_USER_ID:8,addEventListener(){},IMPE_TELEPHONY_NOTIFY(){}};window.parent=window;
const bootstrap={Modal:{getOrCreateInstance:()=>({show(){shown++;}}),getInstance:()=>({hide(){hidden++;}})}};window.bootstrap=bootstrap;
const sandbox={window,document:{hidden:false,querySelector:()=>root,getElementById:()=>modal},location,bootstrap,
 URL,URLSearchParams,AbortController,CustomEvent:class{},setInterval:()=>1,clearInterval(){},setTimeout,clearTimeout,
 sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},
 FormData:class{*[Symbol.iterator](){for(const el of elements)yield[el.name,el.value];}},
 fetch:async(url,options)=>{
  if(options?.method==='POST'){requestBody=options.body;return{ok:false,status:409,json:async()=>({ok:false,mensaje:message})};}
  return{ok:true,json:async()=>({ok:true,llamada:call})};
 }
};
(async()=>{
 vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../public/javascript/telefonia_ventas_resultados.js'),'utf8'),sandbox);
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(nodes['[data-sales-form-state]'].textContent,'27/10/2026 · 10:23 · Cancelada · Ext. 150');
 assert.equal(elements.pbx_call_id.value,call.pbx_call_id);assert.equal(elements.extension.value,'150');
 elements.observacion.value='Captura que debe conservarse';elements.resultado.value='INFORMACION_ENVIADA_WHATSAPP';
 await callbacks.submit({preventDefault(){}});
 assert.equal(requestBody.get('pbx_call_id'),call.pbx_call_id);assert.equal(requestBody.get('extension'),'150');
 assert.equal(nodes['[data-sales-feedback]'].textContent,message);
 assert.equal(elements.observacion.value,'Captura que debe conservarse');assert.equal(hidden,0);assert.equal(replaced,false);
 assert.equal(nodes['[data-sales-submit]'].disabled,false);assert.equal(storage.size,1);
 console.log('PASS: módulo real identifica fecha/hora/estado y conserva referencia, formulario y borrador al rechazar el guardado.');
})().catch(error=>{console.error(error);process.exitCode=1;});
