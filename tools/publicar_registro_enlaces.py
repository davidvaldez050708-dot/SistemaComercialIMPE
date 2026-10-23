#!/usr/bin/env python3
"""Publicador selectivo. La reversión conserva las ligas cortas ya compartidas."""
import argparse,importlib.util,json,shutil,sys,tempfile
from pathlib import Path
PERSISTENTES={'app/services/RegistroEnlaceService.php','registro/el-primer-paso-hacia-tu-meta-comienza-aqui/index.php','registro/el-primer-paso-hacia-tu-meta-comienza-aqui/.htaccess','tools/migrar_enlaces_registro_amigables.php'}
def main():
    spec=importlib.util.spec_from_file_location('bridge',Path(__file__).with_name('publicar_convenios_inline_v4.py'));bridge=importlib.util.module_from_spec(spec);spec.loader.exec_module(bridge);core=bridge.core_module();original_restore=core.restore
    def restore(site,backup):
        info=json.loads((backup/'respaldo.json').read_text(encoding='utf-8'))
        if info['site']!=str(site):raise RuntimeError('El respaldo pertenece a otro sitio')
        for row in info['files']:
            file=core.target(site,row['path']);old=core.target(backup/'original',row['path'])
            if not file.is_file() or core.sha(file.read_bytes())!=row['installed']:raise RuntimeError('Restauración detenida: archivo modificado desde la publicación: '+row['path'])
            if row['original'] is not None and (not old.is_file() or core.sha(old.read_bytes())!=row['original']):raise RuntimeError('Respaldo original incompleto')
        selected=[r for r in info['files'] if r['path'] not in PERSISTENTES]
        # No altera el respaldo original ni elimina el soporte de alias publicados.
        with tempfile.TemporaryDirectory(prefix='restaurar-generadores-',dir=str(backup.parent)) as temp:
            temp=Path(temp);copy=dict(info);copy['files']=selected;(temp/'respaldo.json').write_text(json.dumps(copy),encoding='utf-8')
            for row in selected:
                if row['original'] is not None:
                    target=core.target(temp/'original',row['path']);target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(str(backup/'original'/row['path']),str(target))
            original_restore(site,temp)
        print('CONSERVADO: resolución de ligas amigables y tabla de equivalencias. No se ejecutó SQL.')
    core.restore=restore
    p=argparse.ArgumentParser(add_help=False);p.add_argument('--restore');p.add_argument('--manifest');args,_=p.parse_known_args()
    if args.restore is not None and not args.restore.strip():raise RuntimeError('Falta la ruta real del respaldo.')
    if not args.manifest:sys.argv.extend(['--manifest',str(Path(__file__).with_name('registro_enlaces_release.json'))])
    core.main()
if __name__=='__main__':
    try:main()
    except Exception as e:print('ERROR: '+str(e),file=sys.stderr);sys.exit(1)
