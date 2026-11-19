#!/usr/bin/env python3
"""Conservar la próxima acción elegida al registrar una llamada; sin SQL."""
import sys
from pathlib import Path
import publicar_analista_proxima_accion as engine
engine.MANIFEST_SHA256 = '4a32c52df5e0de18af9cf5a9bd0046e55354c5b267bd4f86af6dcceaa319b4c2'
engine.PATHS = ['public/javascript/seguimiento_interacciones.js', 'public/javascript/seguimiento_llamada_registro_obligatorio.js']
if __name__ == '__main__':
    if '--manifest' not in sys.argv:
        sys.argv.extend(['--manifest',str(Path(__file__).with_name('analista_accion_editable_release.json'))])
    try: engine.main()
    except Exception as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr);sys.exit(1)
