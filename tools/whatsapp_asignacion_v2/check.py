import argparse, hashlib, json, os, subprocess, tempfile
from pathlib import Path
HERE=Path(__file__).resolve().parent
M=json.loads((HERE/'manifest.json').read_text(encoding='utf-8'))
def blob(b):
    return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
def build(site):
    paths=['app/models/WhatsAppModel.php','app/services/WhatsAppAsignacionService.php']
    outputs=[]
    for p,k in zip(paths,['model','service']):
        path=site/p
        if path.is_symlink() or path.resolve()!=path: raise ValueError('Ruta no segura: '+p)
        old=path.read_bytes()
        spec=M[k]
        if blob(old)!=spec['before']: raise ValueError('Base no reconocida: '+p)
        if k=='model':
            a,b=spec['old'].encode(),spec['new'].encode()
            if old.count(a)!=1: raise ValueError('Bloque no reconocido')
            new=old.replace(a,b,1)
        else: new=(HERE/'WhatsAppAsignacionService.php').read_bytes()
        if blob(new)!=spec['after']: raise ValueError('Candidato alterado: '+p)
        with tempfile.NamedTemporaryFile(suffix='.php') as f:
            f.write(new); f.flush()
            subprocess.run(['php','-l',f.name],check=True)
        print('COMPATIBLE | '+p+' | '+blob(new))
        outputs.append((path,new))
    return outputs
def main():
    p=argparse.ArgumentParser()
    p.add_argument('--site',required=True)
    p.add_argument('--check',action='store_true',required=True)
    args=p.parse_args()
    site=Path(args.site).resolve()
    if HERE==site or site in HERE.parents: raise ValueError('Ejecutar fuera del sitio')
    build(site)
    subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(HERE/'schema.php'),str(site)],check=True)
    print('CHECK_OK | solo lectura | sin publicar')
if __name__=='__main__': main()
