const assert=require('node:assert/strict');
global.location={href:'http://phone.test/'};
const {create}=require('../public/javascript/telefonia_transferencia_runtime.js');
(async()=>{
 let confirmed=false,adopted=0;
 const state={active:true,direction:'incoming',destination:'+527777885173',callToken:'sdk-current-live-123456',context:{availabilityCandidate:91}};
 global.zdrmWebPhone={webCallSession:{direction:'incoming',isEnded:()=>false,isEstablished:()=>true}};
 global.fetch=async(url,options)=>{const u=new URL(url);if(!u.searchParams.has('id')){assert.equal(u.searchParams.get('solicitud_id'),'91');assert.equal(u.searchParams.get('sdk'),'sdk-current-live-123456');}
  return {ok:true,json:async()=>({ok:true,operacion:confirmed?{id:17,estado:'ENTREGADA',modo:'DIRECTA',sdk_consulta:'sdk-current-live-123456',referencia:'pbx-exact-sales',origen_extension:'100',numero:'+527777885173'}:null})};};
 const runtime=create({url:'/op',csrf:'test',read:()=>state,publish:p=>Object.assign(state,p),external(op,token,connected){assert(connected);adopted++;state.callToken=token;state.context={transferReference:op.referencia};}});
 await runtime.tick();assert.equal(adopted,0,'Disponibilidad sin respuesta PBX no adopta referencia');assert.equal(state.context.transferReference,undefined);
 confirmed=true;await runtime.tick();assert.equal(adopted,1);assert.equal(state.context.transferReference,'pbx-exact-sales');
 console.log('Transferencia saliente: número externo + solicitud exacta + respuesta propia → vínculo PBX; oferta sola no basta: OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
