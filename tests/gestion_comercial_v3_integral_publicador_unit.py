#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Fixture CHECK/APPLY/ROLLBACK de la entrega integral sin BD ni producción."""
from __future__ import print_function
import os
import shutil
import subprocess
import sys
import tempfile

ROOT=os.path.dirname(os.path.dirname(os.path.realpath(__file__)))
sys.path.insert(0,os.path.join(ROOT,'tools'))
import publicar_gestion_comercial_v3_integral_hostinger as release

BASE="79f8ccda4f6f525318c7abbbb67324077c175c3c"
RELEASE_ORIGINAL="8ff713383f11a61e7e37d827e2da43ee225ea7a8"
# Simula el paquete original inmutable, NO las fusiones privadas Hostinger.
# Las fusiones se verifican con preparador aparte y CHECK en Hostinger.

def revisar(condition,text):
    if not condition:raise AssertionError('PUBLICADOR_INTEGRAL_V3: '+text)

p=release.p
with tempfile.TemporaryDirectory(prefix="impe_integral_reversible_") as temp:
    stage=os.path.join(temp,'stage')
    site=os.path.join(temp,'public_html')
    backups=os.path.join(temp,'resguardos')
    os.makedirs(os.path.join(site,'config'))
    with open(os.path.join(site,'config/db_connection.php'),'wb') as f:
        f.write(b'<?php // No DB connection, synthetic fixture.\n')
    for path,old,new in p.ARCHIVOS:
        original=subprocess.check_output(
            ['git','show',RELEASE_ORIGINAL+':'+path],cwd=ROOT)
        revisar(p.blob_bytes(original)==new,
                'hash del release inmutable '+path)
        dest=os.path.join(stage,path)
        os.makedirs(os.path.dirname(dest),exist_ok=True)
        with open(dest,'wb') as fd:fd.write(original)
        if old is not None:
            previous=subprocess.check_output(
                ['git','show',BASE+':'+path],cwd=ROOT)
            revisar(p.blob_bytes(previous)==old,'SHA productivo anterior '+path)
            target=os.path.join(site,path)
            os.makedirs(os.path.dirname(target),exist_ok=True)
            with open(target,'wb') as fd:fd.write(previous)
    for path,old in p.NO_MODIFICAR.items():
        actual=os.path.join(ROOT,path)
        revisar(p.blob(actual)==old,'dependencia protegida '+path)
        target=os.path.join(site,path)
        os.makedirs(os.path.dirname(target),exist_ok=True)
        shutil.copy2(actual,target)
    src=os.path.join(ROOT,release.MIGRACION)
    revisar(p.blob(src)==release.MIGRACION_HASH,'migracion exacta')
    dest=os.path.join(stage,release.MIGRACION)
    os.makedirs(os.path.dirname(dest),exist_ok=True)
    shutil.copy2(src,dest)
    release.paquete(stage)
    revisar(p.estado(site)=='BASE','solo acepta base inmutable')
    release.publicar(site,stage,backups)
    revisar(p.estado(site)=='READY','aplicacion de 20 archivos')
    for path,old,new in p.ARCHIVOS:
        revisar(p.blob(os.path.join(site,path))==new,'archivo publicado '+path)
    copies=os.listdir(backups)
    revisar(len(copies)==1,'respaldo privado unico')
    release.revertir(site,os.path.join(backups,copies[0]))
    revisar(p.estado(site)=='BASE','reversion completa')
    for path,old,new in p.ARCHIVOS:
        dest=os.path.join(site,path)
        if old is None:revisar(not os.path.exists(dest),'archivo nuevo retirado '+path)
        else:revisar(p.blob(dest)==old,'codigo base restituido '+path)
    # Toda personalizacion desconocida detiene el plan entero.
    modified=os.path.join(site,'app/controllers/EmbudoComercialController.php')
    with open(modified,'ab') as f:f.write(b'\n// Production customization not expected\n')
    try:
        p.estado(site)
        raise AssertionError('Se permitio sobrescribir un controlador personalizado.')
    except p.Bloqueo:
        pass
    revisar(not os.path.exists(os.path.join(
        site,'app/services/ProspectoNoContactarV3.php')),
        'el fallo no deja servicios a medias')

print('CHECK_OK: paquete integral, 20 SHA exactos, 4 dependencias, 20 rollbacks y conflicto bloqueado.')
