"""Publicador real sobre réplicas aisladas. Sin credenciales ni acceso SSH a producción."""
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

REPO = Path(__file__).resolve().parents[1]
SCRIPT = REPO / 'tools/publicar_toasts_hostinger.sh'
TARGET = re.search(r"^TARGET='([a-f0-9]{40})'", SCRIPT.read_text(), re.M)[1]
MANIFEST = json.loads((REPO / 'tools/toasts_8m/manifest.json').read_text())
FILE = MANIFEST['file']
BASH = os.environ.get('IMPE_TEST_BASH', 'bash')
BASE = subprocess.check_output(['git', 'show', MANIFEST['base'] + ':' + FILE], cwd=REPO).replace(b'\r\n', b'\n')
EXPECTED = subprocess.check_output(['git', 'show', TARGET + ':' + FILE], cwd=REPO).replace(b'\r\n', b'\n')
assert hashlib.sha256(EXPECTED).hexdigest() == MANIFEST['after']


def shell_path(path):
    text = str(path).replace('\\', '/')
    return '/' + text[0].lower() + text[2:] if re.match(r'^[A-Za-z]:/', text) else text


def snapshot(root):
    return {p.relative_to(root).as_posix(): p.read_bytes() for p in root.rglob('*') if p.is_file()}


def site(root, variant='hostinger', crlf=False):
    live = root / 'domains/example.org/public_html'
    p = live / FILE
    p.parent.mkdir(parents=True)
    p.write_bytes(BASE)
    (live / 'index.php').write_text('<?php throw new Exception("NO EJECUTAR EL CRM");')
    if variant == 'hostinger':
        result = subprocess.run(['git', '-c', 'core.autocrlf=false', 'apply',
                                 str(REPO / 'tests/fixtures/recordatorios_hostinger_20261018.patch')],
                                cwd=live, capture_output=True)
        assert result.returncode == 0, result.stderr
    digest = hashlib.sha256(p.read_bytes()).hexdigest()
    assert digest == next(v['before'] for v in MANIFEST['variants'] if v['label'].startswith(variant))
    if crlf:
        p.write_bytes(p.read_bytes().replace(b'\n', b'\r\n'))
    p.chmod(0o644)
    for name in ['config/database.local.php', 'storage/grabaciones/audio.mp3', 'public/javascript/telefonia_shell.js']:
        private = live / name
        private.parent.mkdir(parents=True, exist_ok=True)
        private.write_bytes(b'PERSONALIZACION PRIVADA: CONSERVAR SIN MODIFICAR')
    return live


def run(root, live, mode, extra=None, backup=None):
    env = dict(os.environ, IMPE_TOASTS_SOURCE=shell_path(REPO), IMPE_TOASTS_PUBLIC=shell_path(live),
               IMPE_TOASTS_BACKUPS=shell_path(root / 'backups'), TMPDIR=shell_path(root))
    env.update(extra or {})
    args = [BASH, shell_path(SCRIPT), mode]
    if backup is not None:
        args.append(shell_path(backup))
    return subprocess.run(args, cwd=REPO, env=env, capture_output=True, text=True, encoding='utf-8', errors='replace')


def publication(root, variant='hostinger', crlf=False):
    live = site(root, variant, crlf)
    before = snapshot(live)
    checked = run(root, live, '--check')
    assert checked.returncode == 0 and 'SITIO INTACTO' in checked.stdout, checked.stdout + checked.stderr
    assert snapshot(live) == before and not (root / 'backups').exists()
    applied = run(root, live, '--apply')
    assert applied.returncode == 0, applied.stdout + applied.stderr
    expected = EXPECTED.replace(b'\n', b'\r\n') if crlf else EXPECTED
    assert snapshot(live) == {**before, FILE: expected}
    if os.name != 'nt':
        assert (live / FILE).stat().st_mode & 0o777 == 0o644, 'conserva permisos para servir el JS'
    backups = list((root / 'backups').iterdir())
    assert len(backups) == 1 and (backups[0] / FILE).read_bytes() == before[FILE]
    for mode in ['--check', '--apply']:
        again = run(root, live, mode)
        assert again.returncode == 0 and 'ya está publicada' in again.stdout, again.stdout + again.stderr
    assert len(list((root / 'backups').iterdir())) == 1
    return live, before, backups[0]


