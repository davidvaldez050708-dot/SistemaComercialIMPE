// Verificación aislada de notificaciones WebAudio, sin llamadas a Zadarma.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/javascript/telefonia_disponibilidad.js','utf8');
const start=source.indexOf(" const soundKey='impe:availability-chimes:'");
const stop=source.indexOf(" const unlockAudio=event=>",start);
assert(start>0&&stop>start,'Se localiza sólo el generador de avisos');
const script=source.slice(start,stop)+
 '\nthis.soundTest={stopSound,playSound,signalAvailability,setAudio(value){audio=value;},getSoundRequest(){return soundRequest;}};';
let time=1000000,hidden=false,active=false,pending=false,ready=true;
const tones=[],starts=[],stops=[],gains=[],storage=new Map();
const fakeAudio={
 state:'running',currentTime:0,destination:{},
 createOscillator:()=>({
  frequency:{set value(n){tones.push(n)}},type:'',
  connect(){},disconnect(){},start(at){starts.push(at)},
  stop(at){stops.push(at)}
 }),
 createGain:()=>({
  gain:{setValueAtTime(){},linearRampToValueAtTime(value){if(value>0)gains.push(value)}},
  connect(){}
 })
};
const env={
 cfg:{userId:8},phone:{getPhoneStatus:()=>({ready,active,pending})},
 document:{get hidden(){return hidden}},
 Date:{now:()=>time},
 sessionStorage:{
  getItem:k=>storage.get(k)||null,
  setItem:(k,v)=>storage.set(k,v)
 },
 setTimeout:()=>1,clearTimeout:()=>{},setInterval:()=>1,clearInterval:()=>{},unlockAudio:()=>{}
};
vm.runInNewContext(script,env);
const a=env.soundTest,call=(id=1,seconds=45)=>({id,version:1,segundos:seconds});
// Contrato de audio de la versión instalada: dos notas y recordatorios cada 8 s.
a.signalAvailability(call());
assert.equal(starts.length,0,'Sin contexto de audio activo no emite');
a.setAudio(fakeAudio);a.playSound();
assert.deepEqual(tones,[740,990],'Melodía instalada de dos notas');
assert.equal(starts.length,2);assert(gains.every(v=>v<=0.13));
for(let i=0;i<7;i++)a.signalAvailability(call());
assert.equal(starts.length,2,'El polling no repite inmediatamente');
time+=7000;a.signalAvailability(call());assert.equal(starts.length,2);
time+=1100;a.signalAvailability(call());assert.equal(starts.length,4,'Recordatorio al cumplir ocho segundos');
time+=1000;a.signalAvailability(call());assert.equal(starts.length,4);
time+=7100;a.signalAvailability(call());assert.equal(starts.length,6,'Recordatorios mientras siga pendiente');
a.stopSound();a.signalAvailability(call());assert.equal(starts.length,6,'Misma solicitud recuperada sin repetición inmediata');
active=true;a.signalAvailability(call(2));a.playSound();assert.equal(starts.length,6,'Una conversación activa silencia avisos');
active=false;hidden=true;a.playSound();assert.equal(starts.length,8,'Se conserva el aviso en segundo plano');
hidden=false;ready=false;time+=8100;a.playSound();assert.equal(starts.length,8,'Teléfono desconectado no emite');
ready=true;pending=true;a.playSound();assert.equal(starts.length,8,'No interrumpe captura pendiente con recordatorio');
pending=false;a.playSound();assert.equal(starts.length,10);
a.stopSound();a.signalAvailability(call(3,1));a.playSound();assert.equal(starts.length,12);
time+=1100;a.playSound();assert.equal(starts.length,12,'No emitir después de vencida');
a.stopSound();assert(!a.getSoundRequest());
console.log('Aviso instalado: dos notas, frecuencia mínima, conversación/resultado pendiente, segundo plano, vencimiento e idempotencia OK');
