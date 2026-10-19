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
 setTimeout:()=>1,clearTimeout:()=>{}
};
vm.runInNewContext(script,env);
const a=env.soundTest,call=(id=1,seconds=45)=>({id,version:1,segundos:seconds});
a.signalAvailability(call());
assert.equal(starts.length,0,'No se reproduce antes de desbloquear WebAudio');
a.setAudio(fakeAudio);
a.playSound();
assert.deepEqual(tones,[740,880,988],'Alerta distinguible de tres notas');
assert.equal(starts.length,3);
assert(gains.every(v=>v<=0.17),'Volumen moderado');
for(let i=0;i<7;i++)a.signalAvailability(call());
assert.equal(starts.length,3,'Sin repeticiones por polling');
time+=17000;a.signalAvailability(call());
assert.equal(starts.length,3,'Sin recordatorio prematuro');
time+=1100;a.signalAvailability(call());
assert.deepEqual(tones,[740,880,988,740,880],'Único recordatorio más discreto');
assert.equal(starts.length,5);
assert(gains.at(-1)<=0.11,'Recordatorio más tenue');
time+=1000;a.signalAvailability(call());
assert.equal(starts.length,5,'A lo sumo un recordatorio');
a.stopSound();
a.signalAvailability(call());
assert.equal(starts.length,5,'La misma solicitud no repite audio tras recuperar la vista');
active=true;a.signalAvailability(call(2));
a.playSound();
assert.equal(starts.length,5,'Una llamada activa silencia aviso');
active=false;hidden=true;a.playSound();
assert.equal(starts.length,5,'Pestaña oculta no emite audio');
hidden=false;ready=false;a.playSound();
assert.equal(starts.length,5,'Teléfono desconectado no emite audio');
ready=true;a.playSound();
assert.equal(starts.length,8,'Al volver a estar listo conserva la solicitud');
pending=true;time+=19000;a.playSound();
assert.equal(starts.length,8,'No interrumpe el registro de un resultado previo');
pending=false;a.playSound();
assert.equal(starts.length,10,'Único recordatorio al quedar libre');
a.stopSound();
a.signalAvailability(call(3,26));a.playSound();
assert.equal(starts.length,13);
time+=18050; a.signalAvailability(call(3,8));
assert.equal(starts.length,13,'No insistir cuando quedan menos de 10 segundos');
time+=9000;a.signalAvailability(call(3,0));
assert.equal(starts.length,13,'No reproducir tras vencimiento');
a.stopSound();
assert(!a.getSoundRequest(),'Responder o vencer elimina aviso activo');
console.log('Aviso sonoro: consentimiento, notas, recordatorio único, ocupado, pendiente, oculto, vencimiento e idempotencia OK');
