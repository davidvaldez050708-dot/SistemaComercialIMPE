#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Ensayo con archivos ficticios. Nunca abre DB, ni toca Hostinger."""
from pathlib import Path
import importlib.util
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PUB=ROOT/'tools/publicar_ventas_v4_contacto_seguimiento_reportes_hostinger.py'
BASE='5304a38208cd72c9b8971f530eda008d41de37e3'
spec=importlib.util.spec_from_file_location('pub',str(PUB))
pub=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)

def need(ok,msg):
    if not ok:raise AssertionError(msg)

with tempfile.TemporaryDirectory(prefix='impe-ventas-v4-fake-') as tmp:
    root=Path(tmp)
    site=root/'public_html';site.mkdir()
    source=root/'source';source.mkdir()
    backup=root/'backups'
    old={}
    for rel,(before,after) in pub.FILES.items():
        previous=subprocess.run(['git','show',BASE+':'+rel],cwd=str(ROOT),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        need(previous.returncode==0,'histórico GitHub no disponible '+rel)
        need(pub.blob(previous.stdout)==before,'SHA previo '+rel)
        now=(ROOT/rel).read_bytes()
        need(pub.blob(now)==after,'SHA aprobado '+rel)
        target=site/rel;target.parent.mkdir(parents=True,exist_ok=True)
        target.write_bytes(previous.stdout)
        src=source/rel;src.parent.mkdir(parents=True,exist_ok=True)
        src.write_bytes(now)
        old[rel]=previous.stdout
    untouched=site/'public/javascript/telefonia_persistente.js'
    untouched.parent.mkdir(parents=True,exist_ok=True)
    untouched.write_bytes(b'PHONE_RUNTIME_UNTOUCHED')
    checked=pub.scan(site,source)
    need(all(checked[k]==v[0] for k,v in pub.FILES.items()),'check no modifica')
    need(not backup.exists(),'check no genera respaldos')
    refused=False
    try:pub.publish(site,source,backup,'CONFIRMACION_INCORRECTA')
    except pub.Blocked:refused=True
    need(refused,'publicación sin token bloqueada')
    need(all((site/k).read_bytes()==data for k,data in old.items()),'sin cambios no autorizados')
    pub.publish(site,source,backup,pub.CONFIRM)
    need(all(pub.blob((site/k).read_bytes())==v[1] for k,v in pub.FILES.items()),'cuatro archivos correctos')
    need(untouched.read_bytes()==b'PHONE_RUNTIME_UNTOUCHED','teléfono persistente intacto')
    need(len(list(backup.iterdir()))==1,'respaldo privado único')
    folder=next(backup.iterdir())
    need((folder/'manifest.json').is_file(),'manifiesto')
    pub.publish(site,source,backup,pub.CONFIRM)
    need(len(list(backup.iterdir()))==1,'republicación idempotente')
    target=site/'app/views/telefonia/index.php'
    target.write_bytes(target.read_bytes()+b'\n/* CAMBIO LOCAL */\n')
    refused=False
    try:pub.rollback(site,folder,pub.CONFIRM)
    except pub.Blocked:refused=True
    need(refused,'rollback no pisa cambios locales')
    target.write_bytes((ROOT/'app/views/telefonia/index.php').read_bytes())
    pub.rollback(site,folder,pub.CONFIRM)
    need(all((site/k).read_bytes()==data for k,data in old.items()),'rollback exacto')
    need(untouched.read_bytes()==b'PHONE_RUNTIME_UNTOUCHED','rollback no toca runtime PBX')
    target=site/'app/services/TelefoniaResultadoVentasCatalogo.php'
    target.write_bytes(target.read_bytes()+b'\n/* PERSONALIZADO */')
    refused=False
    try:pub.scan(site,source)
    except pub.Blocked:refused=True
    need(refused,'version no coincidente bloqueada')
    need(not any((site/k).read_bytes()==(ROOT/k).read_bytes()
                 for k in pub.FILES),'no publicación parcial')
print('CHECK OK: SHA V4, cuatro archivos, pruebas en copia, backup, bloqueo y rollback seguro.')
