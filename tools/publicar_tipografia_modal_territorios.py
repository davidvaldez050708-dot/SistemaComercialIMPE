#!/usr/bin/env python3
"""Publica solo la tipografía del modal territorial, conservando otros cambios."""

import argparse
import hashlib
import os
from pathlib import Path
import re
import shutil
import stat
import sys
import tempfile


ARCHIVO = Path('public/css/territorios_resumen_refinamientos.css')
REGLAS = (
    (r'#modalAsignacionMasiva\s+\.territory-bulk-header\s+\.system-form-modal-title',
     'font-size', 'clamp(20px, 2vw, 24px)', '16px'),
    (r'#modalAsignacionMasiva\s+\.territory-bulk-header\s+\.system-form-modal-subtitle',
     'font-size', '14px', '11px'),
    (r'\.territory-bulk-states-copy\s+h6\s*,\s*\.territory-bulk-section-title',
     'font-size', '19px', '14px'),
    (r'\.territory-bulk-states-copy\s+p', 'font-size', '13px', '11px'),
    (r'\.territory-bulk-options-list', 'font-size', '13px', '12px'),
    (r'\.territory-bulk-chip', 'font-size', '12px', '11px'),
    (r'\.territory-bulk-person-identity\s+strong', 'font-size', '13px', '12px'),
)


def huella(datos):
    return hashlib.sha256(datos).hexdigest()


def bloque_unico(css, selector):
    patron = re.compile(r'(?m)(?P<inicio>^\s*' + selector +
                        r'\s*\{)(?P<cuerpo>[^{}]*)(?P<fin>\})')
    encontrados = list(patron.finditer(css))
    if len(encontrados) != 1:
        raise ValueError('Selector ausente o repetido: ' + selector)
    return encontrados[0]


def cambiar_propiedad(css, selector, propiedad, anterior, nuevo):
    bloque = bloque_unico(css, selector)
    cuerpo = bloque.group('cuerpo')
    patron = re.compile(r'(?m)^(?P<prefijo>[ \t]*' +
                        re.escape(propiedad) + r'[ \t]*:[ \t]*)' +
                        r'(?P<valor>[^;\r\n]+)(?P<fin>;[ \t]*\r?)$')
    encontrados = list(patron.finditer(cuerpo))
    if len(encontrados) != 1:
        raise ValueError('Propiedad ausente o repetida: ' + selector)
    propiedad_actual = encontrados[0]
    valor = propiedad_actual.group('valor').strip()
    if valor == nuevo:
        return css, False
    if valor != anterior:
        raise ValueError('Valor personalizado en ' + selector + ': ' + valor)
    inicio = bloque.start('cuerpo') + propiedad_actual.start('valor')
    fin = bloque.start('cuerpo') + propiedad_actual.end('valor')
    return css[:inicio] + nuevo + css[fin:], True


def agregar_fuente(css):
    selector = r'#modalAsignacionMasiva\s+\.territory-bulk-content'
    bloque = bloque_unico(css, selector)
    cuerpo = bloque.group('cuerpo')
    encontrado = re.search(r'(?m)^[ \t]*font-family[ \t]*:[ \t]*([^;]+);', cuerpo)
    if encontrado:
        if encontrado.group(1).strip() == "'Manrope', sans-serif":
            return css, False
        raise ValueError('Fuente personalizada en el modal; sitio intacto.')
    salto = '\r\n' if '\r\n' in css else '\n'
    posicion = bloque.end('cuerpo')
    return css[:posicion] + '    font-family: \'Manrope\', sans-serif;' + salto + css[posicion:], True


def preparar(original):
    css = original.decode('utf-8')
    cambios = 0
    for selector, propiedad, anterior, nuevo in REGLAS:
        css, cambiado = cambiar_propiedad(css, selector, propiedad, anterior, nuevo)
        cambios += int(cambiado)
    css, cambiado = agregar_fuente(css)
    cambios += int(cambiado)
    return css.encode('utf-8'), cambios


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True, type=Path)
    parser.add_argument('--backup-root', type=Path)
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument('--check', action='store_true')
    modo.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    sitio = args.site.resolve()
    archivo = (sitio / ARCHIVO).resolve()
    if sitio not in archivo.parents or not archivo.is_file():
        raise ValueError('No se encontró el CSS dentro del sitio indicado.')
    original = archivo.read_bytes()
    preparado, cambios = preparar(original)
    print('CSS actual: ' + huella(original))
    print('Reglas de tipografía por ajustar: ' + str(cambios))
    if args.check:
        print('Comprobación completa. Sitio intacto.')
        return
    if not args.backup_root:
        raise ValueError('--backup-root es obligatorio con --apply.')
    if not cambios:
        print('La tipografía ya está aplicada. Sitio intacto.')
        return
    raiz = args.backup_root.resolve()
    if raiz == sitio or sitio in raiz.parents:
        raise ValueError('El respaldo debe estar fuera del sitio público.')
    raiz.mkdir(parents=True, exist_ok=True)
    respaldo = Path(tempfile.mkdtemp(prefix='territorios-tipografia-', dir=str(raiz)))
    copia = respaldo / ARCHIVO.name
    shutil.copy2(str(archivo), str(copia))
    temporal = None
    try:
        descriptor, nombre = tempfile.mkstemp(prefix='.territorios-tipografia-', dir=str(archivo.parent))
        temporal = Path(nombre)
        with os.fdopen(descriptor, 'wb') as salida:
            salida.write(preparado)
            salida.flush()
            os.fsync(salida.fileno())
        os.chmod(str(temporal), stat.S_IMODE(archivo.stat().st_mode))
        if archivo.read_bytes() != original:
            raise RuntimeError('El CSS cambió durante la comprobación. Sitio intacto.')
        os.replace(str(temporal), str(archivo))
        temporal = None
        if huella(archivo.read_bytes()) != huella(preparado):
            shutil.copy2(str(copia), str(archivo))
            raise RuntimeError('Falló la verificación; se restauró el archivo anterior.')
    finally:
        if temporal is not None and temporal.exists():
            temporal.unlink()
    print('Publicación terminada. Respaldo: ' + str(copia))


if __name__ == '__main__':
    try:
        main()
    except (OSError, UnicodeError, ValueError, RuntimeError) as error:
        print('Publicación detenida: ' + str(error), file=sys.stderr)
        sys.exit(1)
