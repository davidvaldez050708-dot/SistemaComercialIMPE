#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Preflight Fase 2A, EXCLUSIVAMENTE SOLO LECTURA.

No hace backups ni despliegues, no conecta a MySQL y no modifica el CRM.
Sirve para identificar qué ficheros personalizados requieren conciliación.
"""
import argparse
import hashlib
from pathlib import Path
import sys

# Hash SHA-1 git-blob, NO hash de texto normal; compara exactamente los bytes.
MODIFICADOS = {
    'app/controllers/EmbudoComercialController.php':(
        '01e4565fbb8874ee080714955fafa41d907305fa','fbfc356787390bce06edb8678057c71c95c8a6e0'),
    'app/helpers/AdminRolePolicy.php':(
        '87024131a5d5bb8d2bfe7be9440c232422938d0f','72abfb9e72c7cce457e991b6682efcb10d83ea13'),
    'app/services/EmbudoComercialRepository.php':(
        '45c12542b42a0dbaeee282e7196defef999852e7','26ede5fe8648ef4a898719da46fde59a7d659a23'),
    'app/services/TelefoniaResultadoVentasService.php':(
        'cad8946ba51a31df15a5f4070d7f0091f6a86467','0a54290945a19ec02edc3e122da87e31bbb72027'),
    'app/views/embudo_comercial/detalle.php':(
        'd945736b309ebe546d7cba13b9beabb44a3698ba','660199b4c55734fcab548627eeb5a468f7e0a37b'),
    'app/views/embudo_comercial/index.php':(
        '279f77446d4840fd2425510290c8a5490a77a3e1','e48248b8b97aca2f01c284ad41cd913245a4ffde'),
    'app/views/telefonia/resultado_ventas_form.php':(
        '0154ed521a9dbf9fbd1c43368a765a455f8f8820','f28b32e5c87fa526dfa5cc6d41987452381aafae'),
    'app/views/telefonia/ventas_llamadas.php':(
        'ab5bc986c897468920f74ba1e2406e2bcc34b3b6','063667e63580e875c76b377a48e7b366576d456a'),
    'public/css/embudo_comercial.css':(
        'acb7c428900aedcf2f50a564c0886742921927c9','9ef05c3861bce969592179a617f655fb8056bb6b'),
    'public/javascript/telefonia_ventas_resultados.js':(
        'cf8a97fb772e4241199b64bc785b9474a4b43c8a','906605027b26d6caba566d00bf91647018cae089'),
}
NUEVOS = {
    'app/services/EmbudoComercialEtapas.php':'db47554c68443bcf9f48c58bd25e18a1a0373007',
    'app/services/EmbudoComercialMovimientoService.php':'9621a3e2a2b96d0c89ecd597a17c323c4ee09139',
    'app/services/EmbudoComercialResultadoBridge.php':'4a60948e006879995b279fab331a1cb822359c2d',
    'public/javascript/embudo_comercial_movimientos.js':'bd37e339a401fb1ec06204237011dc93bd53b7d2'
}
SQL = 'database/migrations/2026_11_09_ventas_etapas_movimientos_fase2a.sql'
SQL_BLOB = '478f3405b956c8801e93af588deec08f5d481054'

def blob(data):
    return hashlib.sha1(('blob %d\0'%len(data)).encode('ascii')+data).hexdigest()

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--site',required=True)
    ap.add_argument('--sql-file')
    args=ap.parse_args()
    site=Path(args.site).resolve()
    if not site.is_dir():
        raise RuntimeError('No se encontró el sitio')
    conflictos=0
    pendientes=0
    aplicados=0
    print('=== PREPARACIÓN DE FASE 2A · SIN ESCRITURAS ===')
    for path,(antes,despues) in MODIFICADOS.items():
        actual=site/path
        if not actual.is_file() or actual.is_symlink():
            print('CONFLICTO: falta o enlace simbólico en '+path)
            conflictos+=1
            continue
        hash_actual=blob(actual.read_bytes())
        if hash_actual==antes:
            print('COMPATIBLE PARA PREPARAR: '+path)
            pendientes+=1
        elif hash_actual==despues:
            print('YA EN VERSIÓN NUEVA: '+path)
            aplicados+=1
        else:
            print('REQUIERE CONCILIACIÓN (PERSONALIZADO): '+path+' git_blob='+hash_actual)
            conflictos+=1
    for path,hash_nuevo in NUEVOS.items():
        actual=site/path
        if not actual.exists():
            print('ARCHIVO NUEVO A PREPARAR: '+path)
            pendientes+=1
        elif actual.is_file() and not actual.is_symlink() and blob(actual.read_bytes())==hash_nuevo:
            print('YA EN VERSIÓN NUEVA: '+path)
            aplicados+=1
        else:
            print('CONFLICTO: archivo nuevo ya está personalizado: '+path)
            conflictos+=1
    if args.sql_file:
        target=Path(args.sql_file)
        if not target.is_file() or blob(target.read_bytes())!=SQL_BLOB:
            print('CONFLICTO: SQL de esta fase con integridad incorrecta')
            conflictos+=1
        else:
            print('INTEGRIDAD SQL OK: sin ejecutar')
    print('RESULTADO: pendientes={}, existentes={}, conflictos={}'.format(
        pendientes,aplicados,conflictos))
    print('CHECK SOLAMENTE: no se modificó el CRM, ni se ejecutó SQL, ni motores.')
    if conflictos:
        print('BLOQUEADO PARA PUBLICACIÓN: reconciliar primero los archivos anteriores.')
        return 2
    print('PREPARABLE: aún falta respaldo, preflight SQL y publicador controlado.')
    return 0

if __name__=='__main__':
    try:
        sys.exit(main())
    except (OSError,RuntimeError) as e:
        print('BLOQUEADO: '+str(e),file=sys.stderr)
        sys.exit(2)
