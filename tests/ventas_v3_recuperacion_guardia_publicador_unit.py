#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Prueba aislada CHECK/APPLY/ROLLBACK de la guardia V3 sobre una copia temporal.
No puede conectarse a ninguna BD ni modificar la instalación de Hostinger.
"""
from __future__ import print_function
import os
import shutil
import subprocess
import sys
import tempfile

ROOT=os.path.dirname(os.path.dirname(os.path.realpath(__file__)))
sys.path.insert(0,os.path.join(ROOT,"tools"))
import publicar_ventas_v3_proteccion_recuperacion_hostinger as g

def check(ok, msg):
    if not ok:
        raise RuntimeError("GUARDIA_PUBLICADOR: "+msg)

bridge="app/services/EmbudoComercialResultadoBridge.php"
base_ref="49646e45226c57ace2f7c31035723495ce8a374d"
base=subprocess.check_output(["git","show",base_ref+":"+bridge],cwd=ROOT)
check(g.p.blob_bytes(base)==g.p.ARCHIVOS[0][1],"base conciliada exacta")
new_path=os.path.join(ROOT,bridge)
check(g.p.blob(new_path)==g.p.ARCHIVOS[0][2],"destino exacto")

with tempfile.TemporaryDirectory(prefix="impe_guardia_v3_test_") as tmp:
    site=os.path.join(tmp,"public_html")
    stage=os.path.join(tmp,"stage")
    backup=os.path.join(tmp,"respaldo_privado")
    os.makedirs(os.path.join(site,"config"))
    with open(os.path.join(site,"config/db_connection.php"),"wb") as f:
        f.write(b"<?php // No connection; fixture only.\n")
    for folder in [site,stage]:
        os.makedirs(os.path.join(folder,os.path.dirname(bridge)),exist_ok=True)
    with open(os.path.join(site,bridge),"wb") as f:
        f.write(base)
    shutil.copy2(new_path,os.path.join(stage,bridge))
    for rel,hash_expected in g.p.NO_MODIFICAR.items():
        src=os.path.join(ROOT,rel)
        check(g.p.blob(src)==hash_expected,"dependencia protegida "+rel)
        dst=os.path.join(site,rel)
        os.makedirs(os.path.dirname(dst),exist_ok=True)
        shutil.copy2(src,dst)

    g.validar_paquete(stage)
    check(g.p.estado(site)=="BASE","base reconocida")
    g.p.publicar(site,stage,backup)
    check(g.p.estado(site)=="READY","cambio único aplicado a fixture")
    check(g.p.blob(os.path.join(site,bridge))==g.p.ARCHIVOS[0][2],
          "SHA nuevo de bridge")
    respaldos=os.listdir(backup)
    check(len(respaldos)==1,"un solo respaldo privado")
    g.p.revertir(site,os.path.join(backup,respaldos[0]))
    check(g.p.estado(site)=="BASE","rollback exacto")
    check(g.p.blob(os.path.join(site,bridge))==g.p.ARCHIVOS[0][1],
          "recupera puente original")

    # Si Hostinger tiene personalizaciones inesperadas, ni CHECK ni APPLY pisan.
    with open(os.path.join(site,bridge),"ab") as f:
        f.write(b"\n# personalizado\n")
    try:
        g.p.estado(site)
        raise RuntimeError("Se permitió sobrescribir una personalización.")
    except g.p.Bloqueo:
        pass
    for rel,hash_expected in g.p.NO_MODIFICAR.items():
        check(g.p.blob(os.path.join(site,rel))==hash_expected,
              "sin alteraciones en dependencias "+rel)

print("CHECK_OK: guardia V3 únicamente bridge, bloqueo personalizado y rollback sobre fixture aislado.")
