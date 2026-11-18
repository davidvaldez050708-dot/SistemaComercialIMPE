#!/usr/bin/env python3
"""Consulta global única, asesor con alcance propio y bandeja sin chats vacíos."""
import argparse
import contextlib
import hashlib
import io
import json
import os
import subprocess
import sys
import tempfile
import shutil
import publicar_ventas_v3_gestion_potenciales_hostinger as motor

ARCHIVOS_CANONICOS=[('app/models/WhatsAppModel.php', 'b2d2d3bbe1415257a70dc0aa405fd064a6f3c645', '4fc5137ca2c6b11931c23a598a22515f5811a2ab'), ('app/services/WhatsAppContextoService.php', 'abbfb95c4569b5ac77c9caffdec8f1ab78e72966', 'a6abff24b2d940a702a5dd94b905bae4f15dd271'), ('app/models/RolModel.php', 'b7531f8c97e51493514072d4c9c1080bc1843bc0', '98e47348ff38cfb5b4d3b6b4584fc146e2a91286'), ('app/views/roles/index.php', '517da1d368d1d2ff4f1bebe04ccec645184c5c59', 'b24e9014007ea54ccd834db987e3618848045acd'), ('app/helpers/AdminRolePolicy.php', '72abfb9e72c7cce457e991b6682efcb10d83ea13', 'b1ac53cbb3ce6730831cf495ce13e947f43affda')]
ARCHIVOS_HOSTINGER=[('app/models/WhatsAppModel.php', 'b2d2d3bbe1415257a70dc0aa405fd064a6f3c645', '4fc5137ca2c6b11931c23a598a22515f5811a2ab'), ('app/services/WhatsAppContextoService.php', 'abbfb95c4569b5ac77c9caffdec8f1ab78e72966', 'a6abff24b2d940a702a5dd94b905bae4f15dd271'), ('app/models/RolModel.php', 'c0c9477b650a706887f81d08b0364f3ed0715b07', 'e5c0452d9eb9de10f5cd78a120df154c9d96a13b'), ('app/views/roles/index.php', '338841608e2722f88b70e9df20630dd791ca7002', 'e2742d3d3f72a77d8fca9748a2e5e3dab7a85587'), ('app/helpers/AdminRolePolicy.php', 'bff951b795118560869395159e921e4172de007d', 'fc7b989a06aa5ca6afbd36e9d84f2e6963741059')]
ADAPTADOS={'app/views/roles/index.php', 'app/models/RolModel.php', 'app/helpers/AdminRolePolicy.php'}
PREFIJO='tools/whatsapp_permisos_hostinger/after/'
DEPENDENCIAS_CANONICAS={'app/controllers/WhatsAppController.php': '13345f6b26d13e3d6eac6f481fa21a0ecf72c3cf', 'app/helpers/PermissionHelper.php': '1b5b1d0a1d832fd75f56010ceb7e230b27e329ea'}
DEPENDENCIAS_HOSTINGER={**DEPENDENCIAS_CANONICAS,'app/helpers/PermissionHelper.php': '63923e8c3d1a874ecbe8f2a56e35ba1feb099846','app/helpers/SupervisionRolePolicy.php':'0395e31f2d361807f710e8e173cdcc551393cd40'}
MIGRACION='tools/whatsapp_permisos_migracion.php'
MIGRACION_SHA256='593071a866b9fcc080b37bba32a10cc6e4c23e07a5a4282182cff9f207c2e91f'
SNAPSHOT='whatsapp_permisos.json'
ARCHIVOS=ARCHIVOS_CANONICOS
HOSTINGER=False

def mensajes(text):
    return (text.replace('V3_GESTION_LISTO','WHATSAPP_PERMISOS_LISTO')
        .replace('gestión segura de Potenciales V3','permisos y bandeja de WhatsApp')
        .replace('gestión segura V3','permisos y bandeja de WhatsApp')
        .replace('gestión de Potenciales V3','permisos y bandeja de WhatsApp')
        .replace('No se modificó SQL, PBX, V4, resultados, asignaciones ni permisos.','Se unificó exclusivamente el alcance global de WhatsApp y el rol Asesor; no se modificaron mensajes, prospectos ni permisos de otros módulos.'))

def seleccionar(site):
    global ARCHIVOS,HOSTINGER
    adm=next(r for r in ARCHIVOS_HOSTINGER if r[0]=='app/helpers/AdminRolePolicy.php')
    HOSTINGER=motor.blob(motor.confiable(site,adm[0])) in adm[1:]
    ARCHIVOS=(ARCHIVOS_HOSTINGER if HOSTINGER else ARCHIVOS_CANONICOS).copy()
    motor.ARCHIVOS=ARCHIVOS;motor.NO_MODIFICAR=(DEPENDENCIAS_HOSTINGER if HOSTINGER else DEPENDENCIAS_CANONICAS).copy()
    if HOSTINGER:print('COMPATIBLE_PERMISOS_HOSTINGER: políticas de Agenda, Formularios, Inscritos y confirmación de roles conservadas.')

def migracion(source,site,mode,snapshot):
    if hashlib.sha256(motor.leer(source)).hexdigest()!=MIGRACION_SHA256:raise motor.Bloqueo('Herramienta de permisos alterada.')
    try:
        out=subprocess.check_output(['php',source,site,mode,snapshot],stderr=subprocess.STDOUT).decode()
        data=json.loads(out)
    except (OSError,subprocess.CalledProcessError,ValueError):raise motor.Bloqueo('No se pudo verificar o actualizar el alcance WhatsApp. No se sobrescriben cambios posteriores de permisos.')
    if data!={'ok':True,'modo':mode}:raise motor.Bloqueo('Respuesta de permisos inesperada.')