def hostinger(root):
    publication(root)
    return 'variante real Hostinger: check sin escrituras, parche selectivo, privados, respaldo e idempotencia'


def github(root):
    publication(root, 'github')
    return 'variante GitHub a0e2607: resultado completo idéntico al probado'


def crlf(root):
    publication(root, crlf=True)
    return 'finales CRLF y permisos conservados'


def unknown(root):
    live = site(root)
    p = live / FILE
    original = p.read_bytes()
    for content in [original + b'\n// PERSONALIZACION NO REVISADA\n',
                    original + b'\n<<<<<<< HEAD\n=======\n>>>>>>> otra\n',
                    original.replace(b'\n', b'\r\n', 1)]:
        p.write_bytes(content)
        before = snapshot(live)
        for mode in ['--check', '--apply']:
            result = run(root, live, mode)
            assert result.returncode != 0, result.stdout + result.stderr
            assert snapshot(live) == before and not (root / 'backups').exists()
    return 'cambios no revisados, marcadores y finales mezclados rechazados sin sobrescribir'


def locked(root):
    live = site(root)
    original = snapshot(live)
    (live.parent / '.impe-atencion-deploy.lock').mkdir()
    result = run(root, live, '--apply')
    assert result.returncode != 0 and 'bloqueo' in result.stderr
    assert snapshot(live) == original and (live.parent / '.impe-atencion-deploy.lock').exists()
    return 'bloqueo compartido impide publicaciones simultáneas'


def interrupted(root):
    live = site(root)
    original = snapshot(live)
    wrapper = root / 'fallar.bash'
    wrapper.write_text('mv() { return 23; }\n', newline='\n')
    result = run(root, live, '--apply', {'BASH_ENV': shell_path(wrapper)})
    assert result.returncode != 0 and 'Archivo anterior restaurado' in result.stderr, result.stdout + result.stderr
    assert snapshot(live) == original
    assert not (live.parent / '.impe-atencion-deploy.lock').exists()
    return 'fallo de instalación restaura el original y limpia temporal y bloqueo'


def restore(root):
    live, original, backup = publication(root)
    result = run(root, live, '--restore', backup=backup)
    assert result.returncode == 0, result.stdout + result.stderr
    assert snapshot(live) == original
    again = run(root, live, '--restore', backup=backup)
    assert again.returncode == 0 and 'ya está restaurado' in again.stdout
    return 'restauración manual comprobada e idempotente'


def restore_modified(root):
    live, _, backup = publication(root)
    (live / FILE).write_bytes((live / FILE).read_bytes() + b'\n// EDICION POSTERIOR\n')
    edited = snapshot(live)
    result = run(root, live, '--restore', backup=backup)
    assert result.returncode != 0 and 'después de publicar' in result.stderr
    assert snapshot(live) == edited
    return 'restauración rechaza pisar ediciones posteriores'


def restore_corrupt(root):
    live, _, backup = publication(root)
    original = snapshot(live)
    (backup / FILE).write_bytes(b'RESPALDO ALTERADO')
    result = run(root, live, '--restore', backup=backup)
    assert result.returncode != 0 and 'respaldo no coincide' in result.stderr
    assert snapshot(live) == original
    result = run(root, live, '--restore', backup=root)
    assert result.returncode != 0 and 'fuera de la carpeta' in result.stderr
    return 'respaldo alterado y ruta de restauración fuera de la carpeta rechazados'


