#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Pruebas de seguridad del ajuste aislado en el sidebar existente."""
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
TOOL=ROOT/'tools/corregir_ancho_gestion_comercial_hostinger.py'
BASE='34de9e8291af01e137f077762093e2e6d44efb78'

def require(value,reason):
    if not value: raise AssertionError(reason)

def run(site,backup,mode):
    args=[sys.executable,str(TOOL),'--site',str(site),mode]
    if mode=='--apply':
        args+=['--backup-root',str(backup)]
    return subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)

p=subprocess.run(['git','show',BASE+':app/views/layout/sidebar.php'],
    cwd=str(ROOT),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
require(p.returncode==0,'commit anterior disponible')
# Reproducir exactamente ambos publicadores previos sobre el sidebar base
# para recrear el archivo instalado en Hostinger, no el de la rama de desarrollo.
original_tool=ROOT/'tools/publicar_embudo_comercial_hostinger.py'
prev_tool=ROOT/'tools/ajustar_sidebar_gestion_comercial_hostinger.py'
old_spec=importlib.util.spec_from_file_location('sidebar_original',str(original_tool))
original_publisher=importlib.util.module_from_spec(old_spec)
old_spec.loader.exec_module(original_publisher)
prev_spec=importlib.util.spec_from_file_location('sidebar_previo',str(prev_tool))
prev_publisher=importlib.util.module_from_spec(prev_spec)
prev_spec.loader.exec_module(prev_publisher)
initial=original_publisher.patch_sidebar(p.stdout.decode('utf-8')).encode('utf-8')
source=prev_publisher.parchear_menu(initial)
spec=importlib.util.spec_from_file_location('sidebar_tamano',str(TOOL))
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
require(source.count(mod.OLD_NAV.encode('utf-8'))==2,'ambos enlaces previos')
with tempfile.TemporaryDirectory() as tmp:
    site=Path(tmp)/'public_html'
    backup=Path(tmp)/'respaldo'
    target=site/mod.FILE
    target.parent.mkdir(parents=True,exist_ok=True)
    original=source+b'\n<!-- PERSONALIZACION AJENA INTACTA -->\n'
    target.write_bytes(original)
    result=run(site,backup,'--check')
    require(result.returncode==0,'check sin errores: '+result.stderr)
    require(target.read_bytes()==original,'check no modifica')
    require(not backup.exists(),'check no crea backups')
    result=run(site,backup,'--apply')
    require(result.returncode==0,'apply con respaldo: '+result.stderr)
    changed=target.read_bytes()
    require(changed.count(mod.NEW_NAV.encode('utf-8'))==2,
        'entrada directa por menu, sin doble contenedor')
    require(changed.count(mod.OLD_NAV.encode('utf-8'))==0,'se retiraron secciones estrechas')
    require('PERSONALIZACION AJENA INTACTA' in changed.decode('utf-8'),'personalizaciones')
    # Cambio solo en bloque de Gestion, no alterar Mi cartera ni otros enlaces
    require(changed==original.replace(mod.OLD_NAV.encode('utf-8'),mod.NEW_NAV.encode('utf-8')),
        'diff exacto en ambos enlaces')
    require(run(site,backup,'--check').returncode==0,'check idempotente')
    require(run(site,backup,'--apply').returncode==0,'apply idempotente')
    directories=list(backup.iterdir())
    require(len(directories)==1,'no crea respaldo al repetir')
    require((directories[0]/mod.FILE).read_bytes()==original,'respaldo conserva original')
    # Rechazar edición posterior sin escribir
    target.write_bytes(changed.replace('Gestión comercial'.encode('utf-8'),b'Otro nombre',1))
    modified=target.read_bytes()
    result=run(site,backup,'--check')
    require(result.returncode!=0,'bloquea conflicto parcial')
    require(target.read_bytes()==modified,'conflicto sin escrituras')
print('CHECK OK: igual ancho, integridad del menú, idempotencia y respaldo.')
