"""Publicador PDF en réplicas de main, PR #118 y auxiliar anterior; nunca en Hostinger."""
import os
from pathlib import Path
import re
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
BASH=os.environ.get('IMPE_TEST_BASH',r'C:\Program Files\Git\bin\bash.exe' if os.name=='nt' else 'bash')
SCRIPT=ROOT/'tools/publicar_oficios_pdf_hostinger.sh'
HELPER='app/services/OficioRedmexPdfRenderer.php'
FILES=[HELPER,'app/controllers/OficioVinculacionController.php','app/services/OficioDocxPdfService.php']
BASE={f:subprocess.check_output(['git','show','fd5fd282:'+f],cwd=ROOT) for f in FILES[1:]}
PREPARED={f:subprocess.check_output(['git','show','50ed2d75:'+f],cwd=ROOT) for f in FILES[1:]}
OLD_HELPER=subprocess.check_output(['git','show','f4a530e8:'+HELPER],cwd=ROOT)
TARGET={f:(ROOT/f).read_bytes().replace(b'\r\n',b'\n') for f in FILES}


def shell_path(path):
    value=str(path).replace('\\','/')
    return '/'+value[0].lower()+value[2:] if re.match(r'^[A-Za-z]:/',value) else value


def snapshot(root):
    return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}


def site(root,label):
    live=root/'site'
    for f,data in (BASE if label=='base' else PREPARED).items():
        (live/f).parent.mkdir(parents=True,exist_ok=True)
        if label=='custom': data+=b'\n// PERSONALIZACION LOCAL CONSERVADA\n'
        (live/f).write_bytes(data.replace(b'\n',b'\r\n') if label=='prepared-crlf' else data)
    if label=='old-helper': (live/HELPER).write_bytes(OLD_HELPER)
    (live/'index.php').write_text('<?php /* replica ficticia */')
    (live/'vendor/dompdf/dompdf').mkdir(parents=True)
    (live/'vendor/autoload.php').write_text('<?php namespace PhpOffice\\PhpWord {class TemplateProcessor{}} namespace Dompdf {class Dompdf{}} namespace FontLib {class Font{}}')
    (live/'vendor/dompdf/dompdf/VERSION').write_text('3.1.6')
    (live/'storage/templates').mkdir(parents=True)
    (live/'storage/templates/oficio_general_redmex.docx').write_bytes((ROOT/'storage/templates/oficio_general_redmex.docx').read_bytes())
    (live/'config').mkdir()
    (live/'config/database.local.php').write_text('PRIVADO DE PRUEBA; INTOCABLE')
    return live


def run(root,live,*args,ok=True):
    env=dict(os.environ,IMPE_OFICIOS_PDF_PUBLIC=shell_path(live),IMPE_OFICIOS_PDF_BACKUPS=shell_path(root/'backups'),TMPDIR=shell_path(root))
    result=subprocess.run([BASH,shell_path(SCRIPT),*map(shell_path,args)],cwd=ROOT,env=env,capture_output=True,
        text=True,encoding='utf-8',errors='replace',timeout=30,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
    assert (result.returncode==0)==ok,result.stdout+result.stderr
    return result


def main():
    with tempfile.TemporaryDirectory(prefix='oficios-pdf-pub-',dir=ROOT/'tmp') as temp:
        work=Path(temp)
        for label in ['base','prepared','prepared-crlf','custom','old-helper']:
            root=work/label
            live=site(root,label)
            initial=snapshot(live)
            checked=run(root,live,'--check')
            assert 'revisión compatible' in checked.stdout and snapshot(live)==initial and not (root/'backups').exists()
            run(root,live,'--apply')
            after=snapshot(live)
            assert {f for f in set(after)|set(initial) if after.get(f)!=initial.get(f)}<=set(FILES)
            for f in FILES:
                expected=TARGET[f]+(b'\n// PERSONALIZACION LOCAL CONSERVADA\n' if label=='custom' and f!=HELPER else b'')
                if label=='prepared-crlf' and f!=HELPER: expected=expected.replace(b'\n',b'\r\n')
                assert after[f]==expected,(label,f)
            backup,=(root/'backups').iterdir()
            run(root,live,'--apply')
            assert snapshot(live)==after and len(list((root/'backups').iterdir()))==1
            if label=='prepared':
                edited=live/FILES[2]
                installed=edited.read_bytes()
                edited.write_bytes(installed+b'\n// EDICION POSTERIOR\n')
                modified=snapshot(live)
                run(root,live,'--restore',backup,ok=False)
                assert snapshot(live)==modified
                edited.write_bytes(installed)
            run(root,live,'--restore',backup)
            assert snapshot(live)==initial,label
            run(root,live,'--restore',backup)
            assert snapshot(live)==initial
            print('OK: check/apply/restore, idempotencia y preservación:',label,flush=True)
        root=work/'conflict'
        live=site(root,'base')
        service=live/FILES[2]
        service.write_bytes(service.read_bytes().replace(b'public function generarPdf(array $vista)',b'public function generarPdf(array $otro)'))
        initial=snapshot(live)
        run(root,live,'--check',ok=False)
        run(root,live,'--apply',ok=False)
        assert snapshot(live)==initial and not (root/'backups').exists()
        root=work/'unknown-helper'
        live=site(root,'base')
        (live/HELPER).write_text('<?php /* otra implementación */')
        initial=snapshot(live)
        run(root,live,'--apply',ok=False)
        assert snapshot(live)==initial
        print('OK: conflictos y auxiliar desconocido rechazados sin modificar el sitio.',flush=True)


if __name__=='__main__': main()
