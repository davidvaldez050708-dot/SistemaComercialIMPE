#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Ensayar el puente tarjeta -> formulario PBX automático con datos falsos.
Reconstruye los SHA productivos originales; no toca producción.
"""
from pathlib import Path
import importlib.util
import subprocess
import tempfile
import hashlib

ROOT=Path(__file__).resolve().parents[1]
PUB=ROOT/'tools/publicar_contexto_auto_interesados_hostinger.py'
spec=importlib.util.spec_from_file_location('pub',str(PUB))
pub=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)

GIT_BASE_EMBUDO='f1724383a62b22d22ad3176ae2a38b91259c8618'
GIT_BASE_V4='5304a38208cd72c9b8971f530eda008d41de37e3'
GIT_PROD_SAVER='bc1c6c635fca7ed3b89e61c15aa2edef7064d911'

def must(x,why):
    if not x:raise AssertionError(why)

def git(ref,path):
    p=subprocess.run(['git','show',ref+':'+path],cwd=str(ROOT),
                     stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    must(p.returncode==0,'Git no localizó '+path+' en '+ref)
    return p.stdout

def production_result_js():
    src=git(GIT_BASE_V4,'public/javascript/telefonia_ventas_resultados.js').decode('utf-8')
    marker='   form.elements.pbx_call_id.value=ref;form.elements.extension.value=ownExt;'
    must(src.count(marker)==1,'marcador productivo esperado único')
    patch='''   form.elements.pbx_call_id.value=ref;form.elements.extension.value=ownExt;
    // IMPE_EMBUDO_ASIGNACION_RESULTADO_V1: contexto opt-in del prospecto.
    const asignacionEmbudo = String(root.dataset.salesAssignmentId || '');
    form.elements.prospecto_asignacion_id.value =
      /^[1-9][0-9]*$/.test(asignacionEmbudo) ? asignacionEmbudo : '';'''
    out=src.replace(marker,patch)
    return out.encode('utf-8')

old={
    'public/javascript/embudo_comercial_movimientos.js':
        git(GIT_BASE_EMBUDO,'public/javascript/embudo_comercial_movimientos.js'),
    'public/javascript/telefonia_ventas_resultados.js':production_result_js(),
    'app/services/EmbudoComercialEtapas.php':
        git(GIT_BASE_EMBUDO,'app/services/EmbudoComercialEtapas.php')
}
for rel,(before,after) in pub.FILES.items():
    must(pub.blob(old[rel])==before,'hostinger original diferente '+rel)
    must(pub.blob((ROOT/rel).read_bytes())==after,'fuente publicada diferente '+rel)
must(len(pub.FILES)==3,'lista cerrada: 2 JS y mapeo')
must(len(pub.GUARDS)==2,'servicio PBX y formulario deben permanecer intactos')

with tempfile.TemporaryDirectory(prefix='impe-embudo-auto-form-') as folder:
    base=Path(folder);site=base/'public_html';site.mkdir()
    sources=base/'sources';sources.mkdir()
    backup=base/'backups'
    for rel,raw in old.items():
        path=site/rel;path.parent.mkdir(parents=True,exist_ok=True)
        path.write_bytes(raw)
        source=sources/rel;source.parent.mkdir(parents=True,exist_ok=True)
        source.write_bytes((ROOT/rel).read_bytes())
    guards={}
    for rel,sha in pub.GUARDS.items():
        raw=git(GIT_PROD_SAVER,rel)
        must(pub.blob(raw)==sha,'guardia de producción debe coincidir '+rel)
        path=site/rel;path.parent.mkdir(parents=True,exist_ok=True)
        path.write_bytes(raw);guards[rel]=raw
    other=site/'public/javascript/telefonia_host.js'
    other.write_bytes(b'NO CAMBIAR TELEFONIA WEBRTC')
    observed=pub.scan(site,sources)
    must(all(observed[rel]==v[0] for rel,v in pub.FILES.items()),'check inicial')
    must(not backup.exists(),'check no crea respaldo')
    denied=False
    try:pub.publish(site,sources,backup,None)
    except pub.Blocked:denied=True
    must(denied,'requiere confirmación expresa')
    must(all((site/rel).read_bytes()==raw for rel,raw in old.items()),'no publicación no autorizada')

    pub.publish(site,sources,backup,pub.CONFIRM)
    must(all(pub.blob((site/rel).read_bytes())==v[1] for rel,v in pub.FILES.items()),'tres archivos íntegros')
    must(all((site/rel).read_bytes()==raw for rel,raw in guards.items()),'guardar PBX y formulario intactos')
    must(other.read_bytes()==b'NO CAMBIAR TELEFONIA WEBRTC','motor no se modifica')
    must(len(list(backup.iterdir()))==1,'respaldo privado único')
    backup_dir=next(backup.iterdir())
    pub.publish(site,sources,backup,pub.CONFIRM)
    must(len(list(backup.iterdir()))==1,'idempotencia')
    changed=site/'public/javascript/embudo_comercial_movimientos.js'
    changed.write_bytes(changed.read_bytes()+b'\n//CAMBIO AJENO')
    denied=False
    try:pub.rollback(site,backup_dir,pub.CONFIRM)
    except pub.Blocked:denied=True
    must(denied,'rollback no puede borrar nuevas personalizaciones')
    changed.write_bytes((ROOT/'public/javascript/embudo_comercial_movimientos.js').read_bytes())
    pub.rollback(site,backup_dir,pub.CONFIRM)
    must(all((site/rel).read_bytes()==raw for rel,raw in old.items()),'restauración byte por byte')
    must(all((site/rel).read_bytes()==raw for rel,raw in guards.items()),'guardias no modificadas')
    must(other.read_bytes()==b'NO CAMBIAR TELEFONIA WEBRTC','motor no se modifica al restaurar')
    guard=site/'app/services/TelefoniaResultadoVentasService.php'
    guard.write_bytes(guard.read_bytes()+b'\n//CAMBIO DE OPERACION')
    denied=False
    try:pub.scan(site,sources)
    except pub.Blocked:denied=True
    must(denied,'servicio personalizado debe bloquear')
print('CHECK OK: imágenes SHA Hostinger, solo 3 archivos, no PBX, respaldo y restauración íntegra.')
