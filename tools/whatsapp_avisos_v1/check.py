import argparse,hashlib,json,subprocess,tempfile
from pathlib import Path
HERE=Path(__file__).resolve().parent
M=json.loads((HERE/'manifest.json').read_text(encoding='utf-8'))
def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def build(site):
    result=[]
    for p in M['patches']:
        target=site/p['path'];old=target.read_bytes()
        if target.resolve()!=target or blob(old)!=p['before']:raise ValueError('Base no reconocida: '+p['path'])
        anchor=p['anchor'].encode()
        if old.count(anchor)!=1:raise ValueError('Bloque no reconocido: '+p['path'])
        result.append((p['path'],old.replace(anchor,p['insert'].encode()+anchor,1)))
    p=M['replacement'];target=site/p['path']
    if target.resolve()!=target or blob(target.read_bytes())!=p['before']:raise ValueError('Base contextual no reconocida')
    result.append((p['path'],(HERE/'whatsapp_contextual.js').read_bytes()))
    for p in M['new_files']:
        target=site/p['path']
        if target.exists() or target.is_symlink():raise ValueError('Nuevo archivo ya existe: '+p['path'])
        result.append((p['path'],(HERE/p['file']).read_bytes()))
    for name,data in result:
        if name.endswith('.php'):
            with tempfile.NamedTemporaryFile(suffix='.php') as f:
                f.write(data);f.flush();subprocess.run(['php','-l',f.name],check=True)
        elif name.endswith('.js'):
            import shutil
            if shutil.which('node'):
                with tempfile.NamedTemporaryFile(suffix='.js') as f:
                    f.write(data);f.flush();subprocess.run(['node','--check',f.name],check=True)
            else: print('NODE_AUSENTE | sintaxis JS se valida en CI')
        print('CANDIDATO | '+name+' | '+blob(data))
    return result
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--site',required=True);p.add_argument('--check',action='store_true',required=True)
    args=p.parse_args();site=Path(args.site).resolve()
    if HERE==site or site in HERE.parents:raise ValueError('Ejecutar fuera del sitio')
    build(site)
    print('CHECK_OK | sin cambios al sitio ni base de datos | publicacion no autorizada')
