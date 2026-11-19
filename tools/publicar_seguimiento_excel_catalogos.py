#!/usr/bin/env python3
"""Plantilla con pasos 00–13 y municipios para Excel/Sheets. No ejecuta SQL."""
import sys
from pathlib import Path
import publicar_seguimiento_excel_opcionales as engine
engine.MANIFEST_SHA256 = 'a886e0fa912c698abf9c5a358b37c6ab58a89f334d452e8c472b2fab4f589784'
engine.PATHS = ['app/services/SeguimientoExcelPlantillaService.php']
engine.DEPENDENCIES = {'app/services/SeguimientoExcelImportService.php': '2857504c1ae345b30b36082a4da9e268d86e61ce533926f177440a07cec554e5'}
if __name__ == '__main__':
    if '--manifest' not in sys.argv:
        sys.argv.extend(['--manifest',str(Path(__file__).with_name('seguimiento_excel_catalogos_release.json'))])
    try: engine.main()
    except Exception as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr);sys.exit(1)
