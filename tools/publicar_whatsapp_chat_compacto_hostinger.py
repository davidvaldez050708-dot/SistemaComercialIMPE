#!/usr/bin/env python3
"""Ventana flotante de WhatsApp: cuatro archivos visuales, sin SQL ni cambios de permisos."""
import argparse
import contextlib
import io
import os
import sys
import publicar_ventas_v3_gestion_potenciales_hostinger as motor

ARCHIVOS=[('public/css/whatsapp_contextual.css', '67d40b9e59816d37e40aa0a3aebc3008e55561cc', 'b5f7baf2ca6bd6e5600cd08ccc2c6623d952ea4f'), ('public/javascript/whatsapp_contextual.js', '11feb854a1835e253d2193991611e6e80b22dea4', 'f916582fc46c7332aa95ab7e27ac5196b8aa1b8e'), ('app/views/whatsapp/acceso_contextual.php', '3c2c3cd112989dcd329c0f7b785ad8ffa2bc091f', '62e8b67a8ea0158526d0dbcac2e6d5465be2382e'), ('app/views/whatsapp/panel.php', '16ad280dd5059d90c8b1e8f80711086722bea708', '42f0d9a3cdb248da3c48389c992f948c7d13c154')]

def main():
    p=argparse.ArgumentParser(description=__doc__)
    for key in ['site','stage','backup-root','backup-dir','confirm']:p.add_argument('--'+key,required=key=='site')
    modes=p.add_mutually_exclusive_group(required=True)
    for key in ['check','apply','rollback']:modes.add_argument('--'+key,action='store_true')
    a=p.parse_args();site=motor.raiz(a.site);motor.ARCHIVOS=ARCHIVOS;motor.NO_MODIFICAR={}
    if a.rollback:
        if a.confirm!='REVERTIR_WHATSAPP_CHAT_COMPACTO_IMPE' or not a.backup_dir:raise motor.Bloqueo('Indica respaldo exacto y confirmación de reversión.')
        backup=motor.raiz(a.backup_dir)
        if motor.dentro(backup,site):raise motor.Bloqueo('El respaldo debe estar fuera del sitio.')
        motor.revertir(site,backup);return
    if not a.stage:raise motor.Bloqueo('Indica la entrega con --stage.')
    stage=motor.raiz(a.stage)
    if motor.dentro(stage,site):raise motor.Bloqueo('La entrega debe estar fuera del sitio.')
    for rel,_,expected in ARCHIVOS:
        source=motor.confiable(stage,rel)
        if not os.path.isfile(source) or motor.blob(source)!=expected:raise motor.Bloqueo('Entrega alterada: '+rel)
        if rel.endswith('.php'):motor.lint_php(source)
        if rel.endswith('.js'):motor.lint_js(source)
    estado=motor.estado(site)
    if a.check:
        print('CHECK_OK: '+('entrega ya aplicada.' if estado=='READY' else 'cuatro archivos compatibles; sitio intacto.'))
        return
    if a.confirm!='PUBLICAR_WHATSAPP_CHAT_COMPACTO_IMPE' or not a.backup_root:raise motor.Bloqueo('Indica respaldo privado y confirmación de publicación.')
    out=io.StringIO()
    try:
        with contextlib.redirect_stdout(out):motor.publicar(site,stage,motor.raiz(a.backup_root,False))
    finally:print(out.getvalue().replace('gestión de Potenciales V3','ventana flotante de WhatsApp').replace('gestión segura de Potenciales V3','ventana flotante de WhatsApp'),end='')
    print('WHATSAPP_CHAT_COMPACTO_OK: presentación únicamente; sin SQL, envíos ni cambios de permisos.')

if __name__=='__main__':
    try:main()
    except (motor.Bloqueo,OSError,ValueError) as e:print('BLOQUEADO: '+str(e),file=sys.stderr);sys.exit(2)
