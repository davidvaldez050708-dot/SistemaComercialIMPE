#!/usr/bin/env python3
"""Variante WhatsApp auditada: conserva políticas, R16, rutas y difusión de Hostinger."""
import argparse
import os
import shutil
import sys
import tempfile
import publicar_whatsapp_contextual_hostinger as canonico

ARCHIVOS_CANONICOS=canonico.ARCHIVOS.copy()
DEPENDENCIAS_CANONICAS=canonico.DEPENDENCIAS.copy()
ARCHIVOS_HOSTINGER=[('app/services/WhatsAppContextoService.php', None, 'abbfb95c4569b5ac77c9caffdec8f1ab78e72966'), ('app/views/whatsapp/chat.php', None, '6198619d464ed7db79c6ded78f48975680502426'), ('app/views/whatsapp/panel.php', None, '16ad280dd5059d90c8b1e8f80711086722bea708'), ('app/views/whatsapp/acceso_contextual.php', None, '3c2c3cd112989dcd329c0f7b785ad8ffa2bc091f'), ('public/javascript/whatsapp_contextual.js', None, '11feb854a1835e253d2193991611e6e80b22dea4'), ('public/css/whatsapp_contextual.css', None, '67d40b9e59816d37e40aa0a3aebc3008e55561cc'), ('app/models/WhatsAppModel.php', '6778077a321e0ebc16ee19885fc5c32eda6496c6', 'b2d2d3bbe1415257a70dc0aa405fd064a6f3c645'), ('app/views/whatsapp/index.php', '6533826817bc89bcf9d8b07e9e267216873cf2d8', '2f37b6738b6c64594c40adc873ca94971bad97e6'), ('public/javascript/whatsapp.js', '98bcb39bbbd5b0d15666caf8d8c7d6692a80e890', 'a5ea390ff8125db3acabedf13454e22c3e2508fe'), ('app/views/embudo_comercial/operativo_v3.php', 'af214cb21fc5322b6cbb2e0a204ee8c5e32cfe15', 'b93fd86c5be55deeadc4ce007cff1eac57d2d37f'), ('app/views/aliados/estado.php', '75fedef6d4532fdf02a1dbda8252486af945b46e', 'd0f8b822f9bf26eea8e56568c7b7fe3561d75377'), ('app/views/seguimiento_vinculacion/detalle.php', '679e090b4748ef74f94722522b314ad8cc82fa69', '015a11a7962426564b8fcb37f935abd6461e0661'), ('app/controllers/WhatsAppController.php', '527c2861d90ba04bd885c15ddf6c8f49f674d31f', '13345f6b26d13e3d6eac6f481fa21a0ecf72c3cf')]
DEPENDENCIAS_HOSTINGER={'app/services/WhatsAppCloudApiService.php': '19af3ebdc78936fddde32ee436f55699f1f34e24', 'app/services/ProspectoNoContactarV3.php': '27b957a3ef3ba45fa61ff57947a7443b1c725436', 'app/helpers/PermissionHelper.php': '63923e8c3d1a874ecbe8f2a56e35ba1feb099846', 'app/helpers/AdminRolePolicy.php': 'bff951b795118560869395159e921e4172de007d', 'index.php': '6f3eb171bec0a47f09b1fd17adb685aa41bf82f6', 'app/helpers/SupervisionRolePolicy.php': '0395e31f2d361807f710e8e173cdcc551393cd40'}
PREFIJO='tools/whatsapp_contextual_hostinger/after/'
ADAPTADOS={'app/views/embudo_comercial/operativo_v3.php','app/views/aliados/estado.php'}
motor=canonico.motor


def seleccionar(sitio):
    rel='app/helpers/PermissionHelper.php'
    actual=motor.blob(motor.confiable(sitio,rel))
    host=actual==DEPENDENCIAS_HOSTINGER[rel]
    canonico.ARCHIVOS=(ARCHIVOS_HOSTINGER if host else ARCHIVOS_CANONICOS).copy()
    canonico.DEPENDENCIAS=(DEPENDENCIAS_HOSTINGER if host else DEPENDENCIAS_CANONICAS).copy()
    if host:print('COMPATIBLE_WHATSAPP_HOSTINGER: variantes exactas auditadas; permisos, R16, rutas, tarjetas y avance de difusión conservados.')
    return host


def main():
    if '--help' in sys.argv or '-h' in sys.argv:return canonico.main()
    p=argparse.ArgumentParser(add_help=False);p.add_argument('--site',required=True);p.add_argument('--stage');p.add_argument('--rollback',action='store_true');p.add_argument('--audit',action='store_true')
    a,_=p.parse_known_args();site=motor.raiz(a.site)
    host=seleccionar(site)
    if not host or a.rollback or a.audit:return canonico.main()
    if not a.stage:raise motor.Bloqueo('Indica la entrega privada con --stage.')
    stage=motor.raiz(a.stage)
    if motor.dentro(stage,site):raise motor.Bloqueo('La entrega debe estar fuera del sitio.')
    with tempfile.TemporaryDirectory(prefix='impe-whatsapp-compatible-') as projected:
        for rel,_,expected in canonico.ARCHIVOS:
            source=motor.confiable(stage,(PREFIJO if rel in ADAPTADOS else '')+rel)
            if not os.path.isfile(source) or motor.blob(source)!=expected:raise motor.Bloqueo('Archivo adaptado incorrecto: '+rel)
            dest=motor.confiable(projected,rel);os.makedirs(os.path.dirname(dest),exist_ok=True);shutil.copyfile(source,dest)
        sql=canonico.SQL_REL;dest=motor.confiable(projected,sql);os.makedirs(os.path.dirname(dest),exist_ok=True);shutil.copyfile(motor.confiable(stage,sql),dest)
        previous=sys.argv.copy()
        try:
            for i,arg in enumerate(sys.argv):
                if arg=='--stage':sys.argv[i+1]=projected
                elif arg.startswith('--stage='):sys.argv[i]='--stage='+projected
            return canonico.main()
        finally:sys.argv[:]=previous

if __name__=='__main__':
    try:main()
    except (motor.Bloqueo,OSError,ValueError) as e:
        print('BLOQUEADO: '+str(e),file=sys.stderr);sys.exit(2)
