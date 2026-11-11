#!/usr/bin/env python3
"""Publicación mensual V5: sólo hashes exactos de las variantes revisadas."""
import argparse
import importlib.util
import json
from pathlib import Path
import sys

def main():
    parser=argparse.ArgumentParser(add_help=False)
    parser.add_argument('--site',required=True)
    parser.add_argument('--manifest',required=True)
    parser.add_argument('--restore')
    args,_=parser.parse_known_args()
    module_path=Path(__file__).with_name('publicar_aliados_registro.py')
    spec=importlib.util.spec_from_file_location('core_meses_v5',str(module_path))
    core=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    site=Path(args.site).resolve()
    if not args.restore:
        manifest=json.loads(Path(args.manifest).read_text(encoding='utf-8'))
        if manifest.get('source')!='932c3fbb86fb1941fa6d232c0812198652d36fda':
            raise RuntimeError('Manifiesto de otra revisión. SITIO INTACTO.')
        expected={
            'app/controllers/ConvocatoriaController.php':'bbf084c48239494d6e1a0d701d06187c00376dea04ebfe2bae1eea59aed4c1d5',
            'app/views/convocatorias/index.php':'80cc94ac0a3a5ceddda6b92e7ddf0b57c426e8a6b1e7c0f6e8c59969591b0269',
        }
        if len(manifest.get('files',[]))!=3 or set(r['path'] for r in manifest['files'])!=set(expected)|{'app/helpers/ConvocatoriaVentanaMensual.php'}:
            raise RuntimeError('Conjunto de archivos inesperado. SITIO INTACTO.')
        for row in manifest['files']:
            old=core.unpack(row['base'])
            new=core.unpack(row['target'])
            if (old is not None and core.sha(old)!=expected.get(row['path'])) or core.sha(new)!=row['sha256']:
                raise RuntimeError('Hash de entrega incorrecto: '+row['path']+'. SITIO INTACTO.')
            target=core.target(site,row['path'])
            present=target.read_bytes() if target.is_file() else None
            if present is None and old is None:
                continue
            if present is None or core.sha(core.lf(present)) not in {core.sha(core.lf(v)) for v in (old,new) if v is not None}:
                raise RuntimeError('CONFLICTO: cambio no reconocido en '+row['path']+'. SITIO INTACTO.')
        for dependency in manifest.get('dependencies',[]):
            target=core.target(site,dependency['path'])
            if not target.is_file() or core.sha(core.lf(target.read_bytes()))!=dependency['sha256']:
                raise RuntimeError('CONFLICTO: dependencia V5 distinta ('+dependency['path']+'). SITIO INTACTO.')
    core.main()

if __name__=='__main__':
    try:
        main()
    except (RuntimeError,OSError,ValueError,KeyError) as ex:
        print('ERROR: '+str(ex),file=sys.stderr)
        sys.exit(1)
