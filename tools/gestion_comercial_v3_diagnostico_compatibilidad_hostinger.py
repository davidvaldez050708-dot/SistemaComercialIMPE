#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Auditar personalizaciones de Hostinger SIN ESCRIBIR en public_html.
Lee solo hashes y metadatos de código; no lee datos de prospectos ni credenciales
de la base de datos. No publica ni hace copias de archivos productivos.
Compatible con Python 3.6 y con el publicador integral versionado.
"""
from __future__ import print_function
import argparse
import difflib
import hashlib
import os
import re
import subprocess
import sys

import publicar_gestion_comercial_v3_integral_hostinger as release

REPO="davidvaldez050708-dot/SistemaComercialIMPE"
BASE_REV="79f8ccda4f6f525318c7abbbb67324077c175c3c"
PHONE="app/services/TelefoniaMarcacionesVentasService.php"

def sha_blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii")+data).hexdigest()

def observar(site,rel,old,new):
    target=os.path.join(site,*rel.split("/"))
    if os.path.islink(target):
        return "ENLACE_NO_PERMITIDO","-"
    if not os.path.exists(target):
        return ("AUSENTE_NUEVO" if old is None else "AUSENTE_EXISTENTE"),"-"
    if not os.path.isfile(target):
        return "NO_ES_ARCHIVO","-"
    try:
        actual=release.p.blob(target)
    except (OSError,ValueError) as exc:
        return "NO_LEIBLE","-"
    if actual==new:
        return "YA_PUBLICADO",actual
    if old is not None and actual==old:
        return "BASE_OK",actual
    return "PERSONALIZADO",actual

def metodo(lineas,position):
    patron=re.compile(r"^\s*(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(")
    for i in range(min(position,len(lineas)-1),-1,-1):
        match=patron.search(lineas[i])
        if match:return match.group(1)
    return "<fuera_de_metodo>"

def diagnosticar_phone(site,expected):
    target=os.path.join(site,*PHONE.split("/"))
    if os.path.islink(target) or not os.path.isfile(target):
        print("DIFF_METODOS: no puede obtenerse la versión productiva.")
        return
    url="https://raw.githubusercontent.com/{}/{}/{}".format(REPO,BASE_REV,PHONE)
    try:
        previous=subprocess.check_output(["curl","-fsSL","--retry","2",url])
        if sha_blob(previous)!=expected:
            print("DIFF_METODOS: base remota no coincide con hash esperado.")
            return
        with open(target,"rb") as f:now=f.read()
        old_lines=previous.decode("utf-8","replace").splitlines()
        new_lines=now.decode("utf-8","replace").splitlines()
        ops=[x for x in difflib.SequenceMatcher(
            None,old_lines,new_lines,autojunk=False).get_opcodes()
            if x[0]!="equal"]
        print("CAMBIOS_TELEFONIA: {} bloques distintos".format(len(ops)))
        for tag,i,j,k,l in ops[:40]:
            print("CAMBIO | {} | base:{}-{} | sitio:{}-{} | metodo_base:{} | metodo_sitio:{}".format(
                tag,i+1,j,k+1,l,metodo(old_lines,i),metodo(new_lines,k)))
        if len(ops)>40:print("CAMBIOS_ADICIONALES: {}".format(len(ops)-40))
    except (OSError,subprocess.CalledProcessError) as exc:
        print("DIFF_METODOS: fallo descargando la base de GitHub; inventario SHA íntegro.")

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--site",required=True)
    ap.add_argument("--stage",required=True)
    args=ap.parse_args()
    site=os.path.realpath(args.site)
    stage=os.path.realpath(args.stage)
    if not os.path.isdir(site) or not os.path.isfile(
        os.path.join(site,"config/db_connection.php")):
        raise SystemExit("BLOQUEADO: raíz de Hostinger incorrecta.")
    if not os.path.isfile(os.path.join(
        stage,"tools/publicar_gestion_comercial_v3_integral_hostinger.py")):
        raise SystemExit("BLOQUEADO: carpeta de paquete incorrecta.")
    print("INVENTARIO_INTEGRAL_V3_SOLO_LECTURA")
    print("NO_SE_ESCRIBE_EN_PRODUCCION")
    mismatches=[]
    for rel,old,new in release.p.ARCHIVOS:
        status,sha=observar(site,rel,old,new)
        if status not in ("BASE_OK","YA_PUBLICADO","AUSENTE_NUEVO"):
            mismatches.append((rel,status,sha))
        print("{} | {} | SHA={}".format(status,rel,sha))
    for rel,expected in release.p.NO_MODIFICAR.items():
        status,sha=observar(site,rel,expected,expected)
        if status not in ("BASE_OK","YA_PUBLICADO"):
            mismatches.append((rel,status,sha))
        print("DEPENDENCIA_{} | {} | SHA={}".format(status,rel,sha))
    print("TOTAL_ARCHIVOS_REVISADOS: {}".format(
        len(release.p.ARCHIVOS)+len(release.p.NO_MODIFICAR)))
    print("DESVIACIONES_ENCONTRADAS: {}".format(len(mismatches)))
    for rel,status,sha in mismatches:
        print("REQUIERE_CONCILIACION | {} | {} | {}".format(rel,status,sha))
    expected=next((old for rel,old,new in release.p.ARCHIVOS if rel==PHONE),None)
    if expected is not None and any(x[0]==PHONE for x in mismatches):
        diagnosticar_phone(site,expected)
    print("SIN_SQL_SIN_PUBLICACION_SIN_CONTACTOS")

if __name__=="__main__":
    main()
