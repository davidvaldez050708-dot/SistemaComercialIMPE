#!/usr/bin/env python3
"""Conciliación selectiva V4. Nunca ejecuta migraciones ni elimina borradores."""
import argparse
import importlib.util
import json
from pathlib import Path
import sys

def core_module():
    spec=importlib.util.spec_from_file_location('publicador_base',str(Path(__file__).with_name('publicar_aliados_registro.py')))
    core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
    original=core.prepare_candidate
    def prepare(row,current,stage):
        choices=[row]
        for variant in row.get('variants',[]):
            if variant['ref']!='hostinger-confirmado-20261023':raise RuntimeError('Variante V4 no revisada.')
            candidate=dict(row);candidate.update(base=variant['base'],target=variant['target'],sha256=variant['sha256'],variants=[]);choices.append(candidate)
        accepted={};errors=[]
        for candidate in choices:
            try:
                result=original(candidate,current,stage);accepted[core.lf(result)]=result
            except RuntimeError as error:errors.append(str(error))
        if not accepted:raise RuntimeError(errors[0])
        if len(accepted)!=1:raise RuntimeError('CONFLICTO: conciliación ambigua. SITIO INTACTO.')
        return next(iter(accepted.values()))
    core.prepare_candidate=prepare;return core

def main():
    core=core_module();parser=argparse.ArgumentParser(add_help=False);parser.add_argument('--site');parser.add_argument('--manifest');parser.add_argument('--restore');args,_=parser.parse_known_args()
    if args.restore is not None and not args.restore.strip():raise RuntimeError('Falta la ruta real del respaldo. No se publicaron archivos.')
    if args.site and not args.restore:
        manifest=Path(args.manifest) if args.manifest else Path(__file__).with_name('convenios_inline_v4_release.json')
        data=json.loads(manifest.read_text(encoding='utf-8'));site=Path(args.site).resolve()
        for dependency in data['dependencies']:
            path=core.target(site,dependency['path']);expected=core.unpack(dependency['content'])
            if core.sha(core.lf(expected))!=dependency['sha256']:raise RuntimeError('Dependencia de la entrega corrupta.')
            if not path.is_file() or core.sha(core.lf(path.read_bytes()))!=dependency['sha256']:raise RuntimeError('CONFLICTO: dependencia distinta de Hostinger revisado: '+dependency['path']+'. SITIO INTACTO.')
        if not args.manifest:sys.argv.extend(['--manifest',str(manifest)])
    core.main()
if __name__=='__main__':
    try:main()
    except (RuntimeError,OSError) as error:print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
