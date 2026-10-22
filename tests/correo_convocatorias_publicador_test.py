"""Publicación selectiva en sitio ficticio. Nunca apunta a Hostinger ni envía mensajes."""
from pathlib import Path
import hashlib,os,re,subprocess,sys,tempfile,difflib
root=Path(__file__).resolve().parents[1]
bash,phpdir,baseline=sys.argv[1:4]
script=root/'tools/publicar_correo_convocatorias_hostinger.sh'
text=script.read_text(encoding='utf-8')
files=re.findall(r'"([^"]+)"',re.search(r'^FILES=\((.+)\)$',text,re.M)[1])
patches=re.findall(r'"([^"]+)"',re.search(r'^PATCH_FILES=\((.+)\)$',text,re.M)[1])
assert len(files)==6 and len(patches)==3
norm=lambda b:b.replace(b'\r\n',b'\n')
old=lambda ref,f:subprocess.check_output(['git','show',ref+':'+f],cwd=root)
digest=lambda p:hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else 'MISSING'
protected=['app/services/CorreoSalidaInstitucionalService.php','app/services/CorreoMarketingService.php',
 'app/services/CorreoCuentaConectadaService.php','app/services/CorreoHostingerApiService.php','app/services/HostingerCorreoAdministracionService.php',
 'app/controllers/LoginController.php','app/models/UsuarioModel.php','app/services/MailService.php','app/services/CorreoSmtpTransport.php',
 'app/services/CorreoEstadoPrivado.php','app/services/CorreoEnvioControl.php','config/mail_config.php','config/private_config.php',
 'app/views/auth/login.php','app/views/auth/recuperar_password.php','index.php']
with tempfile.TemporaryDirectory(prefix='impe-convocatorias-publish-') as tmp:
    base=Path(tmp);live=base/'public_html';live.mkdir()
    env=os.environ.copy();env['PATH']=phpdir+os.pathsep+env['PATH'];env['IMPE_CORREOS_PUBLIC']=live.as_posix()
    env['IMPE_CORREOS_BACKUPS']=(base/'backups').as_posix();env['IMPE_MAIL_STATE_DIR']=(base/'impe-private/mail-state').as_posix()
    preserved={};expected={}
    for f in protected:
        assert f not in files
        r=subprocess.run(['git','show',baseline+':'+f],cwd=root,capture_output=True)
        if r.returncode==0:
            p=live/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(r.stdout);preserved[f]=digest(p)
    for f in patches:
        prior=old(baseline,f);p=live/f;p.parent.mkdir(parents=True,exist_ok=True)
        if f.endswith('.php'):prior=norm(prior).replace(b'\n',b'\r\n')
        if f==patches[0]:prior+=b'\r\n// personalizacion conservada\r\n'
        p.write_bytes(prior)
        # Aplicar exclusivamente el diff funcional de main a la revisión instalada para obtener el resultado esperado.
        before=norm(old('a90b6ade',f)).decode();after=norm((root/f).read_bytes()).decode()
        lines=difflib.unified_diff(before.splitlines(keepends=True),after.splitlines(keepends=True),fromfile='a/'+f,tofile='b/'+f)
        diff=''.join(line if line.endswith('\n') else line+'\n\\ No newline at end of file\n' for line in lines)
        scratch=base/'expected';target=scratch/f;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(norm(prior))
        patch=base/'change.patch';patch.write_text(diff,encoding='utf-8',newline='\n')
        apply_env=env.copy();apply_env['GIT_CEILING_DIRECTORIES']=str(base)
        checked=subprocess.run(['git','-c','core.autocrlf=false','apply',str(patch)],cwd=scratch,env=apply_env,capture_output=True)
        assert checked.returncode==0,checked.stderr.decode(errors='replace')
        expected[f]=target.read_bytes()
    (live/'app/models').mkdir(exist_ok=True)
    (live/'vendor').mkdir();(live/'vendor/autoload.php').write_text("<?php require "+repr((root/'vendor/autoload.php').as_posix())+';',encoding='utf-8')
    for f in ['config/mail_central.local.php','config/password_reset_mail.local.php','config/hostinger_admin.local.php']:
        p=live/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('<?php // SECRETO FICTICIO CONSERVADO',encoding='utf-8');preserved[f]=digest(p)
    private=base/'impe-private';private.mkdir();(private/'mail_central.local.php').write_text('<?php // PRIVADO FUERA DEL SITIO',encoding='utf-8')
    private_before=digest(private/'mail_central.local.php')
    initial={f:digest(live/f) for f in files}
    def run(*args,ok=True):
        r=subprocess.run([bash,script.as_posix(),*args],env=env,cwd=root,capture_output=True,text=True,timeout=90)
        if (r.returncode==0)!=ok:raise RuntimeError(r.stdout+r.stderr)
        return r.stdout
    run('--check');assert {f:digest(live/f) for f in files}==initial
    out=run('--apply');backup=re.search(r'^Respaldo: (.+)$',out,re.M)[1]
    for f in files:
        # Instalador v1 congelado: su payload corresponde a esa entrega, no al reparador v2.
        assert norm((live/f).read_bytes())==(expected[f] if f in expected else norm(old('2ba65dc4',f))),f
    assert all(digest(live/f)==h for f,h in preserved.items())
    assert digest(private/'mail_central.local.php')==private_before
    assert not (live/'tools').exists(),'No crea directorios de herramientas ajenos al payload'
    assert 'ya aplicado' in run('--apply')
    changed=live/'public/javascript/correo_convocatorias.js';prior=changed.read_bytes();changed.write_bytes(prior+b'\n// cambio posterior\n')
    run('--restore',backup,ok=False);assert changed.read_bytes().endswith(b'// cambio posterior\n');changed.write_bytes(prior)
    run('--restore',backup);assert {f:digest(live/f) for f in files}==initial
    assert all(digest(live/f)==h for f,h in preserved.items())
    (live/patches[0]).write_text('<?php // Personalizado incompatible',encoding='utf-8');before={f:digest(live/f) for f in files}
    run('--check',ok=False);assert {f:digest(live/f) for f in files}==before
    print('OK: seis archivos, check sin escritura, apply/restore, idempotencia, CRLF/comentarios, rechazo de incompatibles y conservación exacta de transporte/Hostinger/recuperación/configuración privada. Base '+baseline+'. Sitio ficticio.')
