#!/usr/bin/env python3
"""Notificaciones a tarjeta: entrega selectiva, bases exactas y reversión; sólo lecturas SQL."""
import argparse
import os
import subprocess
import sys
import publicar_recordatorios_comerciales_v3_compatible_hostinger as anterior

ARCHIVOS = [('app/services/EmbudoComercialV3RecordatoriosService.php', 'f52350eeae50a90c6f4655a5670b102ad8c37ac4', 'cec5c5b25b9bc3f1174beb4d5fffa0bbfb35dbbf'), ('app/services/EmbudoComercialRepository.php', 'b0dae33b915edbecf7dffe44762fec92c4606244', 'a05393f0f066ee73fe27ce2f72eafc9e12343272'), ('app/controllers/EmbudoComercialController.php', 'bd9d42544409875d35a0ec2c980afc7c1d18c317', 'b9525d9dad202d86c32d9f16e5268143702db484'), ('app/views/embudo_comercial/operativo_v3.php', 'af214cb21fc5322b6cbb2e0a204ee8c5e32cfe15', 'fb395595b6e483a81732e9de2bc593fe315480a3'), ('app/views/embudo_comercial/bandejas_v3.php', 'd8a90af671220d465c871b7e70ca40dcb36a0395', 'b4bba34ce66cff54d0a209010593d0726f86aeba'), ('app/views/embudo_comercial/expedientes.php', 'c57d33326c46f610e12b70b80ab6f9427cad2365', '4870ddbed2228a62388d34d5d9c4e424c5158e63'), ('app/views/embudo_comercial/notificacion_destino.php', None, 'cea6013f04303ea60fc83d122a6cc1322b714a94'), ('public/css/embudo_notificacion_destino.css', None, 'c7536de5c1183dd5cf37c09751b56a65f068cb27'), ('public/javascript/embudo_notificacion_destino.js', None, '2952e64e13c30f725df8d83c3f9545a4080aeb72')]
publicador=anterior.publicador
DEPENDENCIAS={}

def configurar_dependencias(sitio):
    global DEPENDENCIAS
    anterior.seleccionar_variante(sitio)
    DEPENDENCIAS=anterior.DEPENDENCIAS.copy()
    DEPENDENCIAS.update({rel:new for rel,_,new in anterior.ARCHIVOS})
    for rel,_,_ in ARCHIVOS:DEPENDENCIAS.pop(rel,None)
    anterior.DEPENDENCIAS=DEPENDENCIAS
    anterior.configurar_dependencias(sitio)
    publicador.ARCHIVOS=ARCHIVOS
    publicador.NO_MODIFICAR=DEPENDENCIAS

def comprobar_esquema(sitio):
    anterior.comprobar_esquema(sitio)
    codigo="require $argv[1].'/config/db_connection.php';$db=(new Database())->connect(false);$r=$db->query('SELECT ROW_NUMBER() OVER (ORDER BY n) posicion FROM (SELECT 1 n) x')->fetch_assoc();if((int)$r['posicion']!==1)exit(2);echo 'LOCALIZACION_SQL_OK';"
    try:
        salida=subprocess.check_output(['php','-r',codigo,'--',sitio],stderr=subprocess.STDOUT).decode().strip()
    except (OSError,subprocess.CalledProcessError):
        raise publicador.Bloqueo('MariaDB no admite la localización paginada; sin cambios.')
    if salida!='LOCALIZACION_SQL_OK':raise publicador.Bloqueo('Respuesta de compatibilidad inesperada; sin cambios.')
    print(salida)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    for key in ['site','stage','backup-root','backup-dir','confirm']:parser.add_argument('--'+key,required=key=='site')
    mode=parser.add_mutually_exclusive_group(required=True)
    for key in ['check','apply','rollback']:mode.add_argument('--'+key,action='store_true')
    args=parser.parse_args()
    sitio=publicador.raiz(args.site)
    configurar_dependencias(sitio)
    if args.rollback:
        if args.confirm!='REVERTIR_UBICAR_NOTIFICACIONES_V3_IMPE' or not args.backup_dir:raise publicador.Bloqueo('Indica respaldo y confirmación de reversión.')
        respaldo=publicador.raiz(args.backup_dir)
        if publicador.dentro(respaldo,sitio):raise publicador.Bloqueo('El respaldo debe estar fuera del sitio.')
        publicador.revertir(sitio,respaldo)
        return
    if not args.stage:raise publicador.Bloqueo('Indica --stage.')
    stage=publicador.raiz(args.stage)
    if publicador.dentro(stage,sitio):raise publicador.Bloqueo('La entrega debe estar fuera del sitio.')
    for rel,_,esperado in ARCHIVOS:
        path=publicador.confiable(stage,rel)
        if not os.path.isfile(path) or publicador.blob(path)!=esperado:raise publicador.Bloqueo('Paquete alterado: '+rel)
        if rel.endswith('.php'):publicador.lint_php(path)
        if rel.endswith('.js'):publicador.lint_js(path)
    estado=publicador.estado(sitio)
    comprobar_esquema(sitio)
    if args.check:
        print('CHECK_OK: '+('entrega ya aplicada.' if estado=='READY' else 'bases compatibles; sitio intacto.'))
        return
    if args.confirm!='PUBLICAR_UBICAR_NOTIFICACIONES_V3_IMPE' or not args.backup_root:raise publicador.Bloqueo('Indica respaldo privado y confirmación de publicación.')
    publicador.publicar(sitio,stage,publicador.raiz(args.backup_root,False))
    print('UBICAR_NOTIFICACIONES_OK: tarjeta, página y sección vigentes; sin modificar tareas.')

if __name__=='__main__':
    try:main()
    except (publicador.Bloqueo,OSError,ValueError) as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr)
        sys.exit(2)
