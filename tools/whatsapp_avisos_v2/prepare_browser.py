from pathlib import Path
import hashlib,json,os,subprocess
root=Path(__file__).resolve().parents[2];here=Path(__file__).resolve().parent
m=json.loads((here/'manifest.json').read_text(encoding='utf-8'))
out=here/'ci_assets';out.mkdir(exist_ok=True)
def blob(b):return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
def fetch(p,sha):
    f=out/Path(p).name
    subprocess.run(['curl','-fsSL','--max-time','30','--retry','2','https://fundacionrededumex.org/'+p,'-o',str(f)],check=True)
    b=f.read_bytes()
    if blob(b)!=sha:raise ValueError('El archivo publico no coincide con Hostinger: '+p+' '+blob(b))
    print('BASE_EXACTA | '+p+' | '+sha)
    return f,b
p=m['additional_patches'][0]
f,b=fetch(p['path'],p['before'])
for h in p['hunks']:
    a,z=h['old'].encode(),h['new'].encode()
    if b.count(a)!=1:raise ValueError('Ancla no reconocida')
    b=b.replace(a,z,1)
assert blob(b)=='5200a76bc6ec477cf2c62c30dfe0b91622187598'
f.write_bytes(b)
for name in ['public/css/global.css','public/css/recordatorios.css','public/javascript/convocatoria_notificaciones.js','public/javascript/telefonia_notificaciones.js']:
    fetch(name,m['guards'][name])
for name in ['whatsapp_contextual.js','whatsapp_avisos_globales.js']:
    subprocess.run(['node','--check',str(here/name)],check=True)
subprocess.run(['node','--check',str(f)],check=True)
