const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_shell.js','utf8');
const start=source.indexOf('    const synchronize = function () {'),end=source.indexOf('\n    window.setInterval(synchronize',start);
assert(start>0&&end>start);let checks=0;
(async()=>{
 for(const owned of [false,true])for(const pending of [false,true])for(const authenticated of [false,true]){
  let saves=0,reads=0,leases=0,flushes=0;
  const box={cfg:{sesionLocalUrl:'/prueba_telefonia/api/sesion_local.php'},autoEnabled:false,hostFrame:null,owned,userId:42,writeOwner:()=>leases++,readState:()=>reads++,render(){},
   window:{IMPE_PHONE_HTTP:{csrfToken:()=>authenticated?'fresh-token':'',flushSessions:()=>{flushes++;return Promise.resolve();}},IMPE_CALL_DRAFTS:{pending:()=>pending?[{}]:[],sync:(user,token)=>{
    assert.equal(user,42);assert.equal(token,'fresh-token');saves++;return Promise.resolve();}}}};
  vm.runInNewContext(source.slice(start,end)+';synchronize();',box);
  assert.equal(saves,owned&&pending&&authenticated?1:0);assert.equal(reads,1);assert.equal(leases,owned?1:0);assert.equal(flushes,owned&&authenticated?1:0);checks+=4;
 }
 console.log(`PASS ${checks} actual shell pulse assertions: owned authenticated pending work only, no empty/standby requests, existing state and lease behavior preserved. No new timer or HTTP call in test.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
