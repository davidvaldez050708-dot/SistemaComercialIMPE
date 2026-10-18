"""Publicación real sobre sitios temporales. No conecta a Hostinger ni ejecuta PHP del CRM."""
import concurrent.futures
import hashlib
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

REPO = Path(__file__).resolve().parents[1]
SCRIPT = REPO / 'tools/publicar_devoluciones_avisos_hostinger.sh'
BASH = os.environ.get('IMPE_TEST_BASH', 'bash')
BASE = re.search(r"^BASE='([a-f0-9]{40})'", SCRIPT.read_text(), re.M)[1]
TARGET = re.search(r"^TARGET='([a-f0-9]{40})'", SCRIPT.read_text(), re.M)[1]
FILES = re.search(r'^FILES=\(\n(.*?)\n\)', SCRIPT.read_text(), re.M | re.S)[1].split()


def shell_path(path):
    text = str(path).replace('\\', '/')
    return '/' + text[0].lower() + text[2:] if re.match(r'^[A-Za-z]:/', text) else text


def blob(ref, name):
    result = subprocess.run(['git', 'show', ref + ':' + name], cwd=REPO, capture_output=True)
    return result.stdout if result.returncode == 0 else None


BASE_FILES = {name: blob(BASE, name) for name in FILES}
TARGET_FILES = {name: blob(TARGET, name) for name in FILES}
assert len(FILES) == 7 and all(TARGET_FILES.values())


def snapshot(root):
    return {p.relative_to(root).as_posix(): p.read_bytes() for p in root.rglob('*') if p.is_file()}


def site(root):
    live = root / 'domains/example.org/public_html'
    live.mkdir(parents=True)
    (live / 'index.php').write_text('<?php echo "test";')
    for name, content in BASE_FILES.items():
        if content is not None:
            p = live / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(content)
    for name in ['config/database.local.php', 'storage/grabaciones/audio.mp3']:
        p = live / name
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_bytes(b'PRIVADO: NO PUBLICAR NI CAMBIAR')
    return live


def run(root, live, mode, extra=None):
    env = dict(os.environ, IMPE_AVISOS_SOURCE=shell_path(REPO),
               IMPE_AVISOS_PUBLIC=shell_path(live),
               IMPE_AVISOS_BACKUPS=shell_path(root / 'backups'), TMPDIR=shell_path(root))
    env.update(extra or {})
    return subprocess.run([BASH, shell_path(SCRIPT), mode], env=env, cwd=REPO,
                          capture_output=True, text=True, encoding='utf-8', errors='replace')


def normal(root):
    live = site(root)
    original = snapshot(live)
    check = run(root, live, '--check')
    assert check.returncode == 0 and 'SITIO INTACTO' in check.stdout, check.stdout + check.stderr
    assert snapshot(live) == original and not (root / 'backups').exists()
    applied = run(root, live, '--apply')
    assert applied.returncode == 0, applied.stdout + applied.stderr
    for name, expected in TARGET_FILES.items():
        assert (live / name).read_bytes() == expected, name
    assert (live / 'config/database.local.php').read_bytes() == b'PRIVADO: NO PUBLICAR NI CAMBIAR'
    assert (live / 'storage/grabaciones/audio.mp3').read_bytes() == b'PRIVADO: NO PUBLICAR NI CAMBIAR'
    backups = list((root / 'backups').iterdir())
    assert len(backups) == 1
    for name, content in BASE_FILES.items():
        if content is not None:
            assert (backups[0] / name).read_bytes() == content
    second = run(root, live, '--apply')
    assert second.returncode == 0 and 'ya está publicada' in second.stdout
    assert list((root / 'backups').iterdir()) == backups
    assert not (live.parent / '.impe-atencion-deploy.lock').exists()
    return 'revisión sin escrituras, publicación, 7 archivos exactos, respaldo, privados e idempotencia'


def customization(root):
    live = site(root)
    name = 'public/javascript/telefonia_devoluciones.js'
    note = b'\n// PERSONALIZACION HOSTINGER AJENA AL PARCHE\n'
    (live / name).write_bytes(BASE_FILES[name] + note)
    result = run(root, live, '--apply')
    assert result.returncode == 0, result.stdout + result.stderr
    assert (live / name).read_bytes() == TARGET_FILES[name] + note
    result = run(root, live, '--check')
    assert result.returncode == 0 and 'ya está publicada' in result.stdout
    return 'personalización compatible conservada, también al revisar de nuevo'


