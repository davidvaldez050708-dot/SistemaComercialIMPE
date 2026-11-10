#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Pruebas fuera de Hostinger para el publicador de un único puente."""
from pathlib import Path
import importlib.util
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PUBLISH=ROOT/'tools/publicar_puente_embudo_pbx_mexico_hostinger.py'
BRIDGE_REF='861ee00e144f2b5f7600547d5307cf1216cb4b1a'
BASE='44ebcdb69c56cf89ec7e4a3f85e35275ebaa2bca'
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
approved_git=subprocess.run(
    ['git','show',BRIDGE_REF+':'+pub.REL],cwd=str(ROOT),
    stdout=subprocess.PIPE,stderr=subprocess.PIPE)
must(approved_git.returncode==0,'no se encontró fuente histórica aprobada')
must(pub.blob(approved_git.stdout)==pub.AFTER,'blob nuevo histórico esperado')

with tempfile.TemporaryDirectory(prefix='impe-pbx-mx-') as d:
    root=Path(d)
    BRIDGE=root/'bridge_aprobado_historico.php'
    BRIDGE.write_bytes(approved_git.stdout)
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
    must(pub.baseline(site,target,BRIDGE)==before,'check debe leer sin cambiar')
    must(not backups.exists(),'check no crea respaldo')
    must(untouched.read_bytes()==b'UNTOUCHED','no tocar Telefonía')

    # Confirmación manual obligatoria, independientemente de la actividad.
    denied=False
    try:pub.apply(site,target,BRIDGE,backups,None)
    except pub.Blocked:denied=True
    must(denied and target.read_bytes()==original,'apply sin confirmación bloqueado')
    must(not backups.exists(),'sin confirmación no crea respaldo')

    # Escenario real: operar con asesores activos, con aceptación explícita.
    pub.apply(site,target,BRIDGE,backups,pub.CONFIRM_ACTIVE)
    must(pub.blob(target.read_bytes())==pub.AFTER,'publicar puente íntegro')
    must(untouched.read_bytes()==b'UNTOUCHED','telefonía no tocada')
    must(len(list(backups.iterdir()))==1,'un respaldo exacto')
    folder=next(backups.iterdir())
    must(pub.blob((folder/'puente_original.php').read_bytes())==pub.BEFORE,
         'respaldo original exacto')
    must((folder/'manifest.json').is_file(),'manifiesto privado')

    # Idempotencia. No generar respaldos ni nuevos cambios.
    pub.apply(site,target,BRIDGE,backups,pub.CONFIRM)
    must(len(list(backups.iterdir()))==1,'segundo apply no duplica backup')

    denied=False
    try:pub.rollback(site,target,folder,None)
    except pub.Blocked:denied=True
    must(denied and pub.blob(target.read_bytes())==pub.AFTER,
         'rollback sin confirmación bloqueado')
    denied=False
    try:pub.apply(site,target,BRIDGE,backups,'ACEPTO_RIESGO')
    except pub.Blocked:denied=True
    must(denied and pub.blob(target.read_bytes())==pub.AFTER,
         'confirmación ambigua no debe publicar')
    denied=False
    try:pub.rollback(site,target,folder,'ACEPTO_RIESGO')
    except pub.Blocked:denied=True
    must(denied and pub.blob(target.read_bytes())==pub.AFTER,
         'confirmación ambigua no debe restaurar')

    # No sobrescribir una personalización posterior o una edición concurrente.
    customized=BRIDGE.read_bytes()+b'\n// PERSONALIZADO EN HOSTINGER\n'
    target.write_bytes(customized)
    denied=False
    try:pub.baseline(site,target,BRIDGE)
    except pub.Blocked:denied=True
    must(denied and target.read_bytes()==customized,'personalizado no se sobreescribe')
    denied=False
    try:pub.rollback(site,target,folder,pub.CONFIRM)
    except pub.Blocked:denied=True
    must(denied and target.read_bytes()==customized,'rollback no pisa cambios nuevos')

    target.write_bytes(BRIDGE.read_bytes())
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
must("REL = 'app/services/EmbudoComercialResultadoBridge.php'" in src,
     'solo archivo comercial')
for forbidden in ('TRUNCATE ', 'DELETE FROM ', 'UPDATE telefonia_', 'DROP TABLE',
                  "systemctl ", "service php", 'opcache_reset', 'mysqli'):
    must(forbidden not in src,'operación prohibida en el publicador: '+forbidden)
print('CHECK OK: único puente PBX, check lectura, backup, apply opt-in y rollback seguro.')
