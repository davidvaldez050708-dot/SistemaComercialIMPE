#!/usr/bin/env python3
"""Entrega V5 con el mismo motor reversible de V4. No ejecuta SQL."""
import importlib.util
from pathlib import Path
import sys

def main():
    path=Path(__file__).with_name('publicar_convenios_inline_v4.py')
    spec=importlib.util.spec_from_file_location('publicador_v4',str(path))
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    if '--manifest' not in sys.argv:
        sys.argv.extend(['--manifest',str(Path(__file__).with_name('convocatorias_gestion_v5_release.json'))])
    module.main()

if __name__=='__main__':
    try:main()
    except (RuntimeError,OSError) as error:print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