def reviewed_variant(root, crlf=False):
    """Reproduce el archivo público observado, sin necesitar acceso a producción."""
    name = 'public/javascript/telefonia_persistente.js'
    live = site(root)
    fixture = root / 'fixture'
    p = fixture / name
    p.parent.mkdir(parents=True)
    p.write_bytes(BASE_FILES[name].replace(b'\r\n', b'\n'))
    for patch, expected_hash in [
        ('telefonia_persistente_hostinger_20261018.patch',
         '7208acd1631aa4bcbe47cc8ca06de7a5cbb5441919fa1077034a7ca976a06661'),
        ('telefonia_persistente_hostinger_devoluciones.patch',
         'f5ccdbbc19a008840391f73ac674a7a3dddc267c9787112a21500a12efdec7a2'),
    ]:
        result = subprocess.run(['git', '-c', 'core.autocrlf=false', 'apply',
                                 str(REPO / 'tests/fixtures' / patch)],
                                cwd=fixture, capture_output=True)
        assert result.returncode == 0, result.stderr
        content = p.read_bytes()
        assert hashlib.sha256(content).hexdigest() == expected_hash
        if '20261018' in patch:
            observed = content
        else:
            expected = content
    assert expected != TARGET_FILES[name].replace(b'\r\n', b'\n')
    assert b'const embeddedPhone' not in expected  # No incorpora mejoras ajenas.
    if crlf:
        observed, expected = (b.replace(b'\n', b'\r\n') for b in (observed, expected))
    (live / name).write_bytes(observed)
    return live, name, observed, expected


def hostinger_variant(root):
    live, name, observed, expected = reviewed_variant(root)
    original = snapshot(live)
    checked = run(root, live, '--check')
    assert checked.returncode == 0 and 'COMPATIBLE: variante Hostinger revisada' in checked.stdout, checked.stdout + checked.stderr
    assert snapshot(live) == original and not (root / 'backups').exists()
    applied = run(root, live, '--apply')
    assert applied.returncode == 0, applied.stdout + applied.stderr
    assert (live / name).read_bytes() == expected
    for other in FILES:
        if other != name:
            assert (live / other).read_bytes() == TARGET_FILES[other], other
    backup = next((root / 'backups').iterdir())
    assert (backup / name).read_bytes() == observed
    for mode in ['--check', '--apply']:
        again = run(root, live, mode)
        assert again.returncode == 0 and 'ya está publicada' in again.stdout, again.stdout + again.stderr
        assert (live / name).read_bytes() == expected
    assert len(list((root / 'backups').iterdir())) == 1
    return 'variante real Hostinger: parche selectivo, respaldo exacto e idempotencia'


def hostinger_crlf(root):
    live, name, observed, expected = reviewed_variant(root, crlf=True)
    applied = run(root, live, '--apply')
    assert applied.returncode == 0, applied.stdout + applied.stderr
    assert (live / name).read_bytes() == expected
    again = run(root, live, '--apply')
    assert again.returncode == 0 and 'ya está publicada' in again.stdout
    return 'variante Hostinger conserva finales CRLF sin reemplazar por versión GitHub'


def hostinger_unknown(root):
    live, name, observed, expected = reviewed_variant(root)
    for content in [observed + b'\n// OTRA VARIANTE NO REVISADA\n',
                    expected + b'\n// CAMBIO POSTERIOR NO REVISADO\n',
                    observed.replace(b'\n', b'\r\n', 1)]:
        (live / name).write_bytes(content)
        original = snapshot(live)
        for mode in ['--check', '--apply']:
            result = run(root, live, mode)
            assert result.returncode != 0 and 'CONFLICTO:' in result.stdout, result.stdout + result.stderr
            assert snapshot(live) == original and not (root / 'backups').exists()
    return 'variante desconocida, cambio posterior y finales mezclados se rechazan sin escrituras'


def hostinger_interrupted(root):
    live, name, observed, expected = reviewed_variant(root)
    original = snapshot(live)
    wrapper = root / 'interrumpir.bash'
    wrapper.write_text('mv() {\ncase "${@: -1}" in\n'
                       '*/app/views/telefonia/resultado_analista_form.php) return 23;;\n'
                       'esac\ncommand mv "$@"\n}\n', newline='\n')
    result = run(root, live, '--apply', {'BASH_ENV': shell_path(wrapper)})
    assert result.returncode != 0 and 'Archivos restaurados' in result.stderr, result.stdout + result.stderr
    assert snapshot(live) == original
    assert not (live.parent / '.impe-atencion-deploy.lock').exists()
    return 'fallo posterior al parche restaura también la variante Hostinger original'


def conflict(root):
    live = site(root)
    p = live / 'public/javascript/telefonia_persistente.js'
    p.write_text('<?php // version propia diferente\n')
    original = snapshot(live)
    for mode in ['--check', '--apply']:
        result = run(root, live, mode)
        assert result.returncode != 0 and 'CONFLICTO:' in result.stdout
        assert snapshot(live) == original and not (root / 'backups').exists()
    return 'conflicto rechazado antes de escribir en ambos modos'


