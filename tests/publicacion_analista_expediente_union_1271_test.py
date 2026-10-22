#!/usr/bin/env python3
"""Reproducir Hostinger: patch SQL 1271 exacto con personalizaciones y rollback."""
import os,json,pathlib,re,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
script=root/'tools/publicar_analista_expediente_union_1271_hostinger.sh'
spec=json.loads(re.search(r"SPEC=json.loads\(r'''(.*?)'''\)",script.read_text(encoding='utf8'),re.S).group(1))
assert spec['file']=='app/services/TelefoniaGrabacionExpedienteTransferidaService.php'
assert "COLLATE utf8mb4_general_ci" in spec['after']
latest=(root/spec['file']).read_text(encoding='utf8').replace('\r\n','\n')
before=(spec['before']+'\n' if spec['before'].endswith('\r') else spec['before']).replace('\r\n','\n')
after=(spec['after']+'\n' if spec['after'].endswith('\r') else spec['after']).replace('\r\n','\n')
assert latest.count(after)==1
original=latest.replace(after,before)
assert original.replace(before,after)==latest
for windows in (False,True):
    with tempfile.TemporaryDirectory() as temp:
        base=pathlib.Path(temp);site=base/'public_html';site.mkdir()
        (site/'index.php').write_text('<?php',encoding='utf8')
        private=base/'private';f=site/spec['file'];f.parent.mkdir(parents=True)
        suffix='\n/* HOSTINGER: NO SOBRESCRIBIR MI CODIGO PERSONALIZADO */\n'
        def codec(s):
            return (s.replace('\n','\r\n') if windows else s).encode('utf8')
        initial=codec(original+suffix);target=codec(latest+suffix)
        f.write_bytes(initial)
        def run(*args):
            env=os.environ.copy()
            env['IMPE_CRM_PUBLIC']=str(site)
            env['IMPE_CRM_BACKUPS']=str(private)
            env.pop('IMPE_TEST_MYSQL_PORT',None)
            return subprocess.run(['bash',str(script)]+list(args),
                env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
        check=run('--check')
        assert check.returncode==0 and 'Archivos a modificar: 1' in check.stdout,(check.stdout,check.stderr)
        assert 'OK: estructura SQL de tres fuentes normalizada' in check.stdout,check.stdout
        assert not private.exists() and f.read_bytes()==initial
        applied=run('--apply')
        assert applied.returncode==0,(applied.stdout,applied.stderr)
        assert f.read_bytes()==target
        folder=pathlib.Path(applied.stdout.split('Respaldo: ')[1].splitlines()[0].strip())
        assert private in folder.parents and folder.is_dir()
        installed=run('--check')
        assert installed.returncode==0 and 'Archivos a modificar: 0' in installed.stdout
        f.write_bytes(target+b'\n// otros cambios después\n')
        denied=run('--restore',str(folder))
        assert denied.returncode!=0 and 'CONFLICTO' in denied.stderr
        f.write_bytes(target)
        restored=run('--restore',str(folder))
        assert restored.returncode==0,(restored.stdout,restored.stderr)
        assert f.read_bytes()==initial
        f.write_bytes(b'<?php // Variante incompatible de Hostinger')
        unknown=run('--check')
        assert unknown.returncode!=0 and 'CONFLICTO' in unknown.stderr
        assert f.read_bytes()==b'<?php // Variante incompatible de Hostinger'
print('OK: 1271 UNION, LF/CRLF, sintaxis + smoke PHP, backup, idempotencia, restauración, conflictos')
