import base64, gzip, json, pathlib, subprocess, sys, tempfile
root = pathlib.Path(__file__).resolve().parents[1]
spec = json.loads((root/'tools/microfonos_piloto_release.json').read_text(encoding='utf8'))
row = next(r for r in spec['files'] if r['path'] == 'public/css/telefonia_audio_dispositivos.css')
old = gzip.decompress(base64.b64decode(row['variants'][-1]['before']))
new = (root/row['path']).read_bytes().replace(b'\r\n', b'\n')
for crlf in [False, True]:
    with tempfile.TemporaryDirectory() as folder:
        d = pathlib.Path(folder); site = d/'site'; site.mkdir()
        (site/'index.php').write_text('<?php // fixture')
        css = site/row['path']; css.parent.mkdir(parents=True)
        original = old.replace(b'\n', b'\r\n') if crlf else old
        css.write_bytes(original)
        backup = d/'backup'
        def run(*args, **kwargs):
            r = subprocess.run([sys.executable,str(root/'tools/publicar_analista_modal_estilos.py'),
                '--site',str(site),'--backup-root',str(backup)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            assert (r.returncode == 0) == kwargs.get('ok', True), r.stdout+r.stderr
        run('--check'); assert css.read_bytes() == original and not backup.exists()
        css.write_bytes(original+b'/* local */');run('--apply',ok=False)
        assert css.read_bytes() == original+b'/* local */' and not backup.exists()
        css.write_bytes(original);run('--apply');assert css.read_bytes() == new
        saved = next(backup.iterdir());run('--apply');assert len(list(backup.iterdir())) == 1
        css.write_bytes(new+b'/* posterior */');run('--restore',str(saved),ok=False)
        css.write_bytes(new);run('--restore',str(saved));assert css.read_bytes() == original
print('PASS parche CSS: LF/CRLF, check sin escrituras, variante desconocida, instalación, idempotencia y reversión exacta')
