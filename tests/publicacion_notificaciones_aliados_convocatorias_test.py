#!/usr/bin/env python3
"""Verifica publicador selectivo: parches, idempotencia, respaldo, rechazo de variantes."""
import hashlib,json,os,pathlib,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
script=root/'tools/publicar_notificaciones_aliados_convocatorias_hostinger.sh'
raw=script.read_text(encoding='utf8')
spec=json.loads(raw.split("SPEC=json.loads(r'''",1)[1].split("''')",1)[0])
paths=[x['path'] for x in spec['changes']]
with tempfile.TemporaryDirectory() as d:
 base=pathlib.Path(d)
 public=base/'public_html'
 public.mkdir()
 (public/'index.php').write_text('<?php',encoding='utf8')
 original={}
 for ch in spec['changes']:
    path=public/ch['path']
    path.parent.mkdir(parents=True,exist_ok=True)
    target=(root/ch['path']).read_text(encoding='utf8')
    for old,new in reversed(ch['patches']):
        assert target.count(new)==1,ch['path']
        target=target.replace(new,old,1)
    if ch['path']=='app/views/layout/topbar.php':
        # Simular la campana unica ya publicada previamente en Hostinger.
        target=target.replace("""// El permiso compartido no cambia el rol ni crea una segunda campana.
$esMarketingTopbar =
    !$esAdministradorTopbar &&
    strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0 &&
    tienePermiso('convocatorias.gestionar');""",
        """// Un permiso compartido no convierte a Cuenta Clave en Marketing.
$esMarketingTopbar =
    !$esAdministradorTopbar &&
    strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0 &&
    tienePermiso('convocatorias.gestionar');""")
        target=target.replace("if ($esMarketingTopbar) $mostrarCentroAvisos = false;",
        "if ($esMarketingTopbar) {\n    $mostrarCentroAvisos = false;\n}")
    path.write_text(target,encoding='utf8')
    original[ch['path']]=path.read_bytes()
 service=public/spec['new_file']['path']
 def run(*args):
    env=os.environ.copy()
    env['IMPE_CRM_PUBLIC']=str(public)
    env['IMPE_CRM_BACKUPS']=str(base/'private')
    return subprocess.run(['bash',str(script)]+list(args),env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
 check=run('--check')
 assert check.returncode==0,(check.stdout,check.stderr)
 assert 'Archivos a modificar: 5' in check.stdout,check.stdout
 assert not service.exists() and not (base/'private').exists()
 apply=run('--apply')
 assert apply.returncode==0,(apply.stdout,apply.stderr)
 assert service.exists()
 for ch in spec['changes']:
    assert 'aliado' in (public/ch['path']).read_text(encoding='utf8').lower() or ch['path'].endswith('.js')
 again=run('--check')
 assert again.returncode==0 and 'Archivos a modificar: 0' in again.stdout,(again.stdout,again.stderr)
 folder=pathlib.Path(apply.stdout.split('Respaldo: ')[1].splitlines()[0])
 restore=run('--restore',str(folder))
 assert restore.returncode==0,(restore.stdout,restore.stderr)
 assert not service.exists()
 assert all((public/p).read_bytes()==data for p,data in original.items())
 # Cambios desconocidos deben bloquear la instalacion.
 file=public/paths[0]
 file.write_bytes(file.read_bytes().replace(b'$puedeTelefonia=',b'$otraTelefonia=',1))
 conflict=run('--check')
 assert conflict.returncode!=0 and 'CONFLICTO' in conflict.stderr
 print('OK: 5 archivos selectivos, restauracion completa, idempotencia y rechazo de personalizaciones.')
