"""Ejercita el publicador únicamente en sitios ficticios temporales, nunca producción.
Uso: python tests/correos_publicador_test.py RUTA_BASH DIRECTORIO_PHP
"""
import hashlib
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[1]
script = root / 'tools/publicar_correos_multiproveedor_hostinger.sh'
bash, phpdir = sys.argv[1:3]
text = script.read_text(encoding='utf-8')
files = re.search(r'^FILES=\((.+)\)$', text, re.M).group(1)
files = re.findall(r'"([^"]+)"', files)
patches = re.findall(r'"([^"]+)"', re.search(r'^PATCH_FILES=\((.+)\)$', text, re.M).group(1))

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() else 'MISSING'

with tempfile.TemporaryDirectory(prefix='impe-publish-mail-') as tmp:
    base = Path(tmp); live = base / 'public_html'; live.mkdir()
    env = os.environ.copy()
    env['PATH'] = phpdir + os.pathsep + env['PATH']
    env['IMPE_CORREOS_PUBLIC'] = live.as_posix()
    env['IMPE_CORREOS_BACKUPS'] = (base / 'backups').as_posix()
    env['IMPE_MAIL_STATE_DIR'] = (base / 'impe-private/mail-state').as_posix()
    for f in patches:
        p = live / f; p.parent.mkdir(parents=True, exist_ok=True)
        b = subprocess.check_output(['git', 'show', 'origin/main:' + f], cwd=root)
        # Comprobar conservación de CRLF y personalizaciones ajenas al transporte.
        if f == 'app/services/MailService.php':
            b = b.replace(b'\r\n', b'\n').replace(b'\n', b'\r\n') + b'\r\n// comentario local conservado\r\n'
        p.write_bytes(b)
    (live / 'index.php').write_text('<?php // sitio ficticio', encoding='utf-8')
    (live / 'vendor').mkdir()
    (live / 'vendor/autoload.php').write_text("<?php require " + repr((root / 'vendor/autoload.php').as_posix()) + ";", encoding='utf-8')
    private = live / 'config/mail_config.local.php'; private.write_text('<?php // CONFIGURACION_PRIVADA_FICTICIA', encoding='utf-8')
    original = {f: digest(live / f) for f in files}; private_hash = digest(private)

    def run(*args, ok=True):
        r = subprocess.run([bash, script.as_posix(), *args], cwd=root, env=env, capture_output=True, text=True, timeout=55)
        if (r.returncode == 0) != ok:
            raise RuntimeError(r.stdout + r.stderr)
        return r.stdout

    run('--check')
    assert {f: digest(live / f) for f in files} == original, '--check no escribe'
    output = run('--apply')
    backup = re.search(r'^Respaldo: (.+)$', output, re.M).group(1)
    installed = {f: digest(live / f) for f in files}
    assert all(v != 'MISSING' for v in installed.values())
    for f in files:
        actual = (live / f).read_bytes().replace(b'\r\n', b'\n')
        expected = (root / f).read_bytes().replace(b'\r\n', b'\n')
        if f == 'app/services/MailService.php':
            actual = actual.replace(b'\n// comentario local conservado\n', b'')
        assert actual == expected, 'Payload desactualizado: ' + f
    assert b'// comentario local conservado' in (live / 'app/services/MailService.php').read_bytes()
    assert digest(private) == private_hash
    assert 'ya aplicado' in run('--apply')
    # Rollback no puede pisar ediciones posteriores.
    changed = live / 'app/services/CorreoEnvioControl.php'; prior = changed.read_bytes()
    changed.write_bytes(prior + b'\n// edicion posterior\n')
    run('--restore', backup, ok=False)
    assert changed.read_bytes().endswith(b'// edicion posterior\n')
    changed.write_bytes(prior)
    run('--restore', backup)
    assert {f: digest(live / f) for f in files} == original, 'restauración exacta'
    assert digest(private) == private_hash
    # Archivo incompatible: falla antes de tocar el sitio.
    p = live / 'app/services/MailService.php'; p.write_text('<?php // versión incompatible', encoding='utf-8')
    before = {f: digest(live / f) for f in files}
    run('--check', ok=False)
    assert {f: digest(live / f) for f in files} == before
    print('OK: check sin escritura, apply/restore exactos, repetición idempotente, CRLF/comentarios/config privada conservados y rechazo de versiones incompatibles o ediciones posteriores. Sitio ficticio.')
