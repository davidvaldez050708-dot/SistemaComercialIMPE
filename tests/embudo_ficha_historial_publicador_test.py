#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Simulación en directorio temporal: 5 archivos, sólo cambios autorizados."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PUBLISH=ROOT/'tools/publicar_embudo_ficha_historial_hostinger.py'
BASE='28cfcc5977f38fa9998f3a10209b6d5632126d0a'
APPROVED='55fca2f79cbbefdef25b350cf3fa940909ef62dd' # cierre histórico de ficha

def must(ok,msg):
    if not ok:raise AssertionError(msg)

def show(path):
    p=subprocess.run(['git','show',BASE+':'+path],cwd=str(ROOT),
        stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    must(p.returncode==0,'Git no proporcionó fichero fase2A '+path)
    return p.stdout

spec=importlib.util.spec_from_file_location('publisher',str(PUBLISH))
pub=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)

with tempfile.TemporaryDirectory(prefix='impe-ficha-historial-fake-') as temp:
    site=Path(temp)/'public_html'
    backups=Path(temp)/'respaldos'
    site.mkdir()
    old={}
    for path,(before,after) in pub.EXISTING.items():
        dest=site/path
        dest.parent.mkdir(parents=True,exist_ok=True)
        old[path]=show(path)
        must(pub.blob(old[path])==before,'Hash original incorrecto: '+path)
        dest.write_bytes(old[path])
    for path in pub.NEW:
        (site/path).parent.mkdir(parents=True,exist_ok=True)
    # Este publicador pertenece a una revisión histórica inmutable.
    # Simular fuentes aprobadas de su commit, no del HEAD cambiante.
    reference=Path(temp)/'approved'
    for path in list(pub.EXISTING)+list(pub.NEW):
        result=subprocess.run(['git','show',APPROVED+':'+path],
            cwd=str(ROOT),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        must(result.returncode==0,'No se localizó fuente histórica '+path)
        expected=(pub.EXISTING[path][1] if path in pub.EXISTING else pub.NEW[path])
        must(pub.blob(result.stdout)==expected,'Fuente aprobada distinta '+path)
        approved_path=reference/path
        approved_path.parent.mkdir(parents=True,exist_ok=True)
        approved_path.write_bytes(result.stdout)
    plan=pub.preparar(site,reference)
    must(len(plan)==5,'Cinco ficheros pendientes')
    must(not backups.exists(),'Check no crea respaldos')
    for path,raw in old.items():
        must((site/path).read_bytes()==raw,'Check alteró archivo')
    blocked=False
    try:pub.installed_db(site,None,None)
    except pub.Conflicto:blocked=True
    must(blocked,'Apply debe exigir preflight BD')
    pub.publicar(site,backups,plan)
    must(len(list(backups.iterdir()))==1,'Debe crear un solo respaldo')
    manifest=next(backups.iterdir())/'manifest.json'
    must(manifest.is_file(),'Manifiesto presente')
    for path,raw in old.items():
        must((manifest.parent/path).read_bytes()==raw,'Respaldo preserva original '+path)
    must(not pub.preparar(site,reference),'Segundo check debe ser idempotente')
    for path in list(pub.EXISTING)+list(pub.NEW):
        expected=pub.EXISTING[path][1] if path in pub.EXISTING else pub.NEW[path]
        must(pub.blob((site/path).read_bytes())==expected,'Código publicado íntegro '+path)

    # Una customización posterior impide reemplazo y no modifica el archivo.
    path='app/views/embudo_comercial/detalle.php'
    altered=(site/path).read_bytes()+b'\n<!-- LOCAL CUSTOMIZATION -->\n'
    (site/path).write_bytes(altered)
    blocked=False
    try:pub.preparar(site,reference)
    except pub.Conflicto:blocked=True
    must(blocked and (site/path).read_bytes()==altered,
         'Personalización bloqueada sin cambio')

    # Una copia symlink de cualquier archivo tampoco se sustituye.
    target=site/'app/services/EmbudoComercialPresentacion.php'
    target.unlink()
    target.symlink_to(site/'app/views/embudo_comercial/index.php')
    blocked=False
    try:pub.preparar(site,reference)
    except pub.Conflicto:blocked=True
    must(blocked,'Symlink bloqueado')
print('CHECK OK: cinco archivos, integridad, respaldo privado, idempotencia y bloqueo de conflictos.')
