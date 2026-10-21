const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),http=require('node:http');
const factory=require('./telefonia_microfono_smoke.cjs');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{
 const wav=path.join(os.tmpdir(),'impe-mute-'+process.pid+'.wav');
 const samples=48000*20,buffer=Buffer.alloc(44+samples*2);buffer.write('RIFF');buffer.writeUInt32LE(buffer.length-8,4);buffer.write('WAVEfmt ',8);buffer.writeUInt32LE(16,16);buffer.writeUInt16LE(1,20);buffer.writeUInt16LE(1,22);buffer.writeUInt32LE(48000,24);buffer.writeUInt32LE(96000,28);buffer.writeUInt16LE(2,32);buffer.writeUInt16LE(16,34);buffer.write('data',36);buffer.writeUInt32LE(samples*2,40);for(let i=0;i<samples;i++)buffer.writeInt16LE(Math.round(12000*Math.sin(2*Math.PI*440*i/48000)),44+i*2);fs.writeFileSync(wav,buffer);
 const server=http.createServer((req,res)=>res.end('<!doctype html><title>Prueba local de micrófono</title>'));
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 let browser;
 try {
  browser=await chromium.launch({headless:true,args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-audio-capture='+wav],...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
  const page=await browser.newPage();
  await page.goto('http://127.0.0.1:'+server.address().port);
  const result=await page.evaluate(async factory=>{
   const audio=new AudioContext();await audio.resume();
   const a=new RTCPeerConnection(),b=new RTCPeerConnection();
   const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
   const receivers={};
   const receive=(key,e)=>{receivers[key]=e.streams[0];const player=document.createElement('audio');player.autoplay=true;player.muted=true;player.srcObject=e.streams[0];document.body.append(player);void player.play();};
   a.ontrack=e=>receive('a',e);b.ontrack=e=>receive('b',e);
   a.onicecandidate=e=>{if(e.candidate)b.addIceCandidate(e.candidate).catch(()=>{});};
   b.onicecandidate=e=>{if(e.candidate)a.addIceCandidate(e.candidate).catch(()=>{});};
   const streams=[];
   for(const pc of [a,b]){const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:false,noiseSuppression:false,autoGainControl:false}});streams.push(stream);pc.addTrack(stream.getAudioTracks()[0],stream);}
   await a.setLocalDescription(await a.createOffer());await b.setRemoteDescription(a.localDescription);
   await b.setLocalDescription(await b.createAnswer());await a.setRemoteDescription(b.localDescription);
   for(let i=0;i<100&&(!receivers.a||!receivers.b||a.connectionState!=='connected');i++)await sleep(50);
   if(a.connectionState!=='connected'||!receivers.a||!receivers.b)throw new Error('No se conectaron los pares WebRTC locales');
   const meter=stream=>{const analyser=audio.createAnalyser(),sink=audio.createGain();analyser.fftSize=2048;sink.gain.value=0;audio.createMediaStreamSource(stream).connect(analyser);analyser.connect(sink);sink.connect(audio.destination);return analyser;};
   const incoming=meter(receivers.a),outgoing=meter(receivers.b),local=meter(streams[0]);
   const rms=node=>{const data=new Float32Array(node.fftSize);node.getFloatTimeDomainData(data);return Math.sqrt(data.reduce((sum,x)=>sum+x*x,0)/data.length);};
   const c=(0,eval)(factory)({zdrmWebPhone:{webCallSession:{connection:a,isEnded:()=>false}}},{active:true,phase:'in-progress',callToken:'rtc-real'});
   try{
    await sleep(2000);const before={sent:rms(outgoing),heard:rms(incoming),local:rms(local),time:audio.currentTime,context:audio.state,enabled:a.getSenders()[0].track.enabled};
    c.setMuted(true);await sleep(700);const muted={sent:rms(outgoing),heard:rms(incoming),state:c.state.muted};
    c.setMuted(false);await sleep(500);const restored={sent:rms(outgoing),heard:rms(incoming),state:c.state.muted};
    c.setMuted(true);c.update({active:false});
    const stats=[];for(const pc of [a,b])for(const s of (await pc.getStats()).values())if(s.type==='inbound-rtp')stats.push({bytes:s.bytesReceived,energy:s.totalAudioEnergy});
    return {before,muted,restored,stats,reset:!c.state.muted&&a.getSenders()[0].track.enabled};
   }finally{streams.forEach(s=>s.getTracks().forEach(t=>t.stop()));a.close();b.close();await audio.close();}
  },factory);
  assert(result.before.sent>.01&&result.before.heard>.01,JSON.stringify(result));
  assert(result.muted.sent<.001&&result.muted.heard>.01&&result.muted.state,JSON.stringify(result));
  assert(result.restored.sent>.01&&result.restored.heard>.01&&!result.restored.state&&result.reset,JSON.stringify(result));
  console.log('WebRTC real local: al silenciar no se transmite audio, se sigue recibiendo, reactivación y fin correctos.');
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));fs.unlinkSync(wav);}
})().catch(error=>{console.error(error);process.exitCode=1;});