def invalid_php(root):
    live = site(root)
    p = live / 'app/views/telefonia/resultado_analista_form.php'
    p.write_bytes(p.read_bytes() + b'\n<?php this is not valid PHP !!!\n')
    original = snapshot(live)
    result = run(root, live, '--apply')
    assert result.returncode != 0
    assert snapshot(live) == original and not (root / 'backups').exists()
    return 'PHP inválido rechazado antes de publicar'


def interrupted(root):
    live = site(root)
    original = snapshot(live)
    shim = root / 'bin'
    shim.mkdir()
    # Fallar después de instalar los nuevos servicios, antes de terminar la entrega.
    wrapper = shim / 'interrumpir.bash'
    wrapper.write_text('mv() {\ncase "${@: -1}" in\n'
                       '*/app/views/telefonia/resultado_analista_form.php) return 23;;\n'
                       'esac\ncommand mv "$@"\n}\n', newline='\n')
    env = {'BASH_ENV': shell_path(wrapper)}
    result = run(root, live, '--apply', env)
    assert result.returncode != 0, result.stdout + result.stderr
    assert 'Archivos restaurados' in result.stderr, result.stdout + result.stderr
    assert snapshot(live) == original
    assert not (live.parent / '.impe-atencion-deploy.lock').exists()
    return 'fallo de escritura restaura originales y retira archivos nuevos'


def locked(root):
    live = site(root)
    original = snapshot(live)
    (live.parent / '.impe-atencion-deploy.lock').mkdir()
    result = run(root, live, '--apply')
    assert result.returncode != 0 and 'bloqueo' in result.stderr
    assert snapshot(live) == original
    assert (live.parent / '.impe-atencion-deploy.lock').exists()
    return 'segunda publicación bloqueada sin alterar la primera'



def https_with_ssh_rewrite(root):
    """Reproduce una cuenta que redirige HTTPS a SSH y clona GitHub de verdad."""
    live = site(root)
    original = snapshot(live)
    config = root / 'gitconfig-ssh'
    config.write_text('[url "git@github.com:"]\n\tinsteadOf = https://github.com/\n')
    fake_ssh = root / 'rechazar-ssh.sh'
    fake_ssh.write_text('#!/usr/bin/env bash\necho "SSH_NO_DEBE_UTILIZARSE" >&2\nexit 97\n', newline='\n')
    fake_ssh.chmod(0o755)
    result = run(root, live, '--check', {
        'IMPE_AVISOS_SOURCE': shell_path(root / 'copia-https-nueva'),
        'GIT_CONFIG_GLOBAL': shell_path(config),
        'GIT_SSH_COMMAND': shell_path(fake_ssh),
    })
    assert result.returncode == 0 and 'SITIO INTACTO' in result.stdout, result.stdout + result.stderr
    assert 'SSH_NO_DEBE_UTILIZARSE' not in result.stderr
    assert config.read_text() == '[url "git@github.com:"]\n\tinsteadOf = https://github.com/\n'
    assert snapshot(live) == original and not (root / 'backups').exists()
    return 'GitHub por HTTPS con regla global SSH, sin llaves ni cambios en configuración o sitio'


if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] == '--client-fixture':
        with tempfile.TemporaryDirectory(prefix='impe-hostinger-fixture-') as work:
            _, _, _, expected = reviewed_variant(Path(work))
            Path(sys.argv[2]).write_bytes(expected)
        print('Cliente Hostinger corregido reconstruido para prueba de interfaz.')
        sys.exit(0)
    assert len(sys.argv) == 1, 'Uso: python publicacion_devoluciones_avisos_test.py [--client-fixture archivo]'
    subprocess.run([BASH, '-n', shell_path(SCRIPT)], check=True)
    # Cada escenario dispone de su propia carpeta, sitio y respaldos.
    with tempfile.TemporaryDirectory(prefix='impe-deploy-tests-', dir=os.environ.get('IMPE_TEST_TMP')) as work:
        def execute(test):
            root = Path(work) / test.__name__
            root.mkdir()
            return test(root)
        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
            tests = [normal, customization, conflict, invalid_php, interrupted, locked,
                     hostinger_variant, hostinger_crlf, hostinger_unknown, hostinger_interrupted]
            if os.environ.get('IMPE_TEST_NETWORK') == '1':
                tests.append(https_with_ssh_rewrite)
            futures = [pool.submit(execute, test) for test in tests]
            for future in concurrent.futures.as_completed(futures):
                print('OK:', future.result(), flush=True)
    print(f'Publicador SSH: {len(tests)} escenarios de integración aprobados. Sin conexión al sitio real.')
