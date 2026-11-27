#!/usr/bin/env python3
import argparse,hashlib,json,subprocess,tempfile,shutil
from pathlib import Path
HERE=Path(__file__).resolve().parent
M=json.loads((HERE/'manifest.json').read_text(encoding='utf-8'))
def blob(b):return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
def read(site,p,expected):
    f=site/p
    if f.resolve()!=f or blob(f.read_bytes())!=expected:raise ValueError('BASE_NO_RECONOCIDA | '+p)
    return f.read_bytes()
def replace(data,a,b):
    a,b=a.encode(),b.encode()
    if data.count(a)!=1:raise ValueError('ANCLA_NO_RECONOCIDA | '+a.decode()[:100])
    return data.replace(a,b,1)
def build(site,check_guards=True):
    result={}
    if check_guards:
        for p,s in M['guards'].items():
            read(site,p,s);print('DEPENDENCIA_OK | '+p)
    for spec in M['patches']:
        p=spec['path'];data=read(site,p,spec['before'])
        for h in spec['hunks']:data=replace(data,h['old'],h['new'])
        result[p]=data
    p=M['index']['path'];data=read(site,p,M['index']['before'])
    start=b'            <div class="whatsapp-conversation-list" data-whatsapp-conversation-list>\n'
    end=b'            </div>\n        </aside>'
    if data.count(start)!=1 or data.count(end)!=1:raise ValueError('Lista no reconocida')
    i=data.index(start)+len(start);j=data.index(end,i)
    partial=data[i:j]
    name_start=b'                        $nombreConversacion = trim('
    name_end=b'                        $activa ='
    if partial.count(name_start)!=1 or partial.count(name_end)!=1:raise ValueError('Nombre de lista no reconocido')
    a=partial.index(name_start);b=partial.index(name_end,a)
    partial=partial[:a]+b'                        $nombreConversacion = WhatsAppPresentacionService::nombre($conversacion);\n\n'+partial[b:]
    partial=replace(partial,"class=\"whatsapp-conversation-item <?= $activa ? 'is-active' : '' ?>\"","class=\"whatsapp-conversation-item <?= $activa ? 'is-active' : '' ?> <?= (int)($conversacion['no_leidos']??0)>0?'is-unread':'' ?>\"")
    partial=replace(partial,"$conversacion['ultimo_mensaje_preview'] ??\n                                            'Sin mensajes todavía'","(($conversacion['ultimo_mensaje_direccion']??'')==='SALIENTE'?'Tú: ':'').($conversacion['ultimo_mensaje_preview'] ?? 'Sin mensajes todavía')")
    partial_name='app/views/whatsapp/lista_conversaciones.php'
    if (site/partial_name).exists() or (site/partial_name).is_symlink():raise ValueError('Fragmento nuevo ya existe')
    result[partial_name]=partial
    result[p]=replace(data[:i]+b"                <?php require __DIR__.'/lista_conversaciones.php'; ?>\n"+data[j:],
        '<span><?= count($conversaciones) ?> activas</span>','<span data-whatsapp-list-count><?= count($conversaciones) ?> activas</span>')
    for p,file in M['new_files'].items():
        if (site/p).exists() or (site/p).is_symlink():raise ValueError('Nuevo archivo ya existe: '+p)
        result[p]=(HERE/file).read_bytes()
    for p,data in result.items():
        if p.endswith('.php') or (p.endswith('.js') and shutil.which('node')):
            with tempfile.NamedTemporaryFile(suffix=Path(p).suffix) as f:
                f.write(data);f.flush();subprocess.run(['php','-l',f.name] if p.endswith('.php') else ['node','--check',f.name],check=True)
        elif p.endswith('.js'):print('NODE_AUSENTE | pendiente de CI')
        print('CANDIDATO | '+p+' | '+blob(data))
    return result
if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--site',required=True)
    p.add_argument('--check',action='store_true',required=True)
    args=p.parse_args();site=Path(args.site).resolve()
    if site==HERE or site in HERE.parents:raise ValueError('Ejecutar fuera del sitio')
    build(site)
    print('CHECK_OK | solo lectura | no publicar')
