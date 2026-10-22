#!/usr/bin/env python3
"""Instalación aislada y restauración del servicio exclusivo de expediente."""
import os,re,json,subprocess,tempfile,pathlib
root=pathlib.Path(__file__).resolve().parents[1]
script=root/'tools/publicar_analista_expediente_vinculo_pbx_100_101_hostinger.sh'
spec=json.loads(re.search(r"SPEC=json.loads\(r'''(.*?)'''\)",script.read_text(encoding='utf8'),re.S).group(1))
assert spec['file']=='app/services/TelefoniaGrabacionExpedienteTransferidaService.php'
assert len(spec['patches'])==2
new=(root/spec['file']).read_text(encoding='utf8').replace('\r\n','\n')
old=new
for before,after in reversed(spec['patches']):
    before=(before+'\n' if before.endswith('\r') else before).replace('\r\n','\n')
    after=(after+'\n' if after.endswith('\r') else after).replace('\r\n','\n')
    assert old.count(after)==1,'No único: fragmento actualizado'
    old=old.replace(after,before,1)
assert old!=new
assert not any(path in spec['file'] for path in ['ventas','recepcion_marketing','llamadas_entrantes'])
for crlf in [False,True]:
    with tempfile.TemporaryDirectory() as tmp:
        parent=pathlib.Path(tmp);site=parent/'public_html';site.mkdir()
        (site/'index.php').write_text('<?php',encoding='utf8')
        backup=parent/'private'
        file=site/spec['file'];file.parent.mkdir(parents=True,exist_ok=True)
        suffix='\n/* HOSTINGER PERSONALIZACION DE TERCEROS INTACTA */\n'
        def encode(text):
            return (text.replace('\n','\r\n') if crlf else text).encode('utf8')
        initial=encode(old+suffix);final=encode(new+suffix)
        file.write_bytes(initial)
        def run(*args):
            env=os.environ.copy()
            env.update(IMPE_CRM_PUBLIC=str(site),IMPE_CRM_BACKUPS=str(backup))
            return subprocess.run(['bash',str(script)]+list(args),
                env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
        check=run('--check')
        assert check.returncode==0 and 'Archivos a modificar: 1' in check.stdout,(check.stdout,check.stderr)
        assert not backup.exists() and file.read_bytes()==initial
        applied=run('--apply')
        assert applied.returncode==0,(applied.stdout,applied.stderr)
        assert file.read_bytes()==final
        folder=pathlib.Path(applied.stdout.split('Respaldo: ')[1].splitlines()[0].strip())
        again=run('--check')
        assert again.returncode==0 and 'Archivos a modificar: 0' in again.stdout
        file.write_bytes(final+b'\n// personalización posterior\n')
        refuse=run('--restore',str(folder))
        assert refuse.returncode!=0 and 'CONFLICTO' in refuse.stderr
        file.write_bytes(final)
        restored=run('--restore',str(folder))
        assert restored.returncode==0,(restored.stdout,restored.stderr)
        assert file.read_bytes()==initial
        file.write_bytes(b'<?php // Código distinto: no sobrescribir')
        unknown=run('--check')
        assert unknown.returncode!=0 and 'CONFLICTO' in unknown.stderr
        assert file.read_bytes()==b'<?php // Código distinto: no sobrescribir'
print('OK: expediente Analista, 1 archivo, LF/CRLF, --check/--apply/--restore, variantes y resguardo')
