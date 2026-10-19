// HTTP real contra el validador del controlador. Identidades de sesión de fixture, sin base real.
const cp=require('node:child_process'),net=require('node:net'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const server=cp.spawn('php',['-S','127.0.0.1:'+port,'-t',path.resolve(__dirname,'..')],{stdio:'ignore'});
 const url='http://127.0.0.1:'+port+'/tests/fixtures/telefonia_ventas_guard.php';
 try{for(let n=0;n<40;n++){try{await fetch(url);break;}catch(_){await new Promise(r=>setTimeout(r,100));}}
  let count=0;
  for(const [query,method,token,status]of [
   ['caso=sin_sesion','POST','b'.repeat(64),403],['caso=otro_rol','POST','b'.repeat(64),403],['caso=sin_permiso','POST','b'.repeat(64),403],
   ['','GET','',405],['','PUT','b'.repeat(64),405],['','POST','',403],['','POST','incorrecto',403],
   ['','POST','b'.repeat(64),200],['','POST','a'.repeat(64),200],['lectura=1','GET','',200],['lectura=1','POST','b'.repeat(64),405]
  ]){const r=await fetch(url+'?'+query,{method,...(method==='GET'?{}:{body:new URLSearchParams({csrf_token:token})})});assert.equal(r.status,status,query+' '+method+' '+await r.text());count++;}
  console.log('Ventas HTTP: '+count+' casos de sesión, rol, permiso, método y CSRF: OK');
 }finally{server.kill();}
})().catch(e=>{console.error(e);process.exitCode=1;});
