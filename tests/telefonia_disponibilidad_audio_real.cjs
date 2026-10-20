const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const source=fs.readFileSync('public/javascript/telefonia_disponibilidad.js','utf8');
const html='<dialog data-availability-incoming><h2>Disponibilidad</h2><p data-availability-caller></p><p data-availability-countdown></p><p data-availability-feedback></p><button data-availability-audio hidden>Activar avisos sonoros</button><button data-availability-pending hidden>Pendiente</button><button data-availability-accept>Aceptar</button><button data-availability-decline>Rechazar</button></dialog>';
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{for(const sales of [false,true]){
  const page=await browser.newPage();let op={id:1,version:1,estado:'PENDIENTE',segundos:45,origen_extension:'100',numero:'5551234567'},nextId=1;
  await page.route('http://disponibilidad.test/**',route=>{
   if(new URL(route.request().url()).pathname==='/api'){
    const command=new URLSearchParams(route.request().postData()).get('accion');
    if(command==='ACEPTAR'||command==='RECHAZAR')op=null;
    return route.fulfill({json:{ok:true,entrante:op,solicitud:{estado:command==='ACEPTAR'?'ACEPTADA':'RECHAZADA',segundos:30},devoluciones:0}});
   }return route.fulfill({contentType:'text/html',body:html});
  });
  await page.goto('http://disponibilidad.test/');
  await page.evaluate(sales=>{
   const Native=window.AudioContext;
   window.probe={contexts:[],meters:[],tones:0,stops:0,max:0};
   window.AudioContext=class extends Native{
    constructor(options){super(options);probe.contexts.push(this);}
    createOscillator(){const node=super.createOscillator(),start=node.start.bind(node),stop=node.stop.bind(node);node.start=(...args)=>{probe.tones++;return start(...args);};node.stop=(...args)=>{probe.stops++;return stop(...args);};return node;}
    createGain(){const node=super.createGain(),meter=super.createAnalyser(),sink=super.createGain();sink.gain.value=0;node.connect(meter);meter.connect(sink);sink.connect(this.destination);probe.meters.push(meter);return node;}
   };
   window.IMPE_PHONE_SHELL_CONFIG={userId:sales?8:9,salesMode:sales,availabilityUrl:'/api',routingCsrf:'test'};
   window.IMPE_TELEPHONY_SHELL={getPhoneStatus:()=>({ready:true,active:false,pending:false}),getActiveCall:()=>null};
   setInterval(()=>{for(const meter of probe.meters){const values=new Float32Array(meter.fftSize);meter.getFloatTimeDomainData(values);probe.max=Math.max(probe.max,Math.sqrt(values.reduce((sum,x)=>sum+x*x,0)/values.length));}},20);
  },sales);
  await page.addScriptTag({content:source});await page.locator('dialog').waitFor({state:'visible'});
  assert.equal(await page.evaluate(()=>probe.tones),0,'Sin gesto no se genera audio');
  await page.locator('h2').click();await page.waitForFunction(()=>probe.max>.04);
  assert.equal(await page.evaluate(()=>probe.tones),3,'Melodía compartida audible en el grafo real');
  await page.addScriptTag({content:source});await page.evaluate(()=>IMPE_AVAILABILITY.tick());
  assert.equal(await page.evaluate(()=>probe.contexts.length),1);assert.equal(await page.evaluate(()=>probe.tones),3,'Polling/listeners no duplican');
  for(const action of ['accept','decline','expire']){
   if(action!=='accept'){op={id:++nextId,version:1,estado:'PENDIENTE',segundos:45,origen_extension:'100',numero:'5551234567'};await page.evaluate(()=>IMPE_AVAILABILITY.tick());await page.locator('dialog').waitFor({state:'visible'});}
   const stops=await page.evaluate(()=>probe.stops);
   if(action==='expire'){op=null;await page.evaluate(()=>IMPE_AVAILABILITY.tick());}else await page.locator('[data-availability-'+action+']').click();
   await page.locator('dialog').waitFor({state:'hidden'});assert(await page.evaluate(()=>probe.stops)>stops,'Aceptar/rechazar/vencer corta los nodos');
  }
  await page.close();
 }console.log('Disponibilidad Analista/Ventas: WebAudio real medido, gesto, contexto único y parada al aceptar/rechazar/vencer: OK');}
 finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
