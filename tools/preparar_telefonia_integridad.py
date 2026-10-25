"""Paquete selectivo con fuentes Git y variantes Hostinger verificadas."""
import base64,difflib,gzip,hashlib,json,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='f070a51bcc0a7a6cbcb06ee68aa0f2faeb1dce53'
FILES=['app/services/TelefoniaMisLlamadasService.php',
 'app/services/TelefoniaGrabacionesTransferenciaVentasService.php',
 'app/views/telefonia/index.php','app/views/telefonia/mis_llamadas.php',
 'app/views/telefonia/recepcion_marketing.php','app/views/telefonia/ventas_llamadas.php',
 'public/javascript/telefonia_host.js','public/javascript/telefonia_persistente.js',
 'public/javascript/telefonia_entrantes_bridge.js','public/javascript/telefonia_webrtc_dtmf.js',
 'public/javascript/telefonia_ventas_resultados.js','app/services/TelefoniaResultadoVentasService.php']
EXPECTED=['21e564cc74de30e393a7533aeb36209a39cbb90eb5c8c16697a222e8ca562602',
 '570a5fac6ee8f7885ae3b6f082dccedb2a5b67c934caa05a91686402e1133059',
 '9c6fb22d71e4d3785e229e480d0b964d8b9caa42ee6450a7ed472c3de5c0e860',
 '5dc3cf5bb33b5f3375272a979f4cb564006118ada7c1e57edd3d66b9de3061b8',
 'e1dd65599476bc0c069feedd10b29399e5b6b9b59774cf25dd2ad9c15112d17f',
 'a82b55b776601f9e580f23cdee09e49f0760cbbc16dc6ad500bfc206d20132f3',
 '31bf3305679bcd82e75188abf5eab62e2716c5569a4b303c34a17c8a1e1223f9',
 '076637749912423eb60f8c79245258f5dddb882fa093b89823d18e9c344ed24d',
 'ecc63227f4cbe5dc4f76ed40fa8f890546cce63dfcebeaf1d9114e410acda6ad',
 '819a0020fb91be8d543d18a9fb6cb7e50cc5d8ef015df1ff8addf5410dfe6fbc',
 '34a798dd42fcaf2c950a9d14039c42dc59f860d2431379d34ddd21a06565ab87',
 'e841cb77858e13f6b11d3705e8bc61708ca6f057557979870458589a320b33d2']
def blob(ref,path):return subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT)
def sha(b):return hashlib.sha256(b).hexdigest()
def packed(b):return base64.b64encode(gzip.compress(b,mtime=0)).decode('ascii')
def delta(custom,before,after):
    a=before.splitlines(True);b=after.splitlines(True)
    for kind,i,j,k,l in reversed(difflib.SequenceMatcher(None,a,b,autojunk=False).get_opcodes()):
        if kind=='equal':continue
        old=''.join(a[i:j]);new=''.join(b[k:l])
        # La variante instalada no tiene este bloque del renderer canónico.
        # No añadirle controles ni comportamiento al traducir sus mensajes.
        if old not in custom and 'WebRTC aún no tiene los controles preparados.' in old:
            continue
        assert old and custom.count(old)==1,('Cambio ambiguo',old[:100])
        custom=custom.replace(old,new,1)
    return custom
def main():
    target=sys.argv[1];rows=[]
    prior=json.loads(blob(BASE,'tools/telefonia_conexion_release.json'))['files']
    for path,expected in zip(FILES,EXPECTED):
        original=blob(BASE,path);before=original.decode('utf8').replace('\r\n','\n')
        after=blob(target,path).decode('utf8').replace('\r\n','\n')
        custom=original
        if path.endswith(('telefonia_host.js','telefonia_persistente.js')):
            row=next(r for r in prior if r['path']==path)
            custom=gzip.decompress(base64.b64decode(row['variants'][-1]['after']))
        elif path.endswith('/mis_llamadas.php'):
            start=before.index('            if (!empty($puedeGestionAnalista) && $saliente')
            end=before.index("                $detalleUrl = BASE_URL",before.index('            } elseif',start))
            custom=(before[:start]+"            if (!empty($puedeGestionAnalista) && !$saliente && $pbxId !== '') {\n"+before[end:]).encode()
        assert sha(custom)==expected,(path,sha(custom),expected)
        pairs=[(before,after)]
        c=custom.decode('utf8').replace('\r\n','\n')
        if c!=before:pairs.append((c,delta(c,before,after)))
        rows.append({'path':path,'verified_hostinger_sha':expected,'variants':[
            {'before':packed(a.encode()),'before_sha':sha(a.encode()),
             'after':packed(b.encode()),'after_sha':sha(b.encode())} for a,b in pairs]})
    out={'version':1,'base':BASE,'target':target,'files':rows}
    (ROOT/'tools/telefonia_integridad_release.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
    print('Paquete: 12 archivos. Personalizaciones verificadas; sin SQL.')
if __name__=='__main__':main()
