"""Publicador real: sólo tres archivos y réplicas desechables, nunca producción."""
from pathlib import Path
import sys, concurrent.futures, hashlib, json, os, re, subprocess, tempfile

REPO = Path(__file__).resolve().parents[1]
STAGE = sys.argv[1] if len(sys.argv)>1 else 'a'
SCRIPT = REPO / ('tools/publicar_ventas_fase_'+STAGE+'_hostinger.sh')
MANIFEST = json.loads((REPO / ('tools/ventas_fase_'+STAGE+'/manifest.json')).read_text(encoding='utf8'))
BASH = os.environ.get('IMPE_TEST_BASH', 'bash')

def sp(p):
    s = str(p).replace('\\', '/')
    return '/' + s[0].lower() + s[2:] if re.match(r'^[A-Za-z]:/', s) else s

def blob(ref, path):
    return subprocess.check_output(['git', 'show', ref + ':' + path], cwd=REPO).replace(b'\r\n', b'\n')

def snap(root):
    return {p.relative_to(root).as_posix(): p.read_bytes() for p in root.rglob('*') if p.is_file()}

def scenario(kind):
    with tempfile.TemporaryDirectory(prefix='impe-identidad-', dir=os.environ.get('IMPE_TEST_TMP')) as tmp:
        root = Path(tmp); live = root / 'site'; live.mkdir()
        (live / 'index.php').write_bytes(b'<?php // no ejecutar CRM')
        (live / 'privado.txt').write_bytes(b'PERSONALIZACION FUERA DEL PARCHE')
        for f in MANIFEST['files']:
            if f['new']: continue
            p = live / f['path']; p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(blob(MANIFEST['base'], f['path']))
            if f.get('production_fixture') and kind == 'hostinger':
                subprocess.run(['git', '-c', 'core.autocrlf=false', 'apply', str(REPO / f['production_fixture'])], cwd=live, check=True, capture_output=True)
            if kind == 'personalizado' and f['kind'] == 'php' and not f['new']:
                p.write_bytes(p.read_bytes() + b'\n// PERSONALIZACION A CONSERVAR\n')
            p.chmod(0o644)
        if kind == 'js-desconocido':
            candidate=next((f for f in MANIFEST['files'] if f['kind']=='js' and not f['new']),None)
            if not candidate: return 'sin JS existente: caso no aplicable'
            p = live / candidate['path']; p.write_bytes(p.read_bytes() + b'\n// NO REVISADO\n')
        if kind == 'php-conflicto':
            candidate=next(f for f in MANIFEST['files'] if f['kind']=='php' and not f['new'])
            (live / candidate['path']).write_bytes(b'<?php // incompatible\n')
        env = dict(os.environ, TMPDIR=sp(root))
        env.update({'IMPE_VENTAS_'+STAGE.upper()+'_SOURCE':sp(REPO),'IMPE_VENTAS_'+STAGE.upper()+'_PUBLIC':sp(live),'IMPE_VENTAS_'+STAGE.upper()+'_BACKUPS':sp(root/'backups')})
        def run(mode, backup=None):
            result = subprocess.run([BASH, sp(SCRIPT), mode] + ([sp(backup)] if backup else []), cwd=REPO, env=env, capture_output=True, text=True, encoding='utf8', errors='replace')
            return result.returncode, result.stdout + result.stderr
        before = snap(live)
        if kind in ['js-desconocido', 'php-conflicto']:
            code, output = run('--apply'); assert code != 0, output
            assert snap(live) == before and not (root/'backups').exists()
            return kind + ': rechazado, sitio intacto'
        code, output = run('--check'); assert code == 0, output
        assert snap(live) == before and not (root/'backups').exists()
        code, output = run('--apply'); assert code == 0, output
        after = snap(live); assert after['privado.txt'] == before['privado.txt']
        for f in MANIFEST['files']:
            data = after[f['path']].replace(b'\r\n', b'\n')
            if kind == 'personalizado' and f['kind'] == 'php' and not f['new']:
                assert data.endswith(b'// PERSONALIZACION A CONSERVAR\n'); continue
            expected = next((v['after'] for v in f['variants'] if v['label'].startswith('hostinger') and kind == 'hostinger'), f['target_sha256'])
            assert hashlib.sha256(data).hexdigest() == expected, f['path']
            if os.name != 'nt': assert (live/f['path']).stat().st_mode & 0o777 == 0o644
        if kind == 'hostinger' and STAGE == 'a':
            persisted = (live/'public/javascript/telefonia_persistente.js').read_text(encoding='utf8')
            shell = (live/'public/javascript/telefonia_shell.js').read_text(encoding='utf8')
            assert "window.parent.IMPE_PHONE_SHELL_CONFIG?.salesResults === true" in persisted
            assert "const abrirControlesAnalista = function" not in persisted, 'Hostinger no restaurará los controles retirados'
            assert "if (receptionState().ready)" in persisted
            assert "getReceptionStatus: function" in shell
        backup = list((root/'backups').iterdir()); assert len(backup) == 1
        code, output = run('--apply'); assert code == 0, output
        assert len(list((root/'backups').iterdir())) == 1
        code, output = run('--restore', backup[0]); assert code == 0, output
        assert snap(live) == before
        return kind + ': check intacto, apply, idempotencia y rollback exactos'

if __name__ == '__main__':
    subprocess.run([BASH, '-n', sp(SCRIPT)], check=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        for result in pool.map(scenario, ['github', 'replica-base', 'hostinger', 'personalizado', 'js-desconocido', 'php-conflicto']):
            print('OK:', result, flush=True)
    print('Publicación selectiva de Ventas: 6 escenarios aprobados en réplicas.')
