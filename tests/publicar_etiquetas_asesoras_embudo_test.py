#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Pruebas aisladas del publicador de etiquetas comerciales del historial."""
from pathlib import Path
import importlib.util
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PUBLISH=ROOT/'tools/publicar_etiquetas_asesoras_embudo_hostinger.py'
VIEW=ROOT/'app/views/embudo_comercial/detalle.php'
BASE='f1724383a62b22d22ad3176ae2a38b91259c8618'
spec=importlib.util.spec_from_file_location('pub',str(PUBLISH))
pub=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)

def must(value,why):
    if not value: raise AssertionError(why)

git=subprocess.run(['git','show',BASE+':'+pub.REL],cwd=str(ROOT),
    stdout=subprocess.PIPE,stderr=subprocess.PIPE)
must(git.returncode==0,'no se encontró blob del puente original')
original=git.stdout
must(pub.blob(original)==pub.BEFORE,'blob previo esperado')
must(pub.blob(VIEW.read_bytes())==pub.AFTER,'blob nuevo esperado')

with tempfile.TemporaryDirectory(prefix='impe-pbx-mx-') as d:
    root=Path(d)
    site=root/'public_html'
    target=site/pub.REL
    target.parent.mkdir(parents=True)
    target.write_bytes(original)
    untouched=site/'public'/'javascript'/'telefonia_ventas_resultados.js'
    untouched.parent.mkdir(parents=True)
    untouched.write_bytes(b'UNTOUCHED')
    backups=root/'backups'

    # Preflight nunca modifica nada, no crea respaldos ni reinicia servicios.
    before=target.read_bytes()
    must(pub.baseline(site,target,VIEW)==before,'check debe leer sin cambiar')
    must(not backups.exists(),'check no crea respaldo')
    must(untouched.read_bytes()==b'UNTOUCHED','no tocar Telefonía')

    # Confirmación manual obligatoria, independientemente de la actividad.
    denied=False
    try:pub.apply(site,target,VIEW,backups,None)
    except pub.Blocked:denied=True
    must(denied and target.read_bytes()==original,'apply sin confirmación bloqueado')
    must(not backups.exists(),'sin confirmación no crea respaldo')

    # Escenario real: operar con asesores activos, con aceptación explícita.
    pub.apply(site,target,VIEW,backups,pub.CONFIRM_ACTIVE)
    must(pub.blob(target.read_bytes())==pub.AFTER,'publicar puente íntegro')
    must(untouched.read_bytes()==b'UNTOUCHED','telefonía no tocada')
    must(len(list(backups.iterdir()))==1,'un respaldo exacto')
    folder=next(backups.iterdir())
    must(pub.blob((folder/'detalle_original.php').read_bytes())==pub.BEFORE,
         'respaldo original exacto')
    must((folder/'manifest.json').is_file(),'manifiesto privado')

    # Idempotencia. No generar respaldos ni nuevos cambios.
    pub.apply(site,target,VIEW,backups,pub.CONFIRM)
    must(len(list(backups.iterdir()))==1,'segundo apply no duplica backup')

    denied=False
    try:pub.rollback(site,target,folder,None)
    except pub.Blocked:denied=True
    must(denied and pub.blob(target.read_bytes())==pub.AFTER,
         'rollback sin confirmación bloqueado')
    denied=False
    try:pub.apply(site,target,VIEW,backups,'ACEPTO_RIESGO')
    except pub.Blocked:denied=True
    must(denied and pub.blob(target.read_bytes())==pub.AFTER,
         'confirmación ambigua no debe publicar')
    denied=False
    try:pub.rollback(site,target,folder,'ACEPTO_RIESGO')
    except pub.Blocked:denied=True
    must(denied and pub.blob(target.read_bytes())==pub.AFTER,
         'confirmación ambigua no debe restaurar')

    # No sobrescribir una personalización posterior o una edición concurrente.
    customized=VIEW.read_bytes()+b'\n// PERSONALIZADO EN HOSTINGER\n'
    target.write_bytes(customized)
    denied=False
    try:pub.baseline(site,target,VIEW)
    except pub.Blocked:denied=True
    must(denied and target.read_bytes()==customized,'personalizado no se sobreescribe')
    denied=False
    try:pub.rollback(site,target,folder,pub.CONFIRM)
    except pub.Blocked:denied=True
    must(denied and target.read_bytes()==customized,'rollback no pisa cambios nuevos')

    target.write_bytes(VIEW.read_bytes())
    pub.rollback(site,target,folder,pub.CONFIRM_ACTIVE)
    must(target.read_bytes()==original,'restauración exacta del original')
    must(untouched.read_bytes()==b'UNTOUCHED','rollback tampoco toca telefonía')
    pub.rollback(site,target,folder,pub.CONFIRM)
    must(target.read_bytes()==original,'rollback idempotente')

    target.unlink()
    target.symlink_to(untouched)
    denied=False
    try:pub.site_target(site)
    except pub.Blocked:denied=True
    must(denied,'bloquear archivo simbólico')

src=PUBLISH.read_text(encoding='utf-8')
must("REL = 'app/views/embudo_comercial/detalle.php'" in src,
     'solo archivo comercial')
for forbidden in ('TRUNCATE ', 'DELETE FROM ', 'UPDATE telefonia_', 'DROP TABLE',
                  "systemctl ", "service php", 'opcache_reset', 'mysqli'):
    must(forbidden not in src,'operación prohibida en el publicador: '+forbidden)
print('CHECK OK: único historial de movimientos legible, check lectura, backup, apply opt-in y rollback seguro.')
