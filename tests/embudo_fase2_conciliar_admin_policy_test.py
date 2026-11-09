#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""AdminRolePolicy compartida: fusionar sólo las restricciones comerciales."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PUBLISH=ROOT/'tools/publicar_embudo_fase2a_hostinger.py'
BASE='62f57c06352ad637a283a90fee234e038aab0f75'
POL='app/helpers/AdminRolePolicy.php'

def require(ok,why):
    if not ok:raise AssertionError(why)

def gitshow(path):
    p=subprocess.run(['git','show',BASE+':'+path],cwd=str(ROOT),
                     stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    require(p.returncode==0,'git fuente '+path)
    return p.stdout

spec=importlib.util.spec_from_file_location('publish',str(PUBLISH))
pub=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)
old=gitshow(POL)
require(pub.blob(old)==pub.CHANGES[POL][0],'base policy')
new=pub.merge_admin_policy(old)
require(pub.blob(new)==pub.CHANGES[POL][1],'version normal coincide con GitHub')
require(pub.merge_admin_policy(new)==new,'merge idempotente')

# Personalización de otra área añadida DESPUÉS de la fase 1
extra=b"            'convenios.auditoria.adicional',\n"
anchor=b"            'prospectos.importar_meta',\n"
require(old.count(anchor)==1,'anchor único')
site_custom=old.replace(anchor,anchor+extra)
revised=pub.merge_admin_policy(site_custom)
require(extra in revised,'personalización ajena conservada')
require(revised.replace(
    b"            'ventas.embudo.mover',\n"
    b"            'ventas.recuperacion.gestionar',\n"
    b"            'ventas.descartados.gestionar',\n", b'')==site_custom,
    'únicamente se agregan tres permisos')
pub.syntax(revised,POL)
require(pub.merge_admin_policy(revised)==revised,'personalización idempotente')

# Instalación parcial con 1 de los 3 permisos nuevos: sólo se agregan 2.
only_one=site_custom.replace(anchor,anchor+b"            'ventas.embudo.mover',\n")
partial=pub.merge_admin_policy(only_one)
require(partial.count(b"'ventas.embudo.mover'")==1,'no duplica permisos')
require(partial.count(b"'ventas.recuperacion.gestionar'")==1,'recuperacion presente')
require(partial.count(b"'ventas.descartados.gestionar'")==1,'descartados presente')

# Una política dinámica o con cambios semánticos NO se modifica.
dynamic=site_custom.replace(anchor,anchor+b"            PERMISOS_EXTRAS,\n")
blocked=False
try:pub.merge_admin_policy(dynamic)
except pub.Conflicto:blocked=True
require(blocked,'protege política dinámica')
missing=site_custom.replace(b"'telefonia.usar',",b"'telefonia.desconocida',")
blocked=False
try:pub.merge_admin_policy(missing)
except pub.Conflicto:blocked=True
require(blocked,'anclas ausentes bloquean')

with tempfile.TemporaryDirectory(prefix='impe-admin-policy-merge-') as d:
    site=Path(d)/'public_html'
    backups=Path(d)/'backups'
    site.mkdir()
    for path in pub.CHANGES:
        file=site/path
        file.parent.mkdir(parents=True,exist_ok=True)
        file.write_bytes(site_custom if path==POL else gitshow(path))
    for path in pub.NEW:
        (site/path).parent.mkdir(parents=True,exist_ok=True)
    before=(site/POL).read_bytes()
    plan=pub.preparar(site,ROOT)
    require(len(plan)==10,'10 archivos con AdminPolicy personalizada')
    require((site/POL).read_bytes()==before,'check solo lectura')
    require(plan[POL][1]==revised,'publicador conserva modificaciones locales')
    pub.publicar(site,backups,plan)
    require((site/POL).read_bytes()==revised,'apply conserva personalización')
    require(len(list(backups.iterdir()))==1,'crea respaldo privado')
    backup=next(backups.iterdir())/POL
    require(backup.read_bytes()==site_custom,'respaldo conserva archivo anterior')
    require(len(pub.preparar(site,ROOT))==0,'postcheck, idempotente')
print('CHECK OK: AdminPolicy personalizada conservada, 3 permisos, respaldos y doble check.')
