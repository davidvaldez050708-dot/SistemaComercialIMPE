#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publishes optional advisor V3 to a fake CRM site, never production."""
from __future__ import print_function
import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile

ROOT=os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SCRIPT=os.path.join(ROOT,"tools/publicar_ventas_v3_asesores_hostinger.py")
BASE_SHA="96dca1549c9753a44f45b5505f47e76472a27bfd"
RELEASE_SHA="84e9e5dc2fd27bb72b194e30af64c2e443b8c27a"
FILES={
 "app/services/EmbudoComercialV3MovimientosPermitidos.php":None,
 "app/services/EmbudoComercialV3AccesoControlado.php":None,
 "app/views/embudo_comercial/operativo_v3.php":None,
 "public/css/embudo_v3_operativo.css":None,
 "public/javascript/embudo_comercial_v3_operativo.js":None,
 "app/services/EmbudoComercialRepository.php":"f9f8c7b43a8c12e9a02ece1be1132431fa2fea5b",
 "app/views/embudo_comercial/detalle.php":"e93e64c10088bb6351af3f0412b0d12cfee736a9",
 "app/controllers/EmbudoComercialController.php":"a4342e88288321567487b20f0fa80c79564ad465",
 "app/views/embudo_comercial/index.php":"825b4c6af25450d4be21b47f8666955ada847eed",
}
UNCHANGED={
 "app/services/EmbudoComercialResultadoBridge.php":"6d990a8b12d4856c83e61dc67ad87b34e7767748",
 "app/services/EmbudoComercialMovimientoService.php":"9621a3e2a2b96d0c89ecd597a17c323c4ee09139",
 "app/services/TelefoniaResultadoVentasService.php":"1d4472ba73e7de4097ef2f592104160b254d7f8f",
 "app/services/TelefoniaResultadoVentasCatalogo.php":"6d5396e4dfb7a5c535ed5b8e964d93fab7a04a88",
 "public/javascript/embudo_comercial_movimientos.js":"a282167325a85f1ba153dc9b8c6b29092aa3a209",
 "public/css/embudo_comercial_compacto_v1.css":"c0ea09e7b35ee8c54d61f9386902bb8bcbac2ee4",
 "app/services/EmbudoComercialPoliticaPropuesta.php":"e5ec54f6fac872d09bf534a06debf7e38ee410c1",
 "app/services/EmbudoComercialV3IndicadoresVista.php":"c7419b2521392c48a0eebefb54bc9c7fd1cfda1c",
 "app/views/embudo_comercial/previsualizacion_v3.php":"04f1180034ea0f94ca78fb9eba87a36b96c45fe7",
 "public/css/embudo_v3_indicadores.css":"57ef9790d4868837e16807a0e56faf214c49219d",
}
def check(ok,why):
    if not ok:
        raise RuntimeError("TEST PUBLICADOR V3: "+why)
def gh_blob(data):
    return hashlib.sha1(("blob %d\0"%len(data)).encode("ascii")+data).hexdigest()
def read(path):
    with open(path,"rb") as f:return f.read()
def write(path,data):
    parent=os.path.dirname(path)
    if not os.path.isdir(parent):os.makedirs(parent)
    with open(path,"wb") as f:f.write(data)
def orig(path):
    expected=FILES.get(path) or UNCHANGED.get(path)
    for ref in (BASE_SHA,"ffc44eb23ad7089422d00d21611734f8fe1e6934"):
        try:
            data=subprocess.check_output(["git","show",ref+":"+path],
                cwd=ROOT,stderr=subprocess.DEVNULL)
        except subprocess.CalledProcessError:
            continue
        if gh_blob(data)==expected:
            return data
    raise RuntimeError("No original version matched for "+path)
