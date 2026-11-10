#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicación simulada con imágenes exactas de los archivos que existen en Hostinger."""
from pathlib import Path
import importlib.util
import tempfile
from hostinger_v4_personalizaciones_fixtures import OBSERVED,production

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/publicar_ventas_v4_contacto_seguimiento_reportes_hostinger.py'
spec=importlib.util.spec_from_file_location("pub",str(SCRIPT))
pub=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)

def require(cond,msg):
    if not cond:raise AssertionError(msg)

require(len(pub.FILES)==4,'solo cuatro archivos que necesitan actualización')
require(len(pub.GUARDS)==2,'el servicio y formulario productivos solo se verifican')
for rel,(before,after) in pub.FILES.items():
    require(before==OBSERVED[rel],'hash de producción no conciliado: '+rel)
    require(pub.blob((ROOT/rel).read_bytes())==after,'fuente publicada diferente: '+rel)
for rel,expected in pub.GUARDS.items():
    require(expected==OBSERVED[rel],'guardia de personalización incorrecta')
    require((ROOT/rel).read_bytes()==production(rel),'guardia idéntica al Hostinger real')

with tempfile.TemporaryDirectory(prefix='impe-ventas-v4-exacto-') as temp:
    base=Path(temp)
    site=base/'public_html';site.mkdir()
    sources=base/'source';sources.mkdir()
    backups=base/'backup'
    original={}
    for rel in OBSERVED:
        old=production(rel)
        p=site/rel;p.parent.mkdir(parents=True,exist_ok=True)
        p.write_bytes(old)
        original[rel]=old
    for rel in pub.FILES:
        p=sources/rel;p.parent.mkdir(parents=True,exist_ok=True)
        p.write_bytes((ROOT/rel).read_bytes())
    telephone=site/'public/javascript/telefonia_host.js'
    telephone.parent.mkdir(parents=True,exist_ok=True)
    telephone.write_bytes(b'RUNTIME_TELEFONO_INTACTO')

    # 1) Comprobación sin escritura ni respaldos.
    states=pub.scan(site,sources)
    require(all(states[p]==hashes[0] for p,hashes in pub.FILES.items()),'estado original')
    require(not backups.exists(),'preflight no debe escribir')

    # 2) Token incorrecto rechaza la publicación.
    blocked=False
    try:pub.publish(site,sources,backups,'PUBLICAR_SIN_CONFIRMACION')
    except pub.Blocked:blocked=True
    require(blocked,'token inválido no rechazado')
    require(all((site/p).read_bytes()==old for p,old in original.items()),'rechazo no debe escribir')

    # 3) Conservación de personalizaciones y guardado.
    pub.publish(site,sources,backups,pub.CONFIRM)
    require(all(pub.blob((site/p).read_bytes())==new for p,(_,new) in pub.FILES.items()),'publicación íntegra')
    for rel in pub.GUARDS:require((site/rel).read_bytes()==original[rel],'NO reemplazar guardado ni formulario PBX')
    require(telephone.read_bytes()==b'RUNTIME_TELEFONO_INTACTO','no tocar teléfono')
    require(len(list(backups.iterdir()))==1,'un respaldo privado')
    folder=next(backups.iterdir())

    # 4) Segunda ejecución idempotente.
    pub.publish(site,sources,backups,pub.CONFIRM)
    require(len(list(backups.iterdir()))==1,'no crear backups al repetir')

    # 5) No sobrescribir un trabajo concurrente.
    customized=site/'app/views/telefonia/index.php'
    customized.write_bytes(customized.read_bytes()+b'\n//PERSONALIZACION POSTERIOR')
    blocked=False
    try:pub.rollback(site,folder,pub.CONFIRM)
    except pub.Blocked:blocked=True
    require(blocked,'no revertir una personalización ajena')
    customized.write_bytes((ROOT/'app/views/telefonia/index.php').read_bytes())

    # 6) Rollback reproduce EXACTAMENTE los SHA del sitio original.
    pub.rollback(site,folder,pub.CONFIRM)
    require(all((site/p).read_bytes()==data for p,data in original.items()),'rollback no restauró byte por byte')
    require(telephone.read_bytes()==b'RUNTIME_TELEFONO_INTACTO','rollback no toca telefonía')

    # 7) Una personalización nueva en el guardado impide cualquier publicación.
    guard=site/'app/services/TelefoniaResultadoVentasService.php'
    guard.write_bytes(guard.read_bytes()+b'\n//OPERACION_ASESORA')
    blocked=False
    try:pub.scan(site,sources)
    except pub.Blocked:blocked=True
    require(blocked,'personalización en guardado debería bloquear')

    # 8) Un cambio en el reporte bloquea antes de publicar.
    guard.write_bytes(original['app/services/TelefoniaResultadoVentasService.php'])
    report=site/'app/services/TelefoniaControlService.php'
    report.write_bytes(report.read_bytes()+b'\n//CAMBIO_AJENO')
    blocked=False
    try:pub.scan(site,sources)
    except pub.Blocked:blocked=True
    require(blocked,'no sobrescribir el reporte personalizado')
print('CHECK OK: seis SHA reales, cuatro archivos actualizados, servicio/formulario intactos, backup y rollback seguro.')
