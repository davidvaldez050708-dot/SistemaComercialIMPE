"""Empaqueta bytes Git y variantes de Hostinger verificadas el 24-oct-2026."""
import base64, gzip, hashlib, json, subprocess, sys, tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='c608387b41270ee014083c5810167dd1a8466323'
FILES=['public/javascript/telefonia_conexion.js','public/javascript/telefonia_host.js',
       'public/javascript/telefonia_shell.js','public/javascript/telefonia_persistente.js','public/javascript/telefonia_audio.js',
       'app/views/telefonia/host.php','app/views/telefonia/shell.php']
def blob(ref,p):return subprocess.check_output(['git','show',ref+':'+p],cwd=ROOT).decode('utf8').replace('\r\n','\n')
def sha(b):return hashlib.sha256(b).hexdigest()
def packed(b):return base64.b64encode(gzip.compress(b,mtime=0)).decode('ascii')
def replace(s,a,b):
    assert s.count(a)==1,(a[:80],s.count(a))
    return s.replace(a,b)
def variants(path,before,after):
    if path.endswith('telefonia_shell.js'):
        start=before.index('        getCallState: function () {')
        end=before.index('        getReceptionStatus:',start)
        old=before[start:end]
        simple='        getCallState: function () {return freshState(currentState) ? JSON.parse(JSON.stringify(currentState)) : null;},\n'
        comment='        // Abrir el formulario NO significa que MySQL haya guardado el resultado.\n        // Conservar el pendiente hasta la confirmación IMPE_ANALYST_RESULT_SAVED\n        // emitida por el iframe después del guardado real.'
        custom='        // No descartar resultado antes de confirmación SQL.'
        src=replace(replace(before,old,simple),comment,custom)
        dst=replace(replace(after,old,simple),comment,custom)
        assert sha(src.encode())=='ee62fbc32cd49df134799a918b8c05c81aca83bc85e8f5f3073206a8acda6e53'
        return [(src,dst)]
    if path.endswith('telefonia_host.js'):
        anchor='            const requestToken=state.callToken;\n            const call =\n                await fetchIncomingState();'
        rescue="            // Rescate seguro de una señal onConnected perdida: sólo cuando el\n            // SDK ya observó audio local y el host sigue esperando respuesta.\n            // No inferir conversación a partir de segundos ni de un ringing PBX.\n            if (state.active && state.direction === 'incoming' &&\n                state.phase === 'answering' &&\n                analystSdkObserver?.isConnected?.()) {\n                answeredAtMs = Date.now();\n                publish({\n                    phase:'in-progress',status:'in-progress',\n                    answeredAtMs, duration:0, message:'',\n                    context:Object.assign({}, state.context, {\n                        sdkConnected:true,source:'SDK_WEBRTC',\n                        verified:Boolean(state.context?.pbxAnswered)\n                    })\n                });\n            }\n"
        src=replace(before,"state.phase === 'incoming-ringing') return 0;","['incoming-ringing', 'answering'].includes(state.phase)) return 0;")
        src=replace(src,anchor,rescue+anchor)
        a=after.index('            // Hostinger: recuperar onConnected perdido')
        b=after.index(anchor,a)
        dst=after[:a]+rescue+after[b:]
        assert sha(src.encode())=='8632440e8bf22d7f424caf5f2ebef6d65563eef49fdd9e88ef63647f56f2ad43'
        return [(src,dst)]
    if path.endswith('telefonia_persistente.js'):
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/path;p.parent.mkdir(parents=True)
            p.write_bytes(blob('59d5eb6656a7a0a2b6b5ff16db9ff4c691c3bc34',path).encode())
            for patch in ['hostinger_persistente_20261019.patch','03.patch']:
                subprocess.run(['git','-c','core.autocrlf=false','apply','-'],cwd=tmp,
                    input=(ROOT/'tools/ventas_fase_a'/patch).read_bytes().replace(b'\r\n',b'\n'),check=True)
            src=p.read_text(encoding='utf8')
        assert sha(src.encode())=='b9649d1f21090da917eadc0f74b2933478e8ed5227aff09f58d9f6e413095ca1'
        dst=replace(src,'state?.hostReady &&\n            Date.now() - hostSeenAt < 3500;',
                    'state?.hostReady && state?.connection?.verified === true &&\n            Date.now() - hostSeenAt < 15000;')
        return [(src,dst)]
    return []
def main():
    target=sys.argv[1];rows=[]
    for path in FILES:
        after=blob(target,path)
        if path.endswith('telefonia_conexion.js'):
            pairs=[(None,after)]
        else:
            before=blob(BASE,path);pairs=[(before,after)]+variants(path,before,after)
        rows.append({'path':path,'variants':[{'before':packed(a.encode()) if a is not None else None,
            'before_sha':sha(a.encode()) if a is not None else None,
            'after':packed(b.encode()),'after_sha':sha(b.encode())} for a,b in pairs]})
    out={'version':1,'base':BASE,'target':target,'files':rows}
    (ROOT/'tools/telefonia_conexion_release.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
    print('Paquete: 7 archivos, fuentes Git y tres variantes Hostinger verificadas.')
if __name__=='__main__':main()