def sellar(snapshot):
    digest=hashlib.sha256(motor.leer(snapshot)).hexdigest()
    path=snapshot+'.sha256'
    with open(path,'x',encoding='ascii') as f:f.write(digest+'\n');f.flush();os.fsync(f.fileno())
    os.chmod(path,0o600)

def verificar(snapshot):
    with open(snapshot+'.sha256',encoding='ascii') as f:expected=f.read().strip()
    if hashlib.sha256(motor.leer(snapshot)).hexdigest()!=expected:raise motor.Bloqueo('Respaldo de permisos alterado.')

def main():
    p=argparse.ArgumentParser(description=__doc__)
    for k in ['site','stage','backup-root','backup-dir','confirm']:p.add_argument('--'+k,required=k=='site')
    modes=p.add_mutually_exclusive_group(required=True)
    for k in ['check','apply','rollback']:modes.add_argument('--'+k,action='store_true')
    a=p.parse_args();site=motor.raiz(a.site);seleccionar(site)
    source=os.path.join(os.path.dirname(os.path.abspath(__file__)),'whatsapp_permisos_migracion.php')
    if a.rollback:
        if a.confirm!='REVERTIR_WHATSAPP_PERMISOS_IMPE' or not a.backup_dir:raise motor.Bloqueo('Indica respaldo exacto y confirmación de reversión.')
        backup=motor.raiz(a.backup_dir)
        if motor.dentro(backup,site):raise motor.Bloqueo('El respaldo debe estar fuera del sitio.')
        snapshot=motor.confiable(backup,SNAPSHOT);verificar(snapshot);migracion(source,site,'verify',snapshot)
        # No anunciar éxito hasta que también se haya verificado la reversión de permisos.
        with contextlib.redirect_stdout(io.StringIO()):motor.revertir(site,backup)
        migracion(source,site,'restore',snapshot)
        print('ROLLBACK_PERMISOS_OK: configuración anterior de WhatsApp verificada; sin modificar otros permisos ni mensajes.');return
    if not a.stage:raise motor.Bloqueo('Indica la entrega privada con --stage.')
    stage=motor.raiz(a.stage)
    if motor.dentro(stage,site):raise motor.Bloqueo('La entrega debe estar fuera del sitio.')
    for rel,_,new in ARCHIVOS:
        path=motor.confiable(stage,(PREFIJO if HOSTINGER and rel in ADAPTADOS else '')+rel)
        if not os.path.isfile(path) or motor.blob(path)!=new:raise motor.Bloqueo('Entrega alterada: '+rel)
        if rel.endswith('.php'):motor.lint_php(path)
    # No omitir metadatos ni comprobar credenciales con envíos de prueba.
    status=io.StringIO()
    with contextlib.redirect_stdout(status):estado=motor.estado(site)
    print(mensajes(status.getvalue()),end='');migracion(source,site,'check','-')
    if HOSTINGER:
        for rel in ['app/helpers/FormularioPermisos.php','app/helpers/InscritosPermisos.php']:
            path=motor.confiable(site,rel)
            if not os.path.isfile(path):raise motor.Bloqueo('Dependencia existente ausente: '+rel)
            motor.lint_php(path)
    if a.check:print('CHECK_OK: '+('entrega ya aplicada.' if estado=='READY' else 'bases compatibles; sólo lectura.'));return
    if a.confirm!='PUBLICAR_WHATSAPP_PERMISOS_IMPE' or not a.backup_root:raise motor.Bloqueo('Indica respaldo privado y confirmación de publicación.')
    if estado=='READY':print('SIN_CAMBIOS: no se repite la modificación de permisos.');return
    active=[];original=motor.respaldar
    def respaldar(site,root):
        directory,data=original(site,root);snapshot=os.path.join(directory,SNAPSHOT);active.append(snapshot)
        try:migracion(source,site,'apply',snapshot)
        finally:
            if os.path.isfile(snapshot) and not os.path.exists(snapshot+'.sha256'):sellar(snapshot)
        print('RESPALDO_PERMISOS_VERIFICADO: '+snapshot);return directory,data
    out=io.StringIO()
    try:
        motor.respaldar=respaldar
        with tempfile.TemporaryDirectory(prefix='impe-wa-permisos-') as projected:
            for rel,_,_ in ARCHIVOS:
                src=motor.confiable(stage,(PREFIJO if HOSTINGER and rel in ADAPTADOS else '')+rel)
                dst=motor.confiable(projected,rel);os.makedirs(os.path.dirname(dst),exist_ok=True);shutil.copyfile(src,dst)
            with contextlib.redirect_stdout(out):motor.publicar(site,projected,motor.raiz(a.backup_root,False))
    except BaseException:
        if active and os.path.isfile(active[0]):
            verificar(active[0]);migracion(source,site,'restore',active[0]);print('REVERSION_PERMISOS_AUTOMATICA_OK')
        raise
    finally:
        motor.respaldar=original
        print(mensajes(out.getvalue()),end='')
    print('WHATSAPP_PERMISOS_OK: una consulta global, asesor con alcance propio y chats vacíos fuera de la bandeja.')

if __name__=='__main__':
    try:main()
    except (motor.Bloqueo,OSError,ValueError) as e:print('BLOQUEADO: '+str(e),file=sys.stderr);sys.exit(2)
