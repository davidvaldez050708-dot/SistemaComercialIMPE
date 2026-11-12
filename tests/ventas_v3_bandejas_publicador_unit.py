#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Prueba de publicador en copia efímera: sin DB, credenciales ni Hostinger."""
from __future__ import print_function
import os
import shutil
import subprocess
import sys
import tempfile

BASE=os.path.dirname(os.path.dirname(os.path.realpath(__file__)))
sys.path.insert(0,os.path.join(BASE,'tools'))
import publicar_ventas_v3_bandejas_hostinger as p

def check(cond,msg):
    if not cond:raise AssertionError("PUBLICADOR_BANDEJAS_V3: "+msg)

ref="b5dfe589061e900ca9da2ab5303ccd7f93db80b7"
published_ref="79f8ccda4f6f525318c7abbbb67324077c175c3c"
# La versión publicada se valida contra el commit original, no HEAD.
original_release={}
for ruta,old,new in p.p.ARCHIVOS:
    original_release[ruta]=subprocess.check_output(
        ['git','show',published_ref+':'+ruta],cwd=BASE)
    check(p.p.blob_bytes(original_release[ruta])==new,
          "SHA histórico publicado "+ruta)
    if old is not None:
        base=subprocess.check_output(['git','show',ref+':'+ruta],cwd=BASE)
        check(p.p.blob_bytes(base)==old,"base exacta "+ruta)
for ruta,sha in p.p.NO_MODIFICAR.items():
    check(p.p.blob(os.path.join(BASE,ruta))==sha,"dependencia exacta "+ruta)

with tempfile.TemporaryDirectory(prefix='impe_bandejas_test_') as tmp:
    site=os.path.join(tmp,'public_html')
    stage=os.path.join(tmp,'stage')
    back=os.path.join(tmp,'backups')
    os.makedirs(os.path.join(site,'config'))
    with open(os.path.join(site,'config/db_connection.php'),'wb') as f:
        f.write(b'<?php // isolated test fixture\n')
    for ruta,old,new in p.p.ARCHIVOS:
        dest=os.path.join(stage,ruta)
        os.makedirs(os.path.dirname(dest),exist_ok=True)
        with open(dest,'wb') as file:
            file.write(original_release[ruta])
        if old is not None:
            original=os.path.join(site,ruta)
            os.makedirs(os.path.dirname(original),exist_ok=True)
            with open(original,'wb') as f:
                f.write(subprocess.check_output(['git','show',ref+':'+ruta],cwd=BASE))
    for ruta,sha in p.p.NO_MODIFICAR.items():
        dest=os.path.join(site,ruta)
        os.makedirs(os.path.dirname(dest),exist_ok=True)
        shutil.copy2(os.path.join(BASE,ruta),dest)
    p.paquete(stage)
    check(p.p.estado(site)=='BASE','estado base completo')
    p.p.publicar(site,stage,back)
    check(p.p.estado(site)=='READY','entrega aplicada solo a copia')
    for ruta,old,new in p.p.ARCHIVOS:
        check(p.p.blob(os.path.join(site,ruta))==new,'publicado '+ruta)
    for ruta,sha in p.p.NO_MODIFICAR.items():
        check(p.p.blob(os.path.join(site,ruta))==sha,'protege '+ruta)
    dirs=os.listdir(back)
    check(len(dirs)==1,'un respaldo único')
    p.p.revertir(site,os.path.join(back,dirs[0]))
    check(p.p.estado(site)=='BASE','rollback completo')
    for ruta,old,new in p.p.ARCHIVOS:
        dest=os.path.join(site,ruta)
        if old is None:check(not os.path.exists(dest),'retira archivo nuevo '+ruta)
        else:check(p.p.blob(dest)==old,'restaura original '+ruta)
    # Un solo archivo inesperado debe impedir el CHECK, sin tocar otros.
    modified=os.path.join(site,'app/controllers/EmbudoComercialController.php')
    with open(modified,'ab') as f:f.write(b'\n// Unexpected custom modification')
    try:
        p.p.estado(site)
        raise AssertionError('No bloqueó personalización del controlador.')
    except p.p.Bloqueo:
        pass
    check(not os.path.exists(os.path.join(site,
        'app/services/EmbudoComercialV3BandejasService.php')),
        'no instalar nueva capa al fallar el CHECK')
print('CHECK_OK: nueve archivos exactos, rollback, dependencias protegidas y personalización bloqueada.')
