#!/usr/bin/env python3
"""Publicación selectiva de dos archivos nuevos; compatible con Python 3.6."""
import argparse
import hashlib
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ARCHIVOS = (
    'app/services/ZohoLeadsProspectosBridge.php',
    'tools/importar_zoho_leads.php',
    'tools/cron_zoho_leads.php',
)
VERSIONES_ANTERIORES = {
    'tools/importar_zoho_leads.php': {
        'b4d5051f2ddb9571931a823ee25c6207fa6f1b64d53fe80287d04a63f0880baa',
    },
}


def huella(ruta):
    digest = hashlib.sha256()
    with ruta.open('rb') as archivo:
        for bloque in iter(lambda: archivo.read(65536), b''):
            digest.update(bloque)
    return digest.hexdigest()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--site', required=True, type=Path)
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument('--check', action='store_true')
    modo.add_argument('--apply', action='store_true')
    parser.add_argument('--backup-root', type=Path)
    args = parser.parse_args()
    sitio = args.site.resolve()
    fuente = Path(__file__).resolve().parent.parent
    if not sitio.is_dir() or not (sitio / 'app/services/ProspectoCentralRepository.php').is_file():
        raise RuntimeError('No se encontró el almacén central de Prospectos en el sitio.')
    if args.apply:
        if args.backup_root is None:
            raise RuntimeError('--apply requiere --backup-root.')
        respaldo = args.backup_root.resolve()
        if respaldo == sitio or sitio in respaldo.parents:
            raise RuntimeError('El respaldo debe quedar fuera del sitio.')
    pendientes = []
    for nombre in ARCHIVOS:
        origen = fuente / nombre
        destino = sitio / nombre
        if not origen.is_file() or destino.is_symlink() or not destino.parent.is_dir():
            raise RuntimeError('Ruta no válida: ' + nombre)
        anterior = huella(destino) if destino.exists() else None
        nuevo = huella(origen)
        if anterior is not None and anterior != nuevo and anterior not in VERSIONES_ANTERIORES.get(nombre, set()):
            raise RuntimeError('Archivo personalizado en conflicto: ' + nombre)
        if anterior != nuevo:
            pendientes.append((origen, destino))
        print(('nuevo' if anterior is None else 'coincide' if anterior == nuevo else 'actualizable') + ' | ' + nombre)
    for nombre in ARCHIVOS:
        resultado = subprocess.run(['php', '-l', str(fuente / nombre)],
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if resultado.returncode:
            raise RuntimeError('Error de sintaxis PHP en ' + nombre)
    if args.check:
        print('Comprobación completa. Sitio intacto.')
        return
    respaldo.mkdir(parents=True, exist_ok=True)
    carpeta = Path(tempfile.mkdtemp(prefix='zoho-leads-', dir=str(respaldo)))
    creados = []
    anteriores = []
    try:
        for origen, destino in pendientes:
            if destino.exists():
                copia = carpeta / str(destino.relative_to(sitio))
                copia.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(str(destino), str(copia))
                anteriores.append((destino, copia))
            descriptor, temporal = tempfile.mkstemp(prefix='.zoho-', dir=str(destino.parent))
            try:
                with os.fdopen(descriptor, 'wb') as salida, origen.open('rb') as entrada:
                    shutil.copyfileobj(entrada, salida)
                os.chmod(temporal, 0o644)
                os.replace(temporal, str(destino))
                if not any(destino == anterior[0] for anterior in anteriores):
                    creados.append(destino)
            finally:
                if os.path.exists(temporal):
                    os.unlink(temporal)
        for nombre in ARCHIVOS:
            if huella(sitio / nombre) != huella(fuente / nombre):
                raise RuntimeError('Falló la verificación de integridad: ' + nombre)
    except Exception:
        for destino in creados:
            destino.unlink()
        for destino, copia in anteriores:
            shutil.copy2(str(copia), str(destino))
        raise
    (carpeta / 'archivos-creados.txt').write_text(
        ''.join(str(d.relative_to(sitio)) + '\n' for d in creados), encoding='utf-8')
    print('Publicación de código completada. Registro: ' + str(carpeta))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Publicación detenida: ' + str(error), file=sys.stderr)
        sys.exit(1)
