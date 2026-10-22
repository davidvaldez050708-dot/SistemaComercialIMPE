"""Ejercita el publicador únicamente en sitios ficticios temporales, nunca producción.
Uso: python tests/correos_publicador_test.py RUTA_BASH DIRECTORIO_PHP [REVISION_BASE] [--sin-diagnostico]
"""
import hashlib
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[1]
script = root / 'tools/publicar_correo_comercial_hostinger.sh'
bash, phpdir = sys.argv[1:3]
without_diagnostic = '--sin-diagnostico' in sys.argv[3:]
diagnostic = 'tools/diagnosticar_hostinger_comercial.php'
baseline = sys.argv[3] if len(sys.argv)>3 and not sys.argv[3].startswith('--') else 'a90b6aded4a7f606eccc7a27749658e3fed9b9ed'
text = script.read_text(encoding='utf-8')
files = re.search(r'^FILES=\((.+)\)$', text, re.M).group(1)
files = re.findall(r'"([^"]+)"', files)
patches = re.findall(r'"([^"]+)"', re.search(r'^PATCH_FILES=\((.+)\)$', text, re.M).group(1))

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() else 'MISSING'

with tempfile.TemporaryDirectory(prefix='impe-publish-mail-') as tmp:
    base = Path(tmp); live = base / 'public_html'; live.mkdir()
    (live / 'app/controllers').mkdir(parents=True)
    env = os.environ.copy()
    env['PATH'] = phpdir + os.pathsep + env['PATH']
    env['IMPE_CORREOS_PUBLIC'] = live.as_posix()
    env['IMPE_CORREOS_BACKUPS'] = (base / 'backups').as_posix()
    env['IMPE_MAIL_STATE_DIR'] = (base / 'impe-private/mail-state').as_posix()
    for f in patches:
        if without_diagnostic and f == diagnostic:
            continue
        p = live / f; p.parent.mkdir(parents=True, exist_ok=True)
        b = subprocess.check_output(['git', 'show', baseline + ':' + f], cwd=root)
        # Comprobar conservación de CRLF y personalizaciones ajenas al transporte.
        if f == 'app/services/CorreoSalidaInstitucionalService.php':
            b = b.replace(b'\r\n', b'\n').replace(b'\n', b'\r\n') + b'\r\n// comentario local conservado\r\n'
        p.write_bytes(b)
    if True:
        for f in files:
            if f in patches:
                continue
            old = subprocess.run(['git', 'show', baseline + ':' + f], cwd=root, capture_output=True)
            if old.returncode == 0:
                p = live / f; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(old.stdout)
    if not (live / 'index.php').exists():
        (live / 'index.php').write_text('<?php // sitio ficticio', encoding='utf-8')
    (live / 'vendor').mkdir()
    (live / 'vendor/autoload.php').write_text("<?php require " + repr((root / 'vendor/autoload.php').as_posix()) + ";", encoding='utf-8')
    private = live / 'config/mail_config.local.php'; private.parent.mkdir(parents=True,exist_ok=True)
    private.write_text('<?php // CONFIGURACION_PRIVADA_FICTICIA', encoding='utf-8')
    preserved = {}
    for extra in ['config/mail_accounts.local.php', 'config/mail_oauth.local.php']:
        p = live / extra; p.write_text('<?php // CONFIGURACION_ANTERIOR_FICTICIA', encoding='utf-8'); preserved[extra] = digest(p)
    # Preservar EXACTAMENTE la recuperación que tenga cada instalación (main o posterior).
    for f in ['app/controllers/LoginController.php','app/models/UsuarioModel.php','app/services/MailService.php',
              'app/services/CorreoSmtpTransport.php','app/services/CorreoEstadoPrivado.php','app/services/CorreoEnvioControl.php',
              'app/services/CorreoOAuthTokenProvider.php','app/services/CorreoRecuperacionService.php','config/mail_config.php',
              'config/private_config.php','app/views/auth/login.php','app/views/auth/recuperar_password.php']:
        assert f not in files, 'El publicador incluye recuperación/componente protegido: '+f
        r=subprocess.run(['git','show',baseline+':'+f],cwd=root,capture_output=True)
        if r.returncode==0:
            p=live/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(r.stdout);preserved[f]=digest(p)
    original = {f: digest(live / f) for f in files}; private_hash = digest(private)

    def run(*args, ok=True):
        r = subprocess.run([bash, script.as_posix(), *args], cwd=root, env=env, capture_output=True, text=True, timeout=90)
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
        if f == 'app/services/CorreoSalidaInstitucionalService.php':
            actual = actual.replace(b'\n// comentario local conservado\n', b'')
        assert actual == expected, 'Payload desactualizado: ' + f
    assert b'// comentario local conservado' in (live / 'app/services/CorreoSalidaInstitucionalService.php').read_bytes()
    assert digest(private) == private_hash
    assert all(digest(live / f) == h for f, h in preserved.items()), 'conservar configuración/componentes anteriores'
    assert 'ya aplicado' in run('--apply')
    # Rollback no puede pisar ediciones posteriores.
    changed_file = next(f for f in files if f.startswith('app/services/') and installed[f] != original[f])
    changed = live / changed_file; prior = changed.read_bytes()
    changed.write_bytes(prior + b'\n// edicion posterior\n')
    run('--restore', backup, ok=False)
    assert changed.read_bytes().endswith(b'// edicion posterior\n')
    changed.write_bytes(prior)
    run('--restore', backup)
    assert {f: digest(live / f) for f in files} == original, 'restauración exacta'
    if without_diagnostic:
        assert not (live / 'tools').exists(), 'restaurar también el directorio creado'
    assert digest(private) == private_hash
    assert all(digest(live / f) == h for f, h in preserved.items()), 'restore no altera configuración anterior'
    # Archivo incompatible: falla antes de tocar el sitio.
    p = live / 'app/services/CorreoSalidaInstitucionalService.php'; p.write_text('<?php // versión incompatible', encoding='utf-8')
    before = {f: digest(live / f) for f in files}
    run('--check', ok=False)
    assert {f: digest(live / f) for f in files} == before
    print('OK: check sin escritura, apply/restore exactos, repetición idempotente, CRLF/comentarios/config privada conservados y rechazo de versiones incompatibles o ediciones posteriores. Diagnóstico inicialmente ' + ('ausente' if without_diagnostic else 'presente') + '; base ' + baseline[:8] + '. Sitio ficticio.')
