#!/usr/bin/env python3
"""Publicación exacta de * y # en el teclado de extensión, sin SQL."""
import sys
from pathlib import Path
import publicar_teclado_fisico as engine
engine.MANIFEST_SHA256 = '2f1fad9776125cd093705804972b8f9a57b6cc3bbe8a3c99412e91121f635ab1'
engine.PATHS = ['public/javascript/telefonia_webrtc_dtmf.js', 'public/javascript/telefonia_host.js', 'public/javascript/telefonia_shell.js', 'public/javascript/telefonia_persistente.js']
engine.DEPENDENCIES = {}
if __name__ == '__main__':
    if '--manifest' not in sys.argv:
        sys.argv.extend(['--manifest', str(Path(__file__).with_name('teclado_simbolos_release.json'))])
    try:
        engine.main()
    except Exception as error:
        print('BLOQUEADO: '+str(error), file=sys.stderr)
        sys.exit(1)
