"""Publicación y restauración reales sobre réplicas locales. Sin Hostinger ni BD."""
import hashlib
import os
from pathlib import Path
import re
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tools/publicar_oficios_comentarios_hostinger.sh'
BASH = os.environ.get('IMPE_TEST_BASH', r'C:\Program Files\Git\bin\bash.exe' if os.name == 'nt' else 'bash')
SERVICE = 'app/services/OficioDocxPdfService.php'
HELPER = 'app/services/OficioDocxComentarios.php'
BASE = subprocess.run(['git', 'show', '02625c21:' + SERVICE], cwd=ROOT, capture_output=True, check=True).stdout
TARGET = (ROOT / SERVICE).read_bytes().replace(b'\r\n', b'\n')


def shell_path(path):
    value = str(path).replace('\\', '/')
    return '/' + value[0].lower() + value[2:] if re.match(r'^[A-Za-z]:/', value) else value


def snapshot(root):
    return {p.relative_to(root).as_posix(): p.read_bytes() for p in root.rglob('*') if p.is_file()}


def site(root, content=BASE):
    live = root / 'site'
    (live / 'app/services').mkdir(parents=True)
    (live / 'vendor').mkdir()
    (live / SERVICE).write_bytes(content)
    (live / 'index.php').write_text('<?php /* sitio ficticio */')
    (live / 'vendor/autoload.php').write_text('<?php namespace PhpOffice\\PhpWord {class IOFactory{}} namespace Dompdf {class Dompdf{}}')
    (live / 'config').mkdir()
    (live / 'config/database.local.php').write_text('PRIVADO DE PRUEBA; INTOCABLE')
    return live


def run(root, live, *args, env_extra=None, ok=True):
    env = dict(os.environ, IMPE_OFICIOS_PDF_PUBLIC=shell_path(live),
               IMPE_OFICIOS_PDF_BACKUPS=shell_path(root / 'backups'), TMPDIR=shell_path(root))
    env.update(env_extra or {})
    command = [BASH, shell_path(SCRIPT), *map(shell_path, args)]
    if env_extra and env_extra.get('IMPE_TEST_BIN'):
        command = [BASH, '-c', 'export PATH="$1:$PATH"; exec /usr/bin/bash "$2" "${@:3}"', '_', env_extra['IMPE_TEST_BIN'], shell_path(SCRIPT), *map(shell_path, args)]
    result = subprocess.run(command, cwd=ROOT, env=env,
                            capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=30,
                            creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
    if ok:
        assert result.returncode == 0, result.stdout + result.stderr
    else:
        assert result.returncode != 0, result.stdout
    return result


def main():
    (ROOT / 'tmp').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='oficios-pub-test-', dir=ROOT / 'tmp') as work:
        work = Path(work)
        for label, data in [('normal', BASE), ('crlf', BASE.replace(b'\n', b'\r\n')),
                            ('custom', BASE + b'\n// PERSONALIZACION LOCAL CONSERVADA\n')]:
            root = work / label
            live = site(root, data)
            initial = snapshot(live)
            checked = run(root, live, '--check')
            assert '2 archivo(s)' in checked.stdout and snapshot(live) == initial
            assert not (root / 'backups').exists()
            run(root, live, '--apply')
            expected = TARGET.replace(b'\n', b'\r\n') if label == 'crlf' else TARGET
            if label == 'custom':
                expected += b'\n// PERSONALIZACION LOCAL CONSERVADA\n'
            assert (live / SERVICE).read_bytes() == expected
            assert (live / HELPER).read_bytes().replace(b'\r\n', b'\n') == (ROOT / HELPER).read_bytes().replace(b'\r\n', b'\n')
            backups = list((root / 'backups').iterdir())
            assert len(backups) == 1
            run(root, live, '--apply')
            assert list((root / 'backups').iterdir()) == backups
            installed = snapshot(live)
            (live / SERVICE).write_bytes(expected + b'\n// EDITADO DESPUES\n')
            before_restore = snapshot(live)
            run(root, live, '--restore', backups[0], ok=False)
            assert snapshot(live) == before_restore
            (live / SERVICE).write_bytes(installed[SERVICE])
            run(root, live, '--restore', backups[0])
            assert snapshot(live) == initial
            run(root, live, '--restore', backups[0])
            assert snapshot(live) == initial
        root = work / 'conflict'
        live = site(root, BASE.replace(b'$conversion = $this->convertirAPdf($rutaDocx, $directorioTemporal);', b'$conversion = funcionPersonalizada();'))
        before = snapshot(live)
        run(root, live, '--apply', ok=False)
        assert snapshot(live) == before and not (root / 'backups').exists()
        root = work / 'helper-custom'
        live = site(root)
        (live / HELPER).write_text('<?php /* auxiliar local no revisado */')
        before = snapshot(live)
        run(root, live, '--apply', ok=False)
        assert snapshot(live) == before
        root = work / 'interrupted'
        live = site(root)
        before = snapshot(live)
        bin_dir = root / 'bin'
        bin_dir.mkdir()
        stub = bin_dir / 'mv'
        stub.write_text('#!/usr/bin/env bash\nfor arg; do case "$arg" in */OficioDocxPdfService.php) exit 47;; esac; done\nexec /usr/bin/mv "$@"\n', newline='\n')
        subprocess.run([BASH, '-c', 'chmod +x "$1"', '_', shell_path(stub)], check=True,
                       creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
        run(root, live, '--apply', env_extra={'IMPE_TEST_BIN': shell_path(bin_dir)}, ok=False)
        assert snapshot(live) == before, 'rollback de publicación interrumpida'
        assert not list(work.rglob('*.impe-new-*'))
    print('OK: check/apply/restore, LF/CRLF, personalización, idempotencia, conflictos, edición posterior y rollback interrumpido. Sin producción.')


if __name__ == '__main__':
    main()
