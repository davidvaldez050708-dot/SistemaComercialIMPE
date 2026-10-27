import base64, gzip, hashlib, json, pathlib, subprocess, sys, tempfile

R = pathlib.Path(__file__).resolve().parents[1]
spec = json.loads((R / 'tools/supervision_filtros_release.json').read_text(encoding='utf-8'))
assert len(spec['files']) == 4
unpack = lambda b: gzip.decompress(base64.b64decode(b))
for crlf in (False, True):
    with tempfile.TemporaryDirectory() as folder:
        private = pathlib.Path(folder)
        site = private / 'public_html'
        site.mkdir()
        (site / 'index.php').write_bytes(b'<?php')
        before, after = {}, {}
        for row in spec['files']:
            v = row['variants'][0]
            old, new = unpack(v['before']), unpack(v['after'])
            assert hashlib.sha256(old).hexdigest() == v['before_sha']
            assert hashlib.sha256(new).hexdigest() == v['after_sha']
            assert (R / row['path']).read_bytes().replace(b'\r\n', b'\n') == new
            before[row['path']] = old.replace(b'\n', b'\r\n') if crlf else old
            after[row['path']] = new.replace(b'\n', b'\r\n') if crlf else new
            target = site / row['path']
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(before[row['path']])
        sentinel = site / 'app/services/SupervisionLlamadasAudioService.php'
        sentinel.parent.mkdir(parents=True, exist_ok=True)
        sentinel.write_bytes(b'<?php /* audio y permisos intactos */')
        backups = private / 'respaldos'
        command = [sys.executable, str(R / 'tools/publicar_supervision_filtros.py'), '--site', str(site), '--backup-root', str(backups)]

        def run(*args, ok=True):
            result = subprocess.run(command + list(args), capture_output=True, text=True)
            assert (result.returncode == 0) == ok, result.stdout + result.stderr
            return result.stdout

        def unchanged():
            assert all((site / p).read_bytes() == b for p, b in before.items())
            assert sentinel.read_bytes() == b'<?php /* audio y permisos intactos */'

        run('--check')
        unchanged()
        assert not backups.exists()
        target = site / spec['files'][-1]['path']
        target.write_bytes(target.read_bytes() + b'\n// otra personalizacion')
        run('--apply', ok=False)
        target.write_bytes(before[spec['files'][-1]['path']])
        unchanged()
        assert not backups.exists()
        run('--apply')
        assert all((site / p).read_bytes() == b for p, b in after.items())
        backup = next(backups.iterdir())
        assert run('--check').count('ACTUALIZADO:') == 4
        run('--apply')
        assert len(list(backups.iterdir())) == 1
        current = target.read_bytes()
        target.write_bytes(current + b'\n// edicion posterior')
        run('--restore', str(backup), ok=False)
        target.write_bytes(current)
        run('--restore', str(backup))
        unchanged()

print('OK: 4 archivos de Supervisión, LF/CRLF, check sin escrituras, rechazo de versiones, respaldo, reinstalación y restauración; servicio de audio intacto.')
