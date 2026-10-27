import base64, gzip, hashlib, json, pathlib, subprocess, sys, tempfile

R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/limpieza_llamadas_release.json').read_text(encoding='utf8'))
def unpack(v):return gzip.decompress(base64.b64decode(v))
for crlf in [False,True]:
 for hostinger in [False,True]:
     with tempfile.TemporaryDirectory() as folder:
         site=pathlib.Path(folder)/'sitio';site.mkdir();(site/'index.php').write_text('<?php')
         backups=pathlib.Path(folder)/'respaldos';before={};dependencies={}
         for row in spec['files']:
             v=row['variants'][-1] if hostinger else row['variants'][0];after=unpack(v['after'])
             assert hashlib.sha256(after).hexdigest()==v['after_sha']
             assert v['after_sha'] in [v2['after_sha'] for v2 in row['variants']]
             if not hostinger:assert (R/row['path']).read_bytes().replace(b'\r\n',b'\n')==after,'Paquete obsoleto: '+row['path']
             old=unpack(v['before']) if v['before'] is not None else None
             before[row['path']]=old.replace(b'\n',b'\r\n') if old is not None and crlf else old
             if old is not None:
                 p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(before[row['path']])
         for d in spec['dependencies']:
             data=(R/d['path']).read_bytes().replace(b'\r\n',b'\n');assert hashlib.sha256(data).hexdigest()==d['sha']
             p=site/d['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data);dependencies[d['path']]=data
         extra=site/'public/css/responsive_sistema.css';extra.parent.mkdir(parents=True,exist_ok=True);extra.write_bytes(b'custom styles')
         command=[sys.executable,str(R/'tools/publicar_limpieza_llamadas.py'),'--site',str(site),'--backup-root',str(backups)]
         def run(*args,ok=True):
             p=subprocess.run(command+list(args),capture_output=True,text=True)
             assert (p.returncode==0)==ok,p.stdout+p.stderr
             return p.stdout
         def unchanged():
             for n,data in before.items():assert ((site/n).read_bytes() if (site/n).exists() else None)==data,n
             for n,data in dependencies.items():assert (site/n).read_bytes()==data,n
             assert extra.read_bytes()==b'custom styles'
         run('--check');unchanged();assert not backups.exists()
         target=site/'app/services/TelefoniaControlService.php';target.write_bytes(target.read_bytes()+b'\n//custom')
         run('--apply',ok=False);target.write_bytes(before['app/services/TelefoniaControlService.php']);unchanged();assert not backups.exists()
         run('--apply');assert run('--check').count('ACTUALIZADO:')==7
         backup=next(backups.iterdir());run('--apply');assert len(list(backups.iterdir()))==1
         current=target.read_bytes();target.write_bytes(current+b'\n//later');run('--restore',str(backup),ok=False);target.write_bytes(current)
         run('--restore',str(backup));unchanged()
print('Publicador: 7 archivos, LF/CRLF, dependencias intactas, conflictos, respaldo, reinstalación y restauración exacta OK')