def corrupted_stage(root):
    live = site(root)
    original = snapshot(live)
    wrapper = root / 'alterar-stage.bash'
    wrapper.write_text('git() {\ncommand git "$@" || return $?\n'
                       'if [[ "$*" == *" apply "* && "$*" != *" --check "* ]]; then\n'
                       'printf "\\n<<<<<<< CAMBIO_NO_VALIDADO\\n" >> public/javascript/recordatorios.js\n'
                       'fi\n}\n', newline='\n')
    result = run(root, live, '--apply', {'BASH_ENV': shell_path(wrapper)})
    assert result.returncode != 0 and 'no coincide íntegramente' in result.stderr, result.stdout + result.stderr
    assert snapshot(live) == original and not (root / 'backups').exists()
    return 'validación integral impide publicar un stage alterado, incluso con marcadores'


def concurrent_edit(root):
    live = site(root)
    before = snapshot(live)
    wrapper = root / 'editar-produccion.bash'
    wrapper.write_text('git() {\ncommand git "$@" || return $?\n'
                       'if [[ "$*" == *" apply "* && "$*" != *" --check "* ]]; then\n'
                       'printf "\\n// EDICION CONCURRENTE\\n" >> "$IMPE_TOASTS_PUBLIC/public/javascript/recordatorios.js"\n'
                       'fi\n}\n', newline='\n')
    result = run(root, live, '--apply', {'BASH_ENV': shell_path(wrapper)})
    assert result.returncode != 0 and 'cambió durante' in result.stderr, result.stdout + result.stderr
    assert snapshot(live) == {**before, FILE: before[FILE] + b'\n// EDICION CONCURRENTE\n'}
    assert not (root / 'backups').exists()
    return 'edición concurrente conservada sin publicar encima'


def no_node(root):
    live = site(root)
    wrapper = root / 'sin-node.bash'
    wrapper.write_text('node() { echo NODE_NO_DISPONIBLE >&2; return 99; }\n', newline='\n')
    result = run(root, live, '--apply', {'BASH_ENV': shell_path(wrapper)})
    assert result.returncode == 0 and 'NODE_NO_DISPONIBLE' not in result.stderr, result.stdout + result.stderr
    assert (live / FILE).read_bytes() == EXPECTED
    return 'publicación completa sin ejecutar Node.js en Hostinger'


def https(root):
    live = site(root)
    before = snapshot(live)
    config = root / 'gitconfig'
    config.write_text('[url "git@github.com:"]\n\tinsteadOf = https://github.com/\n')
    ssh = root / 'rechazar-ssh.sh'
    ssh.write_text('#!/usr/bin/env bash\necho SSH_NO_PERMITIDO >&2\nexit 97\n', newline='\n')
    ssh.chmod(0o755)
    result = run(root, live, '--check', {'IMPE_TOASTS_SOURCE': shell_path(root / 'copia-nueva'),
                                       'GIT_CONFIG_GLOBAL': shell_path(config), 'GIT_SSH_COMMAND': shell_path(ssh)})
    assert result.returncode == 0 and 'SITIO INTACTO' in result.stdout, result.stdout + result.stderr
    assert 'SSH_NO_PERMITIDO' not in result.stderr and snapshot(live) == before
    return 'descarga HTTPS real independiente de reglas SSH de la cuenta'


if __name__ == '__main__':
    subprocess.run([BASH, '-n', shell_path(SCRIPT)], check=True)
    subprocess.run(['node', '--check', str(REPO / FILE)], check=True)  # Desarrollo/CI, nunca el servidor.
    with tempfile.TemporaryDirectory(prefix='impe-toasts-tests-', dir=os.environ.get('IMPE_TEST_TMP')) as work:
        def execute(test):
            root = Path(work) / test.__name__
            root.mkdir()
            return test(root)
        tests = [hostinger, github, crlf, unknown, locked, interrupted, restore,
                 restore_modified, restore_corrupt, corrupted_stage, concurrent_edit, no_node]
        if os.environ.get('IMPE_TEST_NETWORK') == '1':
            tests.append(https)
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            for future in concurrent.futures.as_completed([pool.submit(execute, test) for test in tests]):
                print('OK:', future.result(), flush=True)
    print(f'Publicador de toasts: {len(tests)} escenarios aprobados. Sólo sitios aislados.')
