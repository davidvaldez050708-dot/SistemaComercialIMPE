import base64, gzip, hashlib, json, pathlib, subprocess, sys, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = json.loads((ROOT / 'tools/ventas_resumen_saliente_release.json').read_text(encoding='utf-8'))
row = spec['files'][0]
assert row['path'] == 'public/javascript/telefonia_shell.js' and len(spec['files']) == 1
unpack = lambda value: gzip.decompress(base64.b64decode(value))
assert (ROOT / row['path']).read_bytes().replace(b'\r\n', b'\n') == unpack(row['variants'][0]['after'])
assert row['variants'][1]['before_sha'] == '77cfcf661fe350ee1647e7064e210a87e7b9f0474e39252fa650633d993e8e61'

for variant in row['variants']:
    for crlf in [False, True]:
        before, after = unpack(variant['before']), unpack(variant['after'])
        assert hashlib.sha256(before).hexdigest() == variant['before_sha']
        assert hashlib.sha256(after).hexdigest() == variant['after_sha']
        # La variante de Opera conserva la migración de registros abandonados.
        if variant['label'] == 'Hostinger Opera':
            for marker in [b'const legacyBusy', b'const reconcileLegacy', b'if (!reconcileLegacy()) { render(); return; }']:
                assert marker in before and marker in after
        with tempfile.TemporaryDirectory() as folder:
            private = pathlib.Path(folder)
            site = private / 'public_html'
            site.mkdir()
            (site / 'index.php').write_bytes(b'<?php')
            target = site / row['path']
            target.parent.mkdir(parents=True)
            original = before.replace(b'\n', b'\r\n') if crlf else before
            expected = after.replace(b'\n', b'\r\n') if crlf else after
            target.write_bytes(original)
            dep = spec['dependencies'][0]
            dependency = site / dep['path']
            dependency.write_bytes((ROOT / dep['path']).read_bytes())
            untouched = site / 'public/javascript/telefonia_host.js'
            untouched.write_bytes(b'// Motor personalizado: no sustituir.\n')
            backups = private / 'respaldos'
            cmd = [sys.executable, str(ROOT / 'tools/publicar_ventas_resumen_saliente.py'), '--site', str(site), '--backup-root', str(backups)]

            def run(*args, ok=True):
                result = subprocess.run(cmd + list(args), capture_output=True, text=True)
                assert (result.returncode == 0) == ok, result.stdout + result.stderr
                return result.stdout

            run('--check')
            assert target.read_bytes() == original and not backups.exists()
            target.write_bytes(original + b'\n// personalizado desconocido')
            run('--apply', ok=False)
            assert not backups.exists()
            target.write_bytes(original)
            old_dep = dependency.read_bytes()
            dependency.write_bytes(old_dep + b'\n// otra version')
            run('--apply', ok=False)
            assert target.read_bytes() == original and not backups.exists()
            dependency.write_bytes(old_dep)
            run('--apply')
            assert target.read_bytes() == expected
            backup = next(backups.iterdir())
            assert (backup / row['path']).read_bytes() == original
            assert 'ACTUALIZADO' in run('--check')
            run('--apply')
            assert len(list(backups.iterdir())) == 1
            target.write_bytes(expected + b'\n// posterior')
            run('--restore', str(backup), ok=False)
            target.write_bytes(expected)
            run('--restore', str(backup))
            assert target.read_bytes() == original
            assert dependency.read_bytes() == old_dep
            assert untouched.read_bytes() == b'// Motor personalizado: no sustituir.\n'

print('OK: publicación de un archivo; variantes GitHub/Opera, LF/CRLF, check sin escrituras, conflictos, dependencia, respaldo, idempotencia y restauración.')
