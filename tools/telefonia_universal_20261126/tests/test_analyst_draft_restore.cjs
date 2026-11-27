const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/seguimiento_entrantes_analista.js','utf8');
const start=source.indexOf('        const restore=async()=>{'),end=source.indexOf('\n        void restore();',start);
const loaderStart=source.indexOf('    const loadVerificationContext = id => {');
const loaderEnd=source.indexOf('\n    const verificationApplicable',loaderStart);
assert(start>0&&end>start&&loaderStart>0&&loaderEnd>loaderStart);
const deferred=()=>{let resolve;return {promise:new Promise(r=>resolve=r),resolve};};
const turn=()=>new Promise(setImmediate);
const names=['verificacion_telefono_confirmado','verificacion_correo_confirmado','verificacion_contacto_confirmado'];
let checks=0;
function fixture(values,allowed=true){
 const wait=deferred();let queries=0;
 const fields={decision:{name:'decision',type:'select-one',value:'existente'},resultado:{name:'resultado',type:'select-one',value:''},
  seguimiento_id:{name:'seguimiento_id',type:'select-one',value:'',options:[],add(option){this.options.push(option);}},
  nuevo_telefono_contacto:{name:'nuevo_telefono_contacto',value:''},nuevo_correo_contacto:{name:'nuevo_correo_contacto',value:''},
  observacion:{name:'observacion',type:'textarea',value:''}};
 for(const name of names)fields[name]={name,type:'checkbox',checked:false,disabled:true};
 const box={userId:42,callRefs:['fixture-analyst-session'],cycle:1,modalCycle:1,formDirty:false,
  drafts:{read:async()=>({...values}),resultValues:v=>v},form:{elements:{...fields,namedItem:name=>fields[name]||null}},
  decision:fields.decision,followup:fields.seguimiento_id,resultInput:fields.resultado,evidence:{hidden:true},
  preparingResult:false,outgoingReady:true,hasAnswer:true,draftStatus:{},allowed:false,
  Option:class Option{constructor(label,value){this.label=label;this.value=value;}},
  loadVerificationContext:()=>{queries++;return wait.promise.then(()=>{box.allowed=allowed;});},
  renderDecision(){box.evidence.hidden=!box.allowed;for(const name of names){fields[name].disabled=!box.allowed;fields[name].checked=false;}},loadMunicipalities(){}};
 vm.createContext(box);vm.runInContext(source.slice(start,end)+';pending=restore();',box);
 return {box,fields,wait,queries:()=>queries};
}
(async()=>{
 for(const selected of [[],[0],[0,1]]){
  const values={decision:'existente',seguimiento_id:'901',resultado:'CONTACTO_CORRECTO',observacion:'Preservar captura'};
  names.forEach((name,i)=>values[name]=selected.includes(i)?'1':'0');
  const f=fixture(values,true);await turn();
  if(selected.length){assert.equal(f.queries(),1);assert.equal(f.fields[names[0]].checked,false);checks+=2;f.wait.resolve();}
  else{f.wait.resolve();}
  await f.box.pending;
  for(let i=0;i<names.length;i++){assert.equal(f.fields[names[i]].checked,selected.includes(i));checks++;}
  assert.equal(f.fields.observacion.value,'Preservar captura');checks++;
 }
 const saved={decision:'existente',seguimiento_id:'901',resultado:'CONTACTO_CORRECTO',[names[0]]:'1'};
 const inapplicable=fixture(saved,false);await turn();inapplicable.wait.resolve();await inapplicable.box.pending;
 assert.equal(inapplicable.fields[names[0]].checked,false);assert.equal(inapplicable.fields[names[0]].disabled,true);checks+=2;
 for(const changed of ['cycle','dirty','institution','result']){
  const f=fixture(saved,true);await turn();
  if(changed==='cycle')f.box.modalCycle++;
  if(changed==='dirty'){f.box.formDirty=true;f.fields.observacion.value='New human edit';}
  if(changed==='institution')f.fields.seguimiento_id.value='902';
  if(changed==='result')f.fields.resultado.value='SIN_RESPUESTA';
  f.wait.resolve();await f.box.pending;
  assert.equal(f.fields[names[0]].checked,false);checks++;
  if(changed==='dirty'){assert.equal(f.fields.observacion.value,'New human edit');checks++;}
 }
 // The actual context loader exposes its in-flight promise, avoiding a second
 // request and letting restoration wait for the same metadata render uses.
 const gate=deferred();let requests=0,renders=0;
 const b={verificationContexts:new Map(),verificationRequests:new Map(),modalCycle:1,searchUrl:'/search',
  window:{location:{href:'http://fixture.test/'}},URL,decision:{value:'existente'},followup:{value:'901'},isSubmitting:false,
  fetchBounded:()=>{requests++;return gate.promise;},renderEvidence:()=>renders++,showError(){throw Error('Unexpected context error');}};
 vm.createContext(b);vm.runInContext(source.slice(loaderStart,loaderEnd)+';load=loadVerificationContext;',b);
 const one=b.load('901'),two=b.load('901');assert.equal(one,two);assert.equal(requests,1);checks+=2;
 gate.resolve({response:{ok:true},data:{ok:true,resultados:[{id:901,datos_verificados:0}]}});await one;
 assert.equal(renders,1);assert.equal(b.verificationContexts.get('901').datos_verificados,0);checks+=2;
 console.log(`PASS ${checks} actual Analyst draft restoration assertions: zero/one/two confirmations, metadata await/coalescing, inapplicability, stale cycle and human edit protection. Adapter eligibility mocked; browser regression tests the real rules.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
