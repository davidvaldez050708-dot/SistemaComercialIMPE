#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Conciliación conservadora de Mi cartera sobre index/sidebar personalizados.

Este archivo no copia ni sobreescribe versiones GitHub de index.php y sidebar.php.
Solo agrega fragmentos conocidos, tras comprobar exactamente los SHA-256
observados en Hostinger y validar anclajes únicos y sintaxis PHP de copias.
Usa después el publicador sellado para respaldar y publicar la entrega completa.
Python 3.6+, PHP CLI. No ejecuta SQL.
"""
import argparse
import base64
import copy
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

import publicar_ventas_mi_cartera as publisher

EXPECTED_LIVE = {
    'index.php': '278d06fa51c5b2ee5ad18acbe696b29dbdfe5facf2aa4de86526c6cf42870948',
    'app/views/layout/sidebar.php': '5f20da7902badda3697dbaa902179f6f1aef9fcf5b0bb6f5a8f1473ba3dd5aab'
}
ORIGINAL_MANIFEST_SHA = '3d81ba3cea37fe9d09ddd88032d93c496fcf6489221373974660de117dfe84f5'
OVERLAY_PATHS = frozenset(EXPECTED_LIVE)
ALLOWED_FILES = frozenset([
    'index.php', 'app/views/layout/sidebar.php',
    'app/controllers/ProspectoController.php',
    'app/controllers/MiCarteraController.php',
    'app/services/MiCarteraRepository.php',
    'app/views/mi_cartera/index.php',
    'app/views/mi_cartera/detalle.php',
    'public/css/mi_cartera.css'
])


def exigir(condicion, mensaje):
    if not condicion:
        raise RuntimeError(mensaje)


def reemplazo_unico(texto, expresion, callback, etiqueta, flags=0):
    coincidencias=list(re.finditer(expresion,texto,flags))
    exigir(len(coincidencias)==1,
           'CONFLICTO: se esperaba un anclaje único %s, encontrados %s; no se modificó nada.' %
           (etiqueta,len(coincidencias)))
    m=coincidencias[0]
    return texto[:m.start()]+callback(m)+texto[m.end():]


def parche_index(contenido):
    exigir('miCartera' not in contenido,
           'CONFLICTO: index.php ya menciona miCartera; requiere conciliación manual.')
    salto='\r\n' if '\r\n' in contenido else '\n'

    def insertar(m):
        espacio=m.group(1)
        agregar=salto.join([
            espacio+"case 'miCartera':",
            '',
            espacio+"    require_once __DIR__ .",
            espacio+"        '/app/controllers/MiCarteraController.php';",
            '',
            espacio+'    $controllerInstance =',
            espacio+'        new MiCarteraController();',
            '',
            espacio+'    break;',
            '',
            '',
        ])
        return agregar+salto+m.group(0)
    return reemplazo_unico(
        contenido,r"(?m)^([ \t]*)case[ \t]+'prospecto'[ \t]*:",
        insertar,"ruta administrativa Prospectos"
    )


def parche_sidebar(contenido):
    exigir('miCartera' not in contenido and '$mostrarMiCartera' not in contenido,
           'CONFLICTO: sidebar.php ya contiene Mi cartera; requiere conciliación manual.')
    salto='\r\n' if '\r\n' in contenido else '\n'

    def permiso(m):
        return salto.join([
            "$mostrarProspectos = tienePermiso('prospectos.ver_todos') &&",
            "    (int)($_SESSION['rol_id']??0)!==3;",
            "$mostrarMiCartera = (int)($_SESSION['rol_id']??0)===3 &&",
            "    tienePermiso('prospectos.mi_cartera');",
        ])
    contenido=reemplazo_unico(
        contenido,
        r"(?m)^\$mostrarProspectos\s*=\s*tienePermiso\(\s*'prospectos\.ver_todos'\s*\);",
        permiso,"control de permiso Prospectos"
    )
    # Las personalizaciones de Hostinger pueden usar otra forma de declarar
    # la clase activa. No tocamos esa definición; la opción nueva calcula
    # su propia clase en el enlace sin necesitar ningún anclaje adicional.
    anchors=list(re.finditer(
        r"(?m)^([ \t]*)<\?php[ \t]+if[ \t]*\([ \t]*\$mostrarMisLlamadas[ \t]*\)[ \t]*:[ \t]*\?>",
        contenido
    ))
    exigir(len(anchors)==2,
           'CONFLICTO: se esperaban dos menús (escritorio/móvil) y se encontraron %s.' % len(anchors))
    # Insertar desde atrás para conservar posiciones de índices.
    for match in reversed(anchors):
        indent=match.group(1)
        menu=salto.join([
            indent+'<?php if ($mostrarMiCartera): ?>',
            indent+'    <div class="sidebar-section">',
            indent+'        <p class="sidebar-section-title">COMERCIAL</p>',
            indent+'        <a href="<?= BASE_URL ?>index.php?controller=miCartera&amp;action=index"',
            indent+'           class="sidebar-link <?= ($opcionActiva ?? \'\') === \'mi_cartera\' ? \'active\' : \'\' ?>">',
            indent+'            <i class="bi bi-person-vcard" aria-hidden="true"></i> Mi cartera',
            indent+'        </a>',
            indent+'    </div>',
            indent+'<?php endif; ?>',
            '',
        ])+salto
        contenido=contenido[:match.start()]+menu+contenido[match.start():]
    return contenido


def validar_php(archivos):
    with tempfile.TemporaryDirectory(prefix='impe-cartera-lint-') as dir_:
        for indice,(nombre,datos) in enumerate(archivos.items()):
            ruta=os.path.join(dir_,str(indice)+'.php')
            with open(ruta,'wb') as fh:
                fh.write(datos)
            try:
                r=subprocess.run(['php','-l',ruta],stdout=subprocess.PIPE,
                    stderr=subprocess.STDOUT,universal_newlines=True)
            except OSError as exc:
                raise RuntimeError('PHP CLI no disponible: %s' % exc)
            exigir(r.returncode==0,'CONFLICTO: sintaxis PHP inválida en '+nombre+': '+r.stdout)
            print('PHP OK:',nombre)


def preparar(sitio,carpeta):
    original=os.path.join(carpeta,publisher.MANIFEST)
    exigir(os.path.isfile(original),'Falta manifiesto original.')
    raw=publisher.read(original)
    exigir(publisher.digest(raw)==ORIGINAL_MANIFEST_SHA,
           'Manifiesto original no coincide con la entrega sellada.')
    manifest=publisher.load_manifest(carpeta)
    exigir(set(i['path'] for i in manifest['files'])==ALLOWED_FILES,
           'La lista de archivos de la entrega cambió; no se publica.')
    exigir(len(manifest['dependencies'])==3,
           'Dependencias diferentes; requiere nueva conciliación.')
    propuesta={}
    for item in manifest['files']:
        ruta=item['path']
        if ruta not in OVERLAY_PATHS:
            continue
        destino=publisher.safe_target(sitio,ruta)
        original_bytes=publisher.read(destino)
        actual=publisher.digest(original_bytes)
        exigir(actual==EXPECTED_LIVE[ruta],
               'CONFLICTO: SHA de %s cambió desde el diagnóstico (esperado %s, actual %s). No se modificó nada.' %
               (ruta,EXPECTED_LIVE[ruta],actual))
        try:
            entrada=original_bytes.decode('utf-8')
        except UnicodeDecodeError:
            raise RuntimeError('CONFLICTO: %s no está en UTF-8.' % ruta)
        nuevo=parche_index(entrada) if ruta=='index.php' else parche_sidebar(entrada)
        nuevo_bytes=nuevo.encode('utf-8')
        exigir(original_bytes!=nuevo_bytes,
               'CONFLICTO: el parche de %s no produjo cambios.' % ruta)
        item['base_sha256']=actual
        item['sha256']=publisher.digest(nuevo_bytes)
        item['payload_b64']=base64.b64encode(nuevo_bytes).decode('ascii')
        item['_payload']=nuevo_bytes
        propuesta[ruta]=nuevo_bytes
    exigir(set(propuesta)==OVERLAY_PATHS,'Faltan archivos para la conciliación.')
    validar_php(propuesta)
    estados,problemas=publisher.inspect(sitio,manifest)
    for ruta,estado,_ in estados:
        print('%s: %s' % (estado,ruta))
    for error in problemas:
        print('ERROR:',error,file=sys.stderr)
    exigir(not problemas,'Preflight fallido, sitio intacto.')
    print('DEPENDENCIAS OK: %s. SQL no ejecutado.' % len(manifest['dependencies']))
    return manifest


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--backup-root')
    actions=parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check',action='store_true')
    actions.add_argument('--apply',action='store_true')
    actions.add_argument('--restore')
    args=parser.parse_args()
    sitio=os.path.realpath(args.site)
    exigir(os.path.isdir(sitio) and not os.path.islink(args.site),
           'Ruta de Hostinger inexistente o simbólica.')
    carpeta=os.path.dirname(os.path.abspath(__file__))
    if args.restore:
        publisher.restore(sitio,args.restore)
        return
    manifest=preparar(sitio,carpeta)
    if args.check:
        print('CHECK CONCILIADO: 2 personalizaciones conservadas; sin escrituras ni respaldo.')
        return
    exigir(args.backup_root,'Para aplicar debes indicar --backup-root fuera de public_html.')
    # El publicador sellado vuelve a hacer su propio preflight antes de escribir.
    with tempfile.TemporaryDirectory(prefix='impe-cartera-conciliada-') as temporal:
        ruta_publicador=os.path.join(temporal,'publicar_ventas_mi_cartera.py')
        shutil.copy2(os.path.join(carpeta,'publicar_ventas_mi_cartera.py'),ruta_publicador)
        version=copy.deepcopy(manifest)
        for item in version['files']:
            item.pop('_payload',None)
        with open(os.path.join(temporal,publisher.MANIFEST),'w',encoding='utf-8') as fh:
            json.dump(version,fh,ensure_ascii=False,indent=2)
        resultado=subprocess.run(
            [sys.executable,ruta_publicador,'--site',sitio,
             '--backup-root',args.backup_root,'--apply']
        )
        exigir(resultado.returncode==0,
               'Publicación cancelada. Examina el mensaje del publicador y su respaldo privado.')
    print('CONCILIACIÓN PUBLICADA: solo se añadieron ruta y menú a los originales de Hostinger.')


if __name__=='__main__':
    try:
        main()
    except Exception as exc:
        print('ERROR:',str(exc),file=sys.stderr)
        sys.exit(1)
