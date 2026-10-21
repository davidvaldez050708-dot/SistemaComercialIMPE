"""Publicador de preparación DOCX sobre réplicas locales; sin Hostinger ni BD."""
import os
from pathlib import Path
import re
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tools/publicar_oficios_contenido_main_hostinger.sh'
BASH = os.environ.get('IMPE_TEST_BASH', r'C:\Program Files\Git\bin\bash.exe' if os.name == 'nt' else 'bash')
FILES = ['app/controllers/OficioVinculacionController.php', 'app/services/OficioDocxPdfService.php']
BASE_REV = 'fd5fd282'
BASE = {f: subprocess.check_output(['git', 'show', BASE_REV + ':' + f], cwd=ROOT) for f in FILES}
TARGET = {f: (ROOT / f).read_bytes().replace(b'\r\n', b'\n') for f in FILES}


def shell_path(path):
    value = str(path).replace('\\', '/')
    return '/' + value[0].lower() + value[2:] if re.match(r'^[A-Za-z]:/', value) else value


def snapshot(root):
    return {p.relative_to(root).as_posix(): p.read_bytes() for p in root.rglob('*') if p.is_file()}


def site(root, crlf=False, custom=False):
    live = root / 'site'
    for f, data in BASE.items():
        (live / f).parent.mkdir(parents=True, exist_ok=True)
        if custom:
            data += b'\n// PERSONALIZACION LOCAL CONSERVADA\n'
        (live / f).write_bytes(data.replace(b'\n', b'\r\n') if crlf else data)
    (live / 'index.php').write_text('<?php /* replica ficticia */')
    (live / 'vendor').mkdir()
    (live / 'vendor/autoload.php').write_text('<?php namespace PhpOffice\\PhpWord {class TemplateProcessor{}}')
    (live / 'storage/templates').mkdir(parents=True)
    (live / 'storage/templates/oficio_general_redmex.docx').write_bytes((ROOT / 'storage/templates/oficio_general_redmex.docx').read_bytes())
    (live / 'config').mkdir()
    (live / 'config/database.local.php').write_text('PRIVADO DE PRUEBA; INTOCABLE')
    return live


def run(root, live, *args, ok=True):
    env = dict(os.environ, IMPE_OFICIOS_PDF_PUBLIC=shell_path(live),
               IMPE_OFICIOS_PDF_BACKUPS=shell_path(root / 'backups'), TMPDIR=shell_path(root))
    result = subprocess.run([BASH, shell_path(SCRIPT), *map(shell_path, args)], cwd=ROOT, env=env,
                            capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=30,
                            creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
    assert (result.returncode == 0) == ok, result.stdout + result.stderr
    return result


def main():
    (ROOT / 'tmp').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='oficios-contenido-pub-', dir=ROOT / 'tmp') as work:
        work = Path(work)
        for label, crlf, custom in [('lf', False, False), ('crlf', True, False), ('custom', False, True)]:
            root = work / label
            live = site(root, crlf, custom)
            initial = snapshot(live)
            assert '2 archivo(s)' in run(root, live, '--check').stdout
            assert snapshot(live) == initial and not (root / 'backups').exists()
            run(root, live, '--apply')
            after = snapshot(live)
            assert {f for f in initial if initial[f] != after[f]} == set(FILES)
            for f in FILES:
                expected = TARGET[f] + (b'\n// PERSONALIZACION LOCAL CONSERVADA\n' if custom else b'')
                assert after[f] == (expected.replace(b'\n', b'\r\n') if crlf else expected)
            backup, = (root / 'backups').iterdir()
            assert len((backup / 'archivos.tsv').read_text().splitlines()) == 2
            run(root, live, '--apply')
            assert snapshot(live) == after and len(list((root / 'backups').iterdir())) == 1
            if label == 'custom':
                edited = live / FILES[0]
                installed = edited.read_bytes()
                edited.write_bytes(installed + b'\n// EDICION POSTERIOR\n')
                before_failed_restore = snapshot(live)
                run(root, live, '--restore', backup, ok=False)
                assert snapshot(live) == before_failed_restore
                edited.write_bytes(installed)
            run(root, live, '--restore', backup)
            assert snapshot(live) == initial
            run(root, live, '--restore', backup)
            assert snapshot(live) == initial
            print('OK: check/apply/restore, idempotencia y archivos ajenos intactos:', label, flush=True)
        root = work / 'conflict'
        live = site(root)
        conflict = live / FILES[1]
        conflict.write_bytes(conflict.read_bytes().replace(b'public function generarDocx(array $vista)', b'public function generarDocx(array $otraVista)'))
        initial = snapshot(live)
        run(root, live, '--check', ok=False)
        run(root, live, '--apply', ok=False)
        assert snapshot(live) == initial and not (root / 'backups').exists()
        print('OK: conflicto rechazado sin cambios ni respaldo.', flush=True)


if __name__ == '__main__':
    main()
