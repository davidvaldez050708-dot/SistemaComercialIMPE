#!/usr/bin/env python3
"""Entrega comercial V3 compatible con las seis bases auditadas de Hostinger."""
import argparse
import os
import shutil
import sys
import tempfile
import publicar_recordatorios_comerciales_v3_hostinger as canonico

publicador=canonico.publicador
TELEFONIA_REL=canonico.TELEFONIA_REL
TELEFONIA_HOSTINGER=canonico.TELEFONIA_HOSTINGER
ARCHIVOS_CANONICOS=canonico.ARCHIVOS.copy()
DEPENDENCIAS_CANONICAS=canonico.DEPENDENCIAS.copy()
ARCHIVOS_HOSTINGER=[('app/services/EmbudoComercialV3RecordatoriosService.php', None, 'f52350eeae50a90c6f4655a5670b102ad8c37ac4'), ('app/services/TelefoniaProgramacionVentasService.php', '871baf3097d497e44620601cc4250a26749a4b2e', 'abdfcd8fdc8cee69390377eccd3b8c3338761a90'), ('app/services/EmbudoComercialRepository.php', 'c562e2032939aceaad8b2e9c60f7765176d2eae6', 'b0dae33b915edbecf7dffe44762fec92c4606244'), ('app/controllers/ReminderController.php', '0e3279b39383c10a1e5381b407001934fc624474', '234c77b18c1b4f2635e82b112f166ca95d216eca'), ('public/javascript/recordatorios.js', '18530b7f659d2147cc494fa0c3ab061e7964c367', '390f4e90e191ccbdd0b8042685b4921bd3cb594a'), ('public/javascript/embudo_comercial_v3_operativo.js', '435c9e2432ddb510221cf8fe2c077a073bb4d3cd', '963186bccf04ba9245a68362670b464f75b63f1b')]
BASES_HOSTINGER={'app/services/AgendaReunionService.php': 'c4722cfeabe0492e622f57c60a8a6fdaa242afaf', 'app/controllers/ReminderController.php': '0e3279b39383c10a1e5381b407001934fc624474', 'app/views/layout/topbar.php': '53f70bfec09d2b8e8a0fe6ff372ffb96e21711a3', 'public/javascript/recordatorios.js': '18530b7f659d2147cc494fa0c3ab061e7964c367', 'app/helpers/ReminderHelper.php': '668a1be1f02bf445d609441be687a74fa839082c', 'app/views/layout/dashboard_footer.php': '8dbc61ccb285462ddef82955d1659b584d4cbbe7'}
ARCHIVOS=ARCHIVOS_CANONICOS.copy()
DEPENDENCIAS=DEPENDENCIAS_CANONICAS.copy()
HOSTINGER=False
PREFIJO='tools/recordatorios_comerciales_v3_hostinger/after/'
ADAPTADOS={'app/controllers/ReminderController.php','public/javascript/recordatorios.js'}

def seleccionar_variante(sitio):
    global ARCHIVOS,DEPENDENCIAS,HOSTINGER
    rel='app/controllers/ReminderController.php'
    fila=next(x for x in ARCHIVOS_HOSTINGER if x[0]==rel)
    actual=publicador.blob(publicador.confiable(sitio,rel))
    HOSTINGER=actual in fila[1:]
    ARCHIVOS=(ARCHIVOS_HOSTINGER if HOSTINGER else ARCHIVOS_CANONICOS).copy()
    DEPENDENCIAS=DEPENDENCIAS_CANONICAS.copy()
    if HOSTINGER:
        # Contratos ya usados por el controlador de producción, preservados sin
        # cambios. No son requisitos nuevos del flujo de actividades comerciales.
        DEPENDENCIAS.pop('public/javascript/telefonia_notificaciones.js',None)
        DEPENDENCIAS.pop('app/services/TelefoniaNotificacionVentasService.php',None)
        for path in ['app/helpers/ReminderHelper.php','app/services/AgendaReunionService.php',
                     'app/views/layout/topbar.php','app/views/layout/dashboard_footer.php']:
            DEPENDENCIAS[path]=BASES_HOSTINGER[path]
    for path,_,_ in ARCHIVOS:DEPENDENCIAS.pop(path,None)
    return HOSTINGER

def configurar_dependencias(sitio):
    canonico.DEPENDENCIAS=DEPENDENCIAS
    canonico.configurar_dependencias(sitio)
    if HOSTINGER:print('COMPATIBLE_HOSTINGER: bases exactas auditadas; Agenda, alcance territorial, encabezado y pie conservados.')

def comprobar_esquema(sitio):
    canonico.comprobar_esquema(sitio)
    if HOSTINGER:
        for rel in ['app/services/AgendaComentarioService.php','app/helpers/SeguimientoTerritorialScope.php']:
            p=publicador.confiable(sitio,rel)
            if not os.path.isfile(p):raise publicador.Bloqueo('dependencia existente de producción ausente: '+rel)
            publicador.lint_php(p)

def ejecutar():
    entrega=canonico.anterior.tipos.anterior.supervision
    entrega.ARCHIVOS=ARCHIVOS
    entrega.DEPENDENCIAS=DEPENDENCIAS
    entrega.comprobar_esquema=comprobar_esquema
    entrega.main()

def main():
    if '--help' in sys.argv or '-h' in sys.argv:return canonico.main()
    parser=argparse.ArgumentParser(add_help=False)
    parser.add_argument('--site',required=True);parser.add_argument('--stage')
    parser.add_argument('--rollback',action='store_true')
    args,_=parser.parse_known_args()
    sitio=publicador.raiz(args.site)
    seleccionar_variante(sitio)
    configurar_dependencias(sitio)
    if not HOSTINGER or args.rollback:return ejecutar()
    if not args.stage:raise publicador.Bloqueo('Indica la carpeta privada del paquete con --stage.')
    stage=publicador.raiz(args.stage)
    if publicador.dentro(stage,sitio):raise publicador.Bloqueo('El paquete debe estar fuera de public_html.')
    # Proyección privada de los seis archivos probados. Los dos adaptados tienen
    # hashes propios; no se aplican parches abiertos sobre archivos de producción.
    with tempfile.TemporaryDirectory(prefix='impe-recordatorios-compat-') as proyectado:
        for rel,_,esperado in ARCHIVOS:
            origen=publicador.confiable(stage,(PREFIJO if rel in ADAPTADOS else '')+rel)
            if publicador.blob(origen)!=esperado:raise publicador.Bloqueo('paquete adaptado alterado: '+rel)
            destino=publicador.confiable(proyectado,rel)
            os.makedirs(os.path.dirname(destino),exist_ok=True)
            shutil.copyfile(origen,destino)
        originales=sys.argv.copy()
        try:
            for i,valor in enumerate(sys.argv):
                if valor=='--stage':sys.argv[i+1]=proyectado
                elif valor.startswith('--stage='):sys.argv[i]='--stage='+proyectado
            ejecutar()
        finally:sys.argv[:]=originales

if __name__=='__main__':
    try:main()
    except (publicador.Bloqueo,OSError,ValueError) as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr)
        sys.exit(2)