def call(args):
    proc=subprocess.Popen([sys.executable,SCRIPT]+args,
        stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    out,err=proc.communicate()
    return proc.returncode,out,err

with tempfile.TemporaryDirectory(prefix="impe-v3-publish-test-") as temp:
    site=os.path.join(temp,"site")
    stage=os.path.join(temp,"stage")
    backups=os.path.join(temp,"private-backups")
    os.mkdir(site)
    os.mkdir(stage)
    write(os.path.join(site,"config/db_connection.php"),b"<?php /* test only */")
    for p in list(FILES)+list(UNCHANGED):
        if p in FILES and FILES[p] is None:
            continue
        data=orig(p)
        expected=FILES.get(p) or UNCHANGED.get(p)
        check(gh_blob(data)==expected,"baseline Git not matching expected "+p)
        write(os.path.join(site,p),data)
    for p in FILES:
        write(os.path.join(stage,p),subprocess.check_output(
            ["git","show",RELEASE_SHA+":"+p],cwd=ROOT))
    sentinel=os.path.join(site,"unmodified-sentinel.txt")
    write(sentinel,b"Advisor's normal work is unchanged.")
    base=["--site",site,"--stage",stage]
    check_args=base+["--check"]
    code,out,err=call(check_args)
    check(code==0,"preflight of staging failed "+out+err)
    check("COMPATIBLE: preparado" in out,"check expects base")
    check("PUBLICADO:" not in out,"no side effects on check")

    # Token absent/wrong must block before any changes.
    unauthorized=base+["--backup-root",backups,"--publish"]
    code,out,err=call(unauthorized)
    check(code!=0 and "BLOQUEADO" in err,"require authorization token")
    check(not os.path.exists(backups),"no backup or publication before authorization")

    # Tampering any one of the 6 downloaded files is rejected.
    altered=os.path.join(stage,"public/css/embudo_v3_operativo.css")
    valid=read(altered)
    write(altered,valid+b"\n/* bad edit */\n")
    code,out,err=call(check_args)
    check(code!=0 and "BLOQUEADO" in err,"reject altered download")
    write(altered,valid)
    checked=call(check_args)
    check(checked[0]==0,"valid package restored")

    publish=base+["--backup-root",backups,"--publish",
                   "--confirm","PUBLICAR_SOLO_EMBUDO_ASESOR_V3_IMPE"]
    code,out,err=call(publish)
    check(code==0,"publish failed "+out+err)
    check(out.count("PUBLICADO: ")==9,"exactly nine files, got "+out)
    check("PUBLICACION OK" in out,"postcheck printed")
    for p in FILES:
        check(read(os.path.join(site,p))==read(os.path.join(stage,p)),
              "published exact committed content "+p)
    for p in UNCHANGED:
        check(read(os.path.join(site,p))==orig(p),
              "not modify PBX/telephone/old Kanban/JS/CSS "+p)
    check(read(sentinel)==b"Advisor's normal work is unchanged.",
          "not modify unrelated advisor work")
    dirs=[os.path.join(backups,p) for p in os.listdir(backups)]
    check(len(dirs)==1,"single private backup")
    backup=dirs[0]
    manifest=json.load(open(os.path.join(backup,"manifest.json")))
    check(manifest["estado"]=="PUBLICADO","manifest published")
    for p,v in FILES.items():
        src=os.path.join(backup,p)
        check((os.path.isfile(src) if v else not os.path.exists(src)),
              "backup only originals "+p)
        if v:
            check(gh_blob(read(src))==v,"original file backed up "+p)

    code,out,err=call(check_args)
    check(code==0 and "YA PUBLICADA" in out,"post-deploy check")
    again=call(publish)
    check(again[0]==0 and "SIN CAMBIOS" in again[1],
          "second publish must be idempotent")
    check(len(os.listdir(backups))==1,"no duplicate backup on second publish")

    # Any user changes after publication must BLOCK rollback and preserve them.
    active=os.path.join(site,"app/services/EmbudoComercialRepository.php")
    actual=read(active)
    write(active,actual+b"\n// third-party edit\n")
    rollback=["--site",site,"--rollback","--backup-dir",backup,
              "--confirm","REVERTIR_SOLO_EMBUDO_ASESOR_V3_IMPE"]
    code,out,err=call(rollback)
    check(code!=0 and "BLOQUEADO" in err,
          "never clobber modified files on rollback")
    check(read(active).endswith(b"// third-party edit\n"),
          "third-party work preserved")
    write(active,actual)
    code,out,err=call(rollback)
    check(code==0 and "REVERSION OK" in out,"rollback "+out+err)
    for p,v in FILES.items():
        dest=os.path.join(site,p)
        if v:
            check(os.path.isfile(dest) and gh_blob(read(dest))==v,
                "restore exact old file "+p)
        else:
            check(not os.path.exists(dest),"remove new-only file "+p)
    for p in UNCHANGED:
        check(read(os.path.join(site,p))==orig(p),"PBX file remains intact")
    check(call(check_args)[0]==0,"back to clean baseline")
    print("CHECK OK: preflight, tamper blocking, backup, publish NINE files, ten protected dependencies, idempotency, rollback without overwriting third-party work. Fake-site only.")
