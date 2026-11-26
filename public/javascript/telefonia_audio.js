/* Un único tono de espera en el motor compartido por todos los módulos. */
(() => {
    'use strict';
    let context, state, timer, gain, oscillators=[], stream, source, analyser;
    let audibleUntil=0, started=0;
    const stop=()=>{
        oscillators.forEach(o=>{try{o.stop();o.disconnect();}catch(_){}});
        oscillators=[];gain?.disconnect();gain=null;
    };
    const unlock=()=>{
        try {
            const Audio=window.AudioContext||window.webkitAudioContext;
            if(!Audio)return;
            context ||= new Audio();
            if(context.state==='suspended')void context.resume().catch(()=>{});
        }catch(_){}
    };
    const sample=()=>{
        const session=window.zdrmWebPhone?.webCallSession;
        const nativeIncoming=document.getElementById('zdrm-incomingRing');
        if(nativeIncoming)nativeIncoming.volume=1;
        const nativeOutgoing=document.getElementById('zdrm-outgoingRing');
        const nativePlaying=nativeOutgoing && !nativeOutgoing.paused && !nativeOutgoing.ended && nativeOutgoing.volume>0 && !nativeOutgoing.muted;
        const eligible=state?.active && state.direction==='outgoing' &&
            state.phase==='ringing' && !nativePlaying && !session?.isEnded?.();
        if(!eligible || context?.state!=='running'){stop();return;}
        // Si la operadora ya envía voz, locución o timbrado, no taparlos
        // con el tono local. Sólo analizar; el SDK mantiene su propio audio.
        try {
            const pc=session?.connection;
            const track=pc?.getReceivers?.().map(r=>r.track).find(t=>t?.kind==='audio'&&t.readyState==='live');
            if(track && stream?.getAudioTracks()[0]!==track) {
                source?.disconnect();analyser?.disconnect();
                stream=new MediaStream([track]);source=context.createMediaStreamSource(stream);
                analyser=context.createAnalyser();analyser.fftSize=256;source.connect(analyser);
            }
            if(analyser){
                const samples=new Uint8Array(analyser.fftSize);analyser.getByteTimeDomainData(samples);
                if(samples.some(v=>Math.abs(v-128)>4))audibleUntil=Date.now()+1800;
            }
        }catch(_){}
        if(Date.now()<audibleUntil){stop();return;}
        if(!gain){
            gain=context.createGain();gain.connect(context.destination);
            for(const frequency of [440,480]) {
                const o=context.createOscillator();o.frequency.value=frequency;o.connect(gain);o.start();oscillators.push(o);
            }
            started=Date.now();
        }
        gain.gain.setTargetAtTime((Date.now()-started)%6000<2000?0.035:0,context.currentTime,0.02);
    };
    window.IMPE_CALL_AUDIO={unlock,getStatus:()=>context?.state || 'uninitialized',update(value){
        if(state?.callToken!==value.callToken){audibleUntil=0;source?.disconnect();analyser?.disconnect();source=analyser=stream=null;stop();}
        state=value;
        if(!timer)timer=window.setInterval(sample,100);
        sample();
    },stop};
    window.addEventListener('pagehide',()=>{stop();window.clearInterval(timer);void context?.close();});
})();
