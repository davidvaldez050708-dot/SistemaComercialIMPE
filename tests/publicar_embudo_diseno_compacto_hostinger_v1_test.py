#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Regresión del diseño compacto: archivos ficticios, sin Hostinger ni MySQL."""
import importlib.util
import json
import os
import stat
import subprocess
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/publicar_embudo_diseno_compacto_hostinger_v1.py'
spec=importlib.util.spec_from_file_location('embudo_ui_publisher',str(SCRIPT))
pub=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)


def need(value,msg):
    if not value:raise AssertionError(msg)


def historic(relative):
    p=subprocess.run(['git','show','b8f77a52cb1f7e716ecd2d47b063e15bbf370866:'+relative],
                     cwd=str(ROOT),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    need(p.returncode==0,'Base histórica no existe: '+relative)
    return p.stdout


old=historic(pub.VIEW)
view=(ROOT/pub.VIEW).read_bytes()
css=(ROOT/pub.CSS).read_bytes()
need(pub.blob(old)==pub.OLD_VIEW,'Hash base de la vista no coincide')
need(pub.blob(view)==pub.NEW_VIEW,'Hash nuevo de vista no coincide')
need(pub.blob(css)==pub.NEW_CSS,'Hash nuevo de CSS no coincide')

old_text=old.decode('utf-8')
new_text=view.decode('utf-8')
stylesheet=css.decode('utf-8')
need(old_text.count('class="impe-embudo-hero"')==1,'No existe un encabezado duplicado único')
need('class="impe-embudo-hero"' not in new_text,'Se dejó el encabezado blanco repetido')
need('impe-embudo--compact' in new_text,'El CSS no queda encapsulado al tablero')
need(new_text.count('embudo_comercial_compacto_v1.css')==2,'No se incluye CSS con versión de caché')
need('hash_file(' in new_text,'Falta invalidación de caché para nuevos estilos')
need('repeat(5, minmax(0, 1fr))' in stylesheet,'Las cinco etapas no son responsivas')
need('min-width: 0' in stylesheet,'Columnas no pueden ajustarse')
need('overflow-x: auto' in stylesheet,'Falta desplazamiento en pantallas reducidas')
need('scroll-snap-type: x proximity' in stylesheet,'Falta alineación al desplazar')
need('impe-embudo-board--single' in stylesheet,'Se ignoraron Recuperación/Descartados')
need('.impe-embudo--compact' in stylesheet,'El CSS afecta a otros módulos')
for marker in ['data-embudo-marcar','data-mover-tarjeta',
               'impe-embudo-dialog','prospecto_asignacion_id',
               'role="region"','aria-label="Vistas comerciales"']:
    if marker=='prospecto_asignacion_id':
        continue # ID comercial en URL, no campo de la vista.
    need(marker in new_text,'Componente funcional perdido: '+marker)

with tempfile.TemporaryDirectory(prefix='impe-embudo-ui-test-') as tmp:
    root=Path(tmp)
    site=root/'public_html'
    site.mkdir()
    sources=root/'fuentes'
    backup=root/'backups'
    for rel,data in [(pub.VIEW,old),('public/css/embudo_comercial.css',b'/* CSS hostinger personalizado */')]:
        f=site/rel;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(data)
    for rel,data in [(pub.VIEW,view),(pub.CSS,css)]:
        f=sources/rel;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(data)
    before=site.joinpath(pub.VIEW).read_bytes()
    _,_,is_old=pub.inspect(site,sources)
    need(is_old,'No reconoce publicación anterior')
    need(site.joinpath(pub.VIEW).read_bytes()==before,'Inspección alteró sitio')
    need(not backup.exists(),'Inspección creó respaldos')
    try:pub.publish(site,sources,backup,'')
    except pub.Blocked:pass
    else:raise AssertionError('Se aplicó sin autorización')
    need(not backup.exists(),'Se creó respaldo sin autorización')

    pub.publish(site,sources,backup,pub.CONFIRM)
    need(site.joinpath(pub.VIEW).read_bytes()==view,'Vista no fue instalada')
    need(site.joinpath(pub.CSS).read_bytes()==css,'CSS aislado no fue instalado')
    need(site.joinpath('public/css/embudo_comercial.css').read_bytes()==b'/* CSS hostinger personalizado */',
         'Se alteró CSS personalizado')
    need(stat.S_IMODE((site/pub.CSS).stat().st_mode)==0o644,'CSS estático debe servir HTTP 200')
    packs=list(backup.iterdir())
    need(len(packs)==1,'Falta respaldo privado exacto')
    need(stat.S_IMODE(packs[0].stat().st_mode)==0o700,'Respaldo no es privado')
    _,_,is_old=pub.inspect(site,sources)
    need(not is_old,'No reconoce publicación ya aplicada')
    pub.publish(site,sources,backup,pub.CONFIRM)
    need(len(list(backup.iterdir()))==1,'Repetición creó respaldo duplicado')

    site.joinpath(pub.CSS).write_bytes(css+b'\n/* cambio personalizado */')
    try:pub.rollback(site,packs[0],pub.CONFIRM)
    except pub.Blocked:pass
    else:raise AssertionError('Rollback borró estilo posterior de Hostinger')
    need(site.joinpath(pub.CSS).read_bytes().endswith(b'cambio personalizado */'),
         'Rollback alteró personalización de estilo')
    site.joinpath(pub.CSS).write_bytes(css)
    pub.rollback(site,packs[0],pub.CONFIRM)
    need(site.joinpath(pub.VIEW).read_bytes()==old,'Rollback no devolvió vista')
    need(not site.joinpath(pub.CSS).exists(),'Rollback no quitó CSS nuevo')
    need(site.joinpath('public/css/embudo_comercial.css').read_bytes()==b'/* CSS hostinger personalizado */',
         'Rollback dañó CSS existente')
    site.joinpath(pub.VIEW).write_bytes(old+b'\n<!-- modificado -->')
    try:pub.inspect(site,sources)
    except pub.Blocked:pass
    else:raise AssertionError('No detectó cambio concurrente de Hostinger')

print('CHECK OK: vista sin hero, 5 columnas, scroll adaptativo, cache, modal y botones, SHA, respaldo, idempotencia y rollback.')
