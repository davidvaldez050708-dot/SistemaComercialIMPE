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
const tones=[],starts=[],stops=[],gains=[],storage=new Map(),intervals=new Map();let intervalId=0;
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
 unlockAudio:()=>{}, setTimeout:()=>1,clearTimeout:()=>{},setInterval:fn=>{intervals.set(++intervalId,fn);return intervalId;},clearInterval:id=>intervals.delete(id)
};
vm.runInNewContext(script,env);
const a=env.soundTest,call=(id=1,seconds=45)=>({id,version:1,segundos:seconds});
a.signalAvailability(call());
assert.equal(starts.length,0,'No se reproduce antes de desbloquear WebAudio');
a.setAudio(fakeAudio);
a.playSound();
assert.deepEqual(tones,[740,990],'Alerta distinguible de dos notas originales');
assert.equal(starts.length,2);
assert(gains.every(v=>v<=0.32),'Ganancia acotada sin saturación');
for(let i=0;i<7;i++)a.signalAvailability(call());
assert.equal(starts.length,2,'Sin repeticiones por polling');
time+=7000;a.signalAvailability(call());
assert.equal(starts.length,2,'Sin recordatorio prematuro');
time+=1100;a.signalAvailability(call());
assert.deepEqual(tones,[740,990,740,990],'Recordatorio más discreto');
assert.equal(starts.length,4);
assert(gains.at(-1)<=0.24,'Recordatorio más tenue');
time+=1000;a.signalAvailability(call());
assert.equal(starts.length,4,'Polling no duplica el recordatorio');
time+=7100;for(const tick of intervals.values())tick();
assert.equal(starts.length,6,'Continúa pendiente: el temporizador compartido repite sin polling');
a.stopSound();
assert.equal(intervals.size,0,'Responder elimina el temporizador de repetición');
a.signalAvailability(call());
assert.equal(starts.length,6,'La misma solicitud no repite audio inmediato tras recuperar la vista');
active=true;a.signalAvailability(call(2));
a.playSound();
assert.equal(starts.length,6,'Una llamada activa silencia aviso');
active=false;hidden=true;a.playSound();
assert.equal(starts.length,6,'Pestaña oculta no emite audio');
hidden=false;ready=false;a.playSound();
assert.equal(starts.length,6,'Teléfono desconectado no emite audio');
ready=true;a.playSound();
assert.equal(starts.length,8,'Al volver a estar listo conserva la solicitud');
pending=true;time+=19000;a.playSound();
assert.equal(starts.length,8,'No interrumpe el registro de un resultado previo');
pending=false;a.playSound();
assert.equal(starts.length,10,'Recordatorio al quedar libre');
a.stopSound();
a.signalAvailability(call(3,26));a.playSound();
assert.equal(starts.length,12);
time+=25050; a.signalAvailability(call(3,1));
assert.equal(starts.length,12,'No iniciar otra melodía en el último segundo');
time+=9000;a.signalAvailability(call(3,0));
assert.equal(starts.length,12,'No reproducir tras vencimiento');
a.stopSound();
assert(!a.getSoundRequest(),'Responder o vencer elimina aviso activo');
console.log('Aviso sonoro: consentimiento, ganancia, repetición única cada 8 s, ocupado, pendiente, oculto y vencimiento OK');
const unlockEnd=source.indexOf(" const audioButton=",stop);
const button={hidden:true};let resumes=0,sinks=0;
const interrupted={state:'interrupted',sinkId:{type:'none'},resume(){resumes++;this.state='running';return Promise.resolve();},setSinkId(id){assert.equal(id,'');sinks++;this.sinkId=id;return Promise.resolve();}};
const unlockEnv={...env,$:()=>button,audioButton:button,window:{AudioContext:class{}},Promise};
vm.runInNewContext(source.slice(start,unlockEnd)+'\nthis.unlockTest={unlockAudio,setAudio(value){audio=value;}};',unlockEnv);
unlockEnv.unlockTest.setAudio(interrupted);
unlockEnv.unlockTest.unlockAudio({isTrusted:false});assert.equal(resumes,0,'No desbloquear por eventos sintéticos');
unlockEnv.unlockTest.unlockAudio({isTrusted:true});
setImmediate(()=>{assert.equal(resumes,1);assert.equal(sinks,0,'Salida predeterminada original sin copiar dispositivos del SDK');assert.equal(button.hidden,true,'Acción adicional sólo si autoplay bloquea');console.log('AudioContext interrumpido, gesto confiable y salida predeterminada: OK');});
