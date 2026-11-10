#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Prueba sobre archivos ficticios, sin usuarios, PBX ni datos de producción."""
import importlib.util
import hashlib
import os
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    'shell_safe', str(ROOT / 'tools/publicar_shell_modal_ventas_hostinger_v1.py'))
pub = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)


def sha(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def must(ok, message):
    if not ok:
        raise AssertionError(message)


reference = (b'/* shell personalizado con cambios ajenos */\n' +
             b'const extraPersonalizacion=()=>true;\n' + pub.OLD +
             b'\nconst llamadaPBX=()=>"No modificar";\n')
changed = pub.patch(reference)
must(changed.count(pub.NEW) == 1, 'Insercion unica')
must(changed.replace(pub.NEW, pub.OLD) == reference, 'No se conservaron los otros bytes')
for bad in [reference + pub.OLD, reference.replace(pub.OLD, b'otros cambios')]:
    try:
        pub.patch(bad)
    except pub.Blocked:
        pass
    else:
        raise AssertionError('No bloqueo marcador diferente/duplicado')

with tempfile.TemporaryDirectory(prefix='impe-shell-safe-') as tmp:
    base = Path(tmp) / 'public_html'
    path = base / pub.REL
    path.parent.mkdir(parents=True)
    path.write_bytes(reference)
    before = path.read_bytes()
    try:
        pub.inspect(base)
    except pub.Blocked:
        pass
    else:
        raise AssertionError('Acepto archivo no auditado')
    must(path.read_bytes() == before, 'Inspeccion modifico el shell')
    try:
        pub.apply(base, 'CONFIRMACION_INCORRECTA')
    except pub.Blocked:
        pass
    else:
        raise AssertionError('Se publico sin confirmacion')
    must(path.read_bytes() == before, 'Intento no confirmado modifico el shell')

    original_sha = pub.BEFORE
    home = os.environ.get('HOME')
    try:
        # Se ajusta la huella solo dentro del proceso de prueba, con datos ficticios.
        pub.BEFORE = sha(reference)
        os.environ['HOME'] = tmp
        _, inspected, candidate, _, done = pub.inspect(base)
        must(not done and inspected == reference and candidate == changed, 'Check fixture')
        pub.apply(base, pub.CONFIRM)
        must(path.read_bytes() == changed, 'Apply altero personalizaciones')
        pub.apply(base, pub.CONFIRM)
        backups = list((Path(tmp) / 'impe-embudo-cierre-api-v1' / 'backups-shell').iterdir())
        must(len(backups) == 1, 'El segundo apply creo respaldos extras')
        path.write_bytes(changed + b'\n// custom posterior')
        try:
            pub.rollback(base, backups[0], pub.CONFIRM)
        except pub.Blocked:
            pass
        else:
            raise AssertionError('Rollback borro personalizacion posterior')
        must(path.read_bytes().endswith(b'custom posterior'), 'Se altero personalizacion posterior')
        path.write_bytes(changed)
        pub.rollback(base, backups[0], pub.CONFIRM)
        must(path.read_bytes() == reference, 'Rollback no restauro original')
    finally:
        pub.BEFORE = original_sha
        if home is None:
            os.environ.pop('HOME', None)
        else:
            os.environ['HOME'] = home

print('CHECK OK: parche unico, SHA, conservacion de bytes, confirmacion, respaldo, idempotencia y rollback.')
