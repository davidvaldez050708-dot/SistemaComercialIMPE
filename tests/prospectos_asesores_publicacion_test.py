#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Pruebas de publicación sellada en directorios temporales. Sin MySQL."""
import base64
import gzip
import hashlib
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
RELEASE = json.loads((ROOT/'tools/prospectos_asesores_1a_release.json').read_text(encoding='utf8'))
PREV = json.loads((ROOT/'tools/prospectos_campanias_release.json').read_text(encoding='utf8'))
checks = 0

# Huella independiente proporcionada desde Hostinger el 06/11/2026.
# En la ejecución aislada se sustituye la dependencia por datos ficticios
# solo después de verificar que el manifiesto público usa el SHA real.
ROL_SHA_HOSTINGER = '51678bbbaa437d278859dc33d25319b808dcd48b21e2018cd3a75cb50b4d6992'
assert next(d['sha256'] for d in RELEASE['dependencies']
            if d['path']=='app/models/RolModel.php') == ROL_SHA_HOSTINGER, (
                'RolModel.php debe conservar el SHA original de Hostinger.'
            )

def check(value, msg):
    global checks
    if not value:
        raise AssertionError(msg)
    checks += 1

def sha(data):
    return hashlib.sha256(data).hexdigest()

def write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)

def run(tool, site, args, ok=True):
    p = subprocess.run([sys.executable,str(tool),'--site',str(site)] + args,
                       stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    if (p.returncode == 0) != ok:
        raise AssertionError('Salida inesperada del publicador: %s %s %s' %
                             (' '.join(args),p.stdout,p.stderr))
    return p.stdout+p.stderr

with tempfile.TemporaryDirectory(prefix='impe-asesores-release-test-') as tmp:
    temp = pathlib.Path(tmp)
    site = temp/'site'
    tool_dir = temp/'tools'
    backups = temp/'backups'
    site.mkdir(); tool_dir.mkdir()
    tool = tool_dir/'publicar_prospectos_asesores_1a.py'
    shutil.copy2(str(ROOT/'tools/publicar_prospectos_asesores_1a.py'),str(tool))
    release = json.loads(json.dumps(RELEASE))
    # Sólo para el fixture: la instalación real usa el SHA de RolModel suministrado.
    fixtures = {
        'public/css/prospectos_central.css':None,
        'app/models/RolModel.php':b'RolModel personalizado de prueba, no reemplazar',
    }
    for prev in PREV['files']:
        if prev['path'] in fixtures and fixtures[prev['path']] is None:
            fixtures[prev['path']] = gzip.decompress(base64.b64decode(prev['target']))
    for dep in release['dependencies']:
        data = fixtures[dep['path']]
        write(site/dep['path'],data)
        dep['sha256'] = sha(data)
    original = {}
    for item in release['files']:
        path = item['path']
        if item['base_sha256'] is None:
            (site/path).parent.mkdir(parents=True, exist_ok=True)
            continue
        past = next(p for p in PREV['files'] if p['path']==path)
        raw = gzip.decompress(base64.b64decode(past['target']))
        check(sha(raw)==item['base_sha256'],'Base no corresponde al manifiesto anterior: '+path)
        original[path]=raw
        write(site/path,raw)
    (tool_dir/'prospectos_asesores_1a_release.json').write_text(
        json.dumps(release,ensure_ascii=False,indent=2),encoding='utf8')
    before = {i['path']:(site/i['path']).read_bytes() if (site/i['path']).exists() else None for i in release['files']}
    out = run(tool,site,['--check'])
    check('CHECK COMPATIBLE' in out,'check previo sin escrituras')
    check(all(((site/p).read_bytes() if (site/p).exists() else None)==v for p,v in before.items()),'check sin alterar archivos')
    check(not backups.exists(),'check no crea respaldos')
    out = run(tool,site,['--apply','--backup-root',str(backups)])
    check('PUBLICADO: 5 archivos' in out,'aplicación selectiva')
    for i in release['files']:
        check(sha((site/i['path']).read_bytes())==i['sha256'],'objetivo exacto: '+i['path'])
    check(sha((site/'app/models/RolModel.php').read_bytes())==release['dependencies'][1]['sha256'],'RolModel sin cambios')
    check('ALREADY APPLIED' in run(tool,site,['--apply','--backup-root',str(backups)]),'idempotencia')
    dirs = list(backups.iterdir())
    check(len(dirs)==1,'un respaldo')
    out=run(tool,site,['--restore',str(dirs[0])])
    check('RESTORE COMPLETO' in out,'restauración completa')
    for p,val in before.items():
        current=(site/p).read_bytes() if (site/p).exists() else None
        check(current==val,'restauración exacta: '+p)
    # Dependencia personalizada no coincide: bloquear todo, sin escribir.
    (site/'app/models/RolModel.php').write_bytes(b'Cambio no autorizado')
    check('Dependencia diferente' in run(tool,site,['--check'],ok=False),'rechazo RolModel distinto')
    (site/'app/models/RolModel.php').write_bytes(fixtures['app/models/RolModel.php'])
    # Un archivo alterado también bloquea todos los destinos.
    old = (site/'app/controllers/ProspectoController.php').read_bytes()
    (site/'app/controllers/ProspectoController.php').write_bytes(b'Personalizacion concurrente')
    check('Conflicto:' in run(tool,site,['--apply','--backup-root',str(backups)],ok=False),'rechaza variante desconocida')
    (site/'app/controllers/ProspectoController.php').write_bytes(old)
    # Manifiesto manipulado: la integridad del destino se comprueba antes de inspeccionar.
    broken=json.loads(json.dumps(release))
    broken['files'][0]['sha256']='0'*64
    (tool_dir/'prospectos_asesores_1a_release.json').write_text(json.dumps(broken),encoding='utf8')
    check('Integridad inválida' in run(tool,site,['--check'],ok=False),'manifiesto manipulado')
    check(len(list(backups.iterdir()))==1,'ningún respaldo adicional tras errores')
print('OK: %d comprobaciones de paquete sellado, check, apply, restore e incompatibilidad.' % checks)
