#!/usr/bin/env python3
"""Publica sólo la vista del selector visual. Respaldo/restore del motor existente."""
import argparse
import importlib.util
import json
import sys
from pathlib import Path

RELEASE='convocatorias-v5-mes-selector-visual-20261023'
VIEW='app/views/convocatorias/index.php'


def main():
    parser=argparse.ArgumentParser(add_help=False)
    parser.add_argument('--site',required=True)
    parser.add_argument('--manifest',required=True)
    parser.add_argument('--restore')
    args,_=parser.parse_known_args()
    path=Path(__file__).with_name('publicar_aliados_registro.py')
    spec=importlib.util.spec_from_file_location('core_mes_visual',str(path))
    core=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    if not args.restore:
        manifest=json.loads(Path(args.manifest).read_text(encoding='utf-8'))
        if manifest.get('source')!=RELEASE:
            raise RuntimeError('Entrega desconocida. SITIO INTACTO.')
        files=manifest.get('files',[])
        if len(files)!=1 or files[0].get('path')!=VIEW:
            raise RuntimeError('El paquete visual debe modificar sólo la vista. SITIO INTACTO.')
        site=Path(args.site).resolve()
        item=files[0]
        old=core.unpack(item['base'])
        new=core.unpack(item['target'])
        if old is None or core.sha(new)!=item['sha256']:
            raise RuntimeError('Paquete corrupto. SITIO INTACTO.')
        target=core.target(site,VIEW)
        if not target.is_file():
            raise RuntimeError('Vista ausente. SITIO INTACTO.')
        current=target.read_bytes()
        if core.lf(current) not in (core.lf(old),core.lf(new)):
            raise RuntimeError('CONFLICTO: vista Hostinger personalizada después del último despliegue. SITIO INTACTO.')
        if len(manifest.get('dependencies',[]))!=4:
            raise RuntimeError('Dependencias incompletas. SITIO INTACTO.')
        for dep in manifest['dependencies']:
            value=core.unpack(dep['content'])
            if core.sha(core.lf(value))!=dep['sha256']:
                raise RuntimeError('Hash de dependencia corrupto: '+dep['path'])
            installed=core.target(site,dep['path'])
            if not installed.is_file() or core.sha(core.lf(installed.read_bytes()))!=dep['sha256']:
                raise RuntimeError('CONFLICTO: dependencia distinta '+dep['path']+'. SITIO INTACTO.')
    core.main()


if __name__=='__main__':
    try:
        main()
    except (RuntimeError,OSError,ValueError,KeyError,TypeError) as error:
        print('ERROR: '+str(error),file=sys.stderr)
        sys.exit(1)
