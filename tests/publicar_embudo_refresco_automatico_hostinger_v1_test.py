#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador de refresco del embudo: fixtures sin DB/PBX ni Hostinger."""
import importlib.util
import subprocess
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location(
    'embudo_refresh_pub',str(ROOT/'tools/publicar_embudo_refresco_automatico_hostinger_v1.py'))
pub=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)

BASE='37e4b7031208790b5111dfc4124db445d1f44cfe'
def assert_(condition,label):
    if not condition:raise AssertionError(label)
def git(path):
    p=subprocess.run(['git','show',BASE+':'+path],cwd=str(ROOT),
        stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert_(p.returncode==0,'No disponible git histórico: '+path)
    return p.stdout

expected=[
    'public/javascript/embudo_comercial_movimientos.js',
    'public/javascript/telefonia_ventas_resultados.js'
]
assert_(list(pub.FILES)==expected,'Lista blanca debe tener únicamente dos JS')
assert_(len(pub.GUARDS)==4,'Se requieren cuatro guardias protegidas')
assert_(pub.GUARDS['public/javascript/telefonia_shell.js']==
    'f20aab1ffc39384b0894b752fa6fbebf49773ce3',
    'Shell productivo auditado como guardia inalterable')

original={};updated={}
for path,(before,after) in pub.FILES.items():
    original[path]=git(path)
    updated[path]=(ROOT/path).read_bytes()
    assert_(pub.blob(original[path])==before,'SHA anterior no coincide: '+path)
    assert_(pub.blob(updated[path])==after,'SHA nuevo no coincide: '+path)

assert_(b'impe:embudo:resultado-guardado:' in updated[expected[0]],
        'El tablero no escucha el cambio del resultado')
assert_(b'impe:embudo:resultado-guardado:' in updated[expected[1]],
        'El guardado no comunica el resultado')
assert_(b'getPhoneStatus' in updated[expected[0]],
        'Protección de llamada activa eliminada')

guard_payloads={
    p:((ROOT/p).read_bytes() if p!='public/javascript/telefonia_shell.js'
       else b'// shell fixture; telefonia real se conserva\n')
    for p in pub.GUARDS
}
original_guards=pub.GUARDS.copy()
try:
    pub.GUARDS={p:pub.blob(raw) for p,raw in guard_payloads.items()}
    with tempfile.TemporaryDirectory(prefix='impe-embudo-refresh-') as tmp:
        root=Path(tmp);site=root/'public_html';site.mkdir()
        sources=root/'fuentes';backup=root/'backups'
        for p,raw in original.items():
            dst=site/p;dst.parent.mkdir(parents=True,exist_ok=True);dst.write_bytes(raw)
        for p,raw in updated.items():
            dst=sources/p;dst.parent.mkdir(parents=True,exist_ok=True);dst.write_bytes(raw)
        for p,raw in guard_payloads.items():
            dst=site/p;dst.parent.mkdir(parents=True,exist_ok=True);dst.write_bytes(raw)

        first=pub.scan(site,sources)
        assert_(first=={p:x[0] for p,x in pub.FILES.items()},'Inspección inexacta')
        assert_(not backup.exists(),'--check no debe crear respaldos')
        try:pub.publish(site,sources,backup,'INVALIDO')
        except pub.Blocked:pass
        else:raise AssertionError('Publicación sin confirmación')

        pub.publish(site,sources,backup,pub.CONFIRM)
        assert_(all((site/p).read_bytes()==raw for p,raw in updated.items()),
            'Se incumplió publicación de dos JS')
        assert_(all((site/p).read_bytes()==raw for p,raw in guard_payloads.items()),
            'Se alteró un archivo protegido')
        backups=list(backup.iterdir())
        assert_(len(backups)==1,'Falta respaldo único')
        pub.publish(site,sources,backup,pub.CONFIRM)
        assert_(len(list(backup.iterdir()))==1,'Aplicación duplicada creó respaldo extra')

        tampered=site/expected[0]
        tampered.write_bytes(updated[expected[0]]+b'\n// cambio externo')
        try:pub.rollback(site,backups[0],pub.CONFIRM)
        except pub.Blocked:pass
        else:raise AssertionError('Rollback ignoró cambio externo')
        assert_(tampered.read_bytes().endswith(b'cambio externo'),
            'Se eliminó personalización ajena')
        tampered.write_bytes(updated[expected[0]])
        pub.rollback(site,backups[0],pub.CONFIRM)
        assert_(all((site/p).read_bytes()==raw for p,raw in original.items()),
            'Rollback no restauró originales')
        assert_(all((site/p).read_bytes()==raw for p,raw in guard_payloads.items()),
            'Rollback alteró guardias')

        (site/expected[1]).write_bytes(original[expected[1]]+b'\n// personalizacion')
        try:pub.scan(site,sources)
        except pub.Blocked:pass
        else:raise AssertionError('Se aceptó personalización no auditada')
finally:
    pub.GUARDS=original_guards

print('CHECK OK: 2 JS, 4 guardias, SHA, respaldo, idempotencia, personalización y rollback; sin producción.')
