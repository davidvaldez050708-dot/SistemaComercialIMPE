const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const settle = async () => { for (let i=0;i<8;i++) await new Promise(setImmediate); };

function fixture(file) {
    const events={}, intervals=[], pending=[], calls=[];
    const content={innerHTML:''}, badge={classList:{add(){},remove(){}}};
    const markAll={disabled:false,addEventListener(name,fn){this[name]=fn;}};
    const root={dataset:{endpoint:'/pending',readEndpoint:'/read',readAllEndpoint:'/read-all',csrfToken:'csrf-test'},
        querySelector(sel){return sel.includes('content')?content:sel.includes('badge')?badge:markAll;},addEventListener(){}};
    const window={location:{href:'https://test.invalid/'},addEventListener(name,fn){events[name]=fn;},
        setInterval(fn){intervals.push(fn);return 1;},setTimeout(){return 1;},clearTimeout(){}};
    const context={window,document:{hidden:true,querySelector(){return root;},addEventListener(_,fn){events.ready=fn;}},
        sessionStorage:{getItem(){return null;},setItem(){}},URLSearchParams,Intl,Date,Map,Set,Promise,
        fetch(url,options={}) {calls.push({url,options});return new Promise((resolve,reject)=>pending.push({url,resolve,reject}));}};
    vm.runInNewContext(fs.readFileSync(file,'utf8'),context,{filename:file}); events.ready();
    const finish=(item,body={ok:true,no_leidas:0,notificaciones:[]},status=200)=>item.resolve({ok:status<400,status,json:async()=>body});
    return {events,intervals,pending,calls,markAll,finish};
}

(async()=>{
    const merged=process.argv[2]||path.join(__dirname,'../public/javascript/convocatoria_notificaciones.js');
    const f=fixture(merged);await settle();
    assert.equal(f.calls[0].options.headers['X-Requested-With'],'fetch');
    const marking=f.markAll.click({preventDefault(){}});await settle();
    await f.markAll.click({preventDefault(){}});await settle();
    assert.equal(f.calls.filter(x=>x.options.method==='POST').length,1);
    assert.equal(f.calls[1].options.body.get('csrf_token'),'csrf-test');
    f.finish(f.pending[1]);await settle();
    assert.equal(f.calls.length,2,'No solapar lectura pendiente con una nueva');
    f.finish(f.pending[0]);await settle();
    assert.equal(f.calls.length,3,'Releer despuÃ©s de completar la escritura');
    f.finish(f.pending[2]);await marking;await settle();assert.equal(f.markAll.disabled,false);
    f.intervals[0]();await settle();f.pending[3].reject(new Error('offline'));await settle();
    assert.equal(f.calls.length,4,'No reintentar automÃ¡ticamente');
    f.intervals[0]();await settle();assert.equal(f.calls.length,5,'Liberar guarda despuÃ©s del error');
    f.finish(f.pending[4]);await settle();
    console.log('PASS notifications: coalescing 3->1, CSRF, POST once, fresh read after mutation, recovery without retry');
})().catch(e=>{console.error(e);process.exitCode=1;});
