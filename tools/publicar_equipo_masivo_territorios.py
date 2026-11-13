#!/usr/bin/env python3
"""Integra la vinculación del equipo territorial con comprobación y respaldo."""

import argparse
import hashlib
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile


BASE = 'd6a86bd5'
ARCHIVOS = (
    Path('app/controllers/TerritorioController.php'),
    Path('app/models/TerritorioModel.php'),
    Path('app/views/territorios/index.php'),
    Path('public/css/territorios_resumen_refinamientos.css'),
)


def git_archivo(repositorio, revision, ruta):
    resultado = subprocess.run(
        ['git', '-C', str(repositorio), 'show', revision + ':' + ruta.as_posix()],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if resultado.returncode:
        raise RuntimeError('No se pudo leer la revisión de ' + ruta.as_posix())
    return resultado.stdout


def integrar(instalado, anterior, nuevo, temporal):
    if instalado == nuevo:
        return instalado
    with tempfile.TemporaryDirectory(prefix='impe-modal-merge-', dir=str(temporal)) as carpeta:
        carpeta = Path(carpeta)
        local = carpeta / 'instalado'
        base = carpeta / 'anterior'
        remoto = carpeta / 'nuevo'
        local.write_bytes(instalado)
        base.write_bytes(anterior)
        remoto.write_bytes(nuevo)
        resultado = subprocess.run(
            ['git', 'merge-file', '-p', str(local), str(base), str(remoto)],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if resultado.returncode:
            raise RuntimeError('Conflicto con personalizaciones; sitio intacto.')
        return resultado.stdout


def validar(ruta, datos):
    texto = datos.decode('utf-8')
    if ruta.name == 'index.php':
        if texto.count('territory-bulk-parent-group') < 1 or \
                texto.count('cuentas_por_estado[') < 1:
            raise RuntimeError('No quedó integrado el selector de Cuentas Clave.')
    elif ruta.name == 'TerritorioModel.php':
        if texto.count('cuentasUnicas') < 3:
            raise RuntimeError('No quedó integrada la vinculación automática.')
    elif ruta.name == 'TerritorioController.php':
        if 'cuentasPorEstado[$tipo]' not in texto:
            raise RuntimeError('No quedó integrada la selección por tipo.')
    if ruta.suffix == '.php':
        with tempfile.TemporaryDirectory(prefix='impe-modal-php-') as carpeta:
            archivo = Path(carpeta) / ruta.name
            archivo.write_bytes(datos)
            resultado = subprocess.run(['php', '-l', str(archivo)],
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if resultado.returncode:
                raise RuntimeError('No supera php -l: ' + ruta.as_posix())
    elif '.territory-bulk-parent-group > summary' not in texto or \
            '.territory-bulk-select-all' not in texto:
        raise RuntimeError('No quedó integrado el estilo del modal.')


def sha(datos):
    return hashlib.sha256(datos).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True, type=Path)
    parser.add_argument('--repo', required=True, type=Path)
    parser.add_argument('--revision', default='FETCH_HEAD')
    parser.add_argument('--backup-root', type=Path)
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument('--check', action='store_true')
    modo.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    sitio = args.site.resolve()
    repositorio = args.repo.resolve()
    if not sitio.is_dir() or not repositorio.is_dir():
        raise ValueError('No se encontró el sitio o el repositorio.')
    if args.apply and not args.backup_root:
        raise ValueError('--backup-root es obligatorio con --apply.')
    preparados = {}
    for ruta in ARCHIVOS:
        archivo = (sitio / ruta).resolve()
        if sitio not in archivo.parents or not archivo.is_file():
            raise ValueError('Falta archivo del sitio: ' + ruta.as_posix())
        original = archivo.read_bytes()
        integrado = integrar(original, git_archivo(repositorio, BASE, ruta),
                             git_archivo(repositorio, args.revision, ruta), sitio)
        validar(ruta, integrado)
        preparados[ruta] = (archivo, original, integrado)
        print('{} | actual {} | {}'.format(
            ruta.as_posix(), sha(original),
            'sin cambio' if original == integrado else 'integración sin conflicto'))
    if args.check:
        print('Comprobación completa. Sitio intacto.')
        return
    raiz = args.backup_root.resolve()
    if raiz == sitio or sitio in raiz.parents:
        raise ValueError('El respaldo debe estar fuera del sitio público.')
    raiz.mkdir(parents=True, exist_ok=True)
    respaldo = Path(tempfile.mkdtemp(prefix='territorios-sin-duplicados-', dir=str(raiz)))
    cambiados = []
    temporales = []
    try:
        for ruta, (archivo, original, integrado) in preparados.items():
            if archivo.read_bytes() != original:
                raise RuntimeError('Cambió un archivo durante la comprobación; sitio intacto.')
            copia = respaldo / ruta
            copia.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(str(archivo), str(copia))
        for ruta, (archivo, original, integrado) in preparados.items():
            if original == integrado:
                continue
            descriptor, nombre = tempfile.mkstemp(prefix='.impe-modal-', dir=str(archivo.parent))
            temporal = Path(nombre)
            temporales.append(temporal)
            with os.fdopen(descriptor, 'wb') as salida:
                salida.write(integrado)
                salida.flush()
                os.fsync(salida.fileno())
            os.chmod(str(temporal), stat.S_IMODE(archivo.stat().st_mode))
            if archivo.read_bytes() != original:
                raise RuntimeError('Cambió un archivo antes de escribirlo.')
            os.replace(str(temporal), str(archivo))
            cambiados.append(ruta)
            if sha(archivo.read_bytes()) != sha(integrado):
                raise RuntimeError('Falló la verificación de ' + ruta.as_posix())
    except Exception:
        for ruta in cambiados:
            shutil.copy2(str(respaldo / ruta), str(preparados[ruta][0]))
        raise
    finally:
        for temporal in temporales:
            if temporal.exists():
                temporal.unlink()
    print('Publicación terminada. Respaldo: ' + str(respaldo))


if __name__ == '__main__':
    try:
        main()
    except (OSError, UnicodeError, ValueError, RuntimeError) as error:
        print('Publicación detenida: ' + str(error), file=sys.stderr)
        sys.exit(1)
