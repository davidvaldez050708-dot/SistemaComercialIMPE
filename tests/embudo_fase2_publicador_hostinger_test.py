#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicación Fase 2A simulada, no toca Hostinger ni DB real."""
import importlib.util
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/publicar_embudo_fase2a_hostinger.py'
BASE='62f57c06352ad637a283a90fee234e038aab0f75'
def must(ok,reason):
    if not ok:raise AssertionError(reason)

def gitshow(path,revision=BASE):
    p=subprocess.run(['git','show',revision+':'+path],cwd=str(ROOT),
        stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    must(p.returncode==0,'git show '+path+' '+p.stderr.decode('utf-8','replace'))
    return p.stdout

spec=importlib.util.spec_from_file_location('publisher',str(SCRIPT))
publisher=importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)
with tempfile.TemporaryDirectory(prefix='impe-embudo-fase2a-fake-') as d:
    site=Path(d)/'public_html'
    backup=Path(d)/'backups'
    approved=Path(d)/'approved_phase2a_snapshot'
    site.mkdir()
    for path in list(publisher.CHANGES)+list(publisher.NEW):
        item=approved/path
        item.parent.mkdir(parents=True,exist_ok=True)
        item.write_bytes(gitshow(path,publisher.REV))
        assert publisher.blob(item.read_bytes())==(publisher.CHANGES[path][1] if path in publisher.CHANGES else publisher.NEW[path])
    old={}
    for path in publisher.CHANGES:
        target=site/path
        target.parent.mkdir(parents=True,exist_ok=True)
        old[path]=gitshow(path)
        must(publisher.blob(old[path])==publisher.CHANGES[path][0],
             'hash fase 1 no coincide '+path)
        target.write_bytes(old[path])
    for path in publisher.NEW:
        (site/path).parent.mkdir(parents=True,exist_ok=True)
    planned=publisher.preparar(site,approved)
    must(len(planned)==10,'diez archivos previstos')
    must(not backup.exists(),'preparación no crea respaldo')
    for path,raw in old.items():
        must((site/path).read_bytes()==raw,'check no cambia '+path)
    # Ningún apply debe publicarse sin comprobar la migración instalada.
    denied=False
    try:publisher.installed_db(site,None,None)
    except publisher.Conflicto:denied=True
    must(denied,'bloquear apply sin preflight DB')
    publisher.publicar(site,backup,planned)
    must(len(list(backup.iterdir()))==1,'respaldo de una operación')
    must(not publisher.preparar(site,approved),'después de aplicar no quedan cambios')
    for path,new in publisher.NEW.items():
        must(publisher.blob((site/path).read_bytes())==new,
             'nuevo archivo exacto '+path)
    for path,(oldblob,new) in publisher.CHANGES.items():
        must(publisher.blob((site/path).read_bytes())==new,
             'archivo actualizado exacto '+path)
    manifest=next(backup.iterdir())/'manifest.json'
    must(manifest.is_file(),'respaldo con manifiesto')
    for path,original in old.items():
        must((manifest.parent/path).read_bytes()==original,
             'respaldo conserva anterior '+path)
    # Una personalización nueva bloquea sin intentar sustituirla.
    target=site/'app/services/EmbudoComercialRepository.php'
    target.write_bytes(target.read_bytes()+b'\n/*PERSONALIZACION*/\n')
    personalized=target.read_bytes()
    denied=False
    try:publisher.preparar(site,approved)
    except publisher.Conflicto:denied=True
    must(denied and target.read_bytes()==personalized,
         'bloquear sobreescritura de personalizaciones')
print('CHECK OK: 10 archivos GitHub íntegros, sin SQL, respaldos, idempotencia, bloqueos.')
