#!/usr/bin/env python3
"""Chat contextual de WhatsApp: preflight, ampliación aditiva, código respaldado y reversión."""
import argparse
import contextlib
import io
import hashlib
import os
import subprocess
import sys
import publicar_ventas_v3_gestion_potenciales_hostinger as motor

ARCHIVOS = [('app/services/WhatsAppContextoService.php', None, 'abbfb95c4569b5ac77c9caffdec8f1ab78e72966'), ('app/views/whatsapp/chat.php', None, '6198619d464ed7db79c6ded78f48975680502426'), ('app/views/whatsapp/panel.php', None, '16ad280dd5059d90c8b1e8f80711086722bea708'), ('app/views/whatsapp/acceso_contextual.php', None, '3c2c3cd112989dcd329c0f7b785ad8ffa2bc091f'), ('public/javascript/whatsapp_contextual.js', None, '11feb854a1835e253d2193991611e6e80b22dea4'), ('public/css/whatsapp_contextual.css', None, '67d40b9e59816d37e40aa0a3aebc3008e55561cc'), ('app/models/WhatsAppModel.php', '6778077a321e0ebc16ee19885fc5c32eda6496c6', 'b2d2d3bbe1415257a70dc0aa405fd064a6f3c645'), ('app/views/whatsapp/index.php', '6533826817bc89bcf9d8b07e9e267216873cf2d8', '2f37b6738b6c64594c40adc873ca94971bad97e6'), ('public/javascript/whatsapp.js', '98bcb39bbbd5b0d15666caf8d8c7d6692a80e890', 'a5ea390ff8125db3acabedf13454e22c3e2508fe'), ('app/views/embudo_comercial/operativo_v3.php', 'fb395595b6e483a81732e9de2bc593fe315480a3', 'ae5ba286455f382921b509d7bf13ce5bcbc8a8f5'), ('app/views/aliados/estado.php', '58f501fcf2766cf484921da8c5e619178e9ee3be', '67dea491b5a17934c79ecbeaa4f0b465454c6f48'), ('app/views/seguimiento_vinculacion/detalle.php', '679e090b4748ef74f94722522b314ad8cc82fa69', '015a11a7962426564b8fcb37f935abd6461e0661'), ('app/controllers/WhatsAppController.php', '527c2861d90ba04bd885c15ddf6c8f49f674d31f', '13345f6b26d13e3d6eac6f481fa21a0ecf72c3cf')]
DEPENDENCIAS={'app/services/WhatsAppCloudApiService.php': '19af3ebdc78936fddde32ee436f55699f1f34e24', 'app/services/ProspectoNoContactarV3.php': '27b957a3ef3ba45fa61ff57947a7443b1c725436', 'app/helpers/PermissionHelper.php': '1b5b1d0a1d832fd75f56010ceb7e230b27e329ea', 'app/helpers/AdminRolePolicy.php': '72abfb9e72c7cce457e991b6682efcb10d83ea13', 'index.php': '185ed202799c5322cf40f8f6cf23489bb938673b'}
SQL_REL='database/migrations/2026_11_18_whatsapp_contexto_comercial.sql'
SQL_SHA256='480b440f46a4103f78a7446320c034823dd35b7dfbc20bdebcc5731a901723d2'

def php(sitio,codigo,*args):
    try:return subprocess.check_output(['php','-r',codigo,'--',sitio,*args],stderr=subprocess.STDOUT).decode().strip()
    except (OSError,subprocess.CalledProcessError):raise motor.Bloqueo('No se pudo comprobar o preparar la estructura de WhatsApp. No se muestran credenciales.')

META=r"""
require $argv[1].'/config/db_connection.php';$db=(new Database())->connect(false);
$tablas=[
 'whatsapp_cuentas'=>['id','phone_number_id','usuario_id','es_predeterminada','activo','tipo'],
 'whatsapp_conversaciones'=>['id','cuenta_id','telefono_normalizado','aliado_seguimiento_id','responsable_usuario_id','ventana_servicio_hasta','no_leidos'],
 'whatsapp_mensajes'=>['id','conversacion_id','wamid','direccion','estado','usuario_id'],
 'whatsapp_webhook_eventos'=>['id','evento_hash','procesado'],
 'prospecto_asignaciones'=>['id','prospecto_id','asesor_id','vigente'],
 'prospectos_comerciales'=>['id','telefono_normalizado','nombre_completo'],
 'prospecto_embudo_etapas'=>['asignacion_id','etapa'],
 'prospecto_embudo_subetapas'=>['asignacion_id','subetapa'],
 'seguimientos_vinculacion'=>['id','estado_id','municipio_id','nombre_entidad','whatsapp_verificado','activo'],
 'asignaciones_territorio'=>['usuario_id','estado_id','tipo_asignacion','activo','fecha_inicio','fecha_fin'],
 'aliados_asignaciones'=>['seguimiento_id','cuenta_clave_usuario_id','activo'],
 'aliados_contactos'=>['seguimiento_id','numero','confirmado_whatsapp','autorizado_whatsapp','activo','preferido_difusion'],
 'usuarios'=>['id','rol_id','estado'], 'roles'=>['id','estado'], 'estados'=>['id','estado'],
 'permisos'=>['id','modulo','codigo','nombre','descripcion','estado']];
foreach($tablas as $tabla=>$campos){
 $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$tabla);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();if(!$r||strtoupper($r['ENGINE'])!=='INNODB')exit(2);
 $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$tabla);$s->execute();$cols=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');$s->close();if(array_diff($campos,$cols))exit(3);
}
$r=$db->query("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_KEY,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='whatsapp_conversacion_prospectos'")->fetch_all(MYSQLI_ASSOC);
if($r){$c=array_column($r,null,'COLUMN_NAME');if(!isset($c['conversacion_id'],$c['prospecto_id'],$c['creado_at'])||$c['conversacion_id']['COLUMN_KEY']!=='PRI'||$c['conversacion_id']['DATA_TYPE']!=='int'||$c['prospecto_id']['DATA_TYPE']!=='bigint'||$c['conversacion_id']['IS_NULLABLE']!=='NO'||$c['prospecto_id']['IS_NULLABLE']!=='NO')exit(4);}
echo $r?'ESQUEMA_WHATSAPP_OK':'ESQUEMA_WHATSAPP_OK;ENLACE_ADITIVO_PENDIENTE';
"""

def comprobar_esquema(sitio):
    result=php(sitio,META)
    if result not in ('ESQUEMA_WHATSAPP_OK','ESQUEMA_WHATSAPP_OK;ENLACE_ADITIVO_PENDIENTE'):raise motor.Bloqueo('Metadatos inesperados; sitio intacto.')
    print(result)

def preparar(sitio,sql):
    codigo=r"""
require $argv[1].'/config/db_connection.php';$db=(new Database())->connect(false);
$sql=preg_replace('/^\s*--.*$/m','',file_get_contents($argv[2]));
foreach(explode(';',$sql) as $parte)if(trim($parte)!=='')$db->query($parte);
echo 'ENLACE_ADITIVO_OK';
"""
    if php(sitio,codigo,sql)!='ENLACE_ADITIVO_OK':raise motor.Bloqueo('No se confirmó el esquema aditivo. Código anterior conservado.')
    comprobar_esquema(sitio)
    print('ENLACE_ADITIVO_OK: tabla de enlace y catálogo de permisos; sin asignar accesos ni modificar mensajes o prospectos.')

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    for k in ['site','stage','backup-root','backup-dir','confirm']:parser.add_argument('--'+k,required=k=='site')
    modes=parser.add_mutually_exclusive_group(required=True)
    for k in ['check','apply','rollback','audit']:modes.add_argument('--'+k,action='store_true')
    a=parser.parse_args();site=motor.raiz(a.site);motor.ARCHIVOS=ARCHIVOS;motor.NO_MODIFICAR=DEPENDENCIAS.copy()
    if a.audit:
        for rel in dict.fromkeys([r[0] for r in ARCHIVOS]+list(DEPENDENCIAS)):
            path=motor.confiable(site,rel)
            if os.path.isfile(path):
                print(hashlib.sha256(motor.leer(path)).hexdigest()+'  '+rel)
            else:print('AUSENTE  '+rel)
        return
    if a.rollback:
        if a.confirm!='REVERTIR_WHATSAPP_CONTEXTUAL_IMPE' or not a.backup_dir:raise motor.Bloqueo('Indica respaldo exacto y confirmación de reversión.')
        backup=motor.raiz(a.backup_dir)
        if motor.dentro(backup,site):raise motor.Bloqueo('El respaldo debe estar fuera del sitio.')
        motor.revertir(site,backup)
        print('Se conservaron los mensajes, enlaces y permisos del catálogo. No se borra SQL al revertir el código.')
        return
    if not a.stage:raise motor.Bloqueo('Indica --stage fuera del sitio.')
    stage=motor.raiz(a.stage)
    if motor.dentro(stage,site):raise motor.Bloqueo('La entrega debe estar fuera del sitio.')
    for rel,_,new in ARCHIVOS:
        if not os.path.isdir(os.path.dirname(motor.confiable(site,rel))):raise motor.Bloqueo('Directorio de aplicación ausente: '+rel)
        path=motor.confiable(stage,rel)
        if not os.path.isfile(path) or motor.blob(path)!=new:raise motor.Bloqueo('Entrega alterada: '+rel)
        if rel.endswith('.php'):motor.lint_php(path)
        if rel.endswith('.js'):motor.lint_js(path)
    sql=motor.confiable(stage,SQL_REL)
    if not os.path.isfile(sql) or hashlib.sha256(motor.leer(sql)).hexdigest()!=SQL_SHA256:raise motor.Bloqueo('Migración aditiva alterada.')
    estado=motor.estado(site);comprobar_esquema(site)
    if a.check:
        print('CHECK_OK: '+('entrega ya aplicada.' if estado=='READY' else 'bases exactas; sitio intacto.'))
        return
    if a.confirm!='PUBLICAR_WHATSAPP_CONTEXTUAL_IMPE' or not a.backup_root:raise motor.Bloqueo('Indica respaldo privado y confirmación de publicación.')
    original=motor.respaldar
    def respaldar_antes_de_preparar(site,root):
        directory,data=original(site,root)
        print('RESPALDO_CODIGO_VERIFICADO: '+directory)
        try:preparar(site,sql)
        except BaseException:
            print('PREPARACION_INTERRUMPIDA: código intacto. El esquema aditivo, si se creó, se conserva; no se borran datos.')
            raise
        return directory,data
    motor.respaldar=respaldar_antes_de_preparar
    salida=io.StringIO()
    try:
        with contextlib.redirect_stdout(salida):motor.publicar(site,stage,motor.raiz(a.backup_root,False))
    finally:
        motor.respaldar=original
        texto=salida.getvalue().replace('gestión de Potenciales V3','WhatsApp contextual').replace('gestión segura de Potenciales V3','WhatsApp contextual')
        texto=texto.replace('No se modificó SQL, PBX, V4, resultados, asignaciones ni permisos.','No se modificaron prospectos, mensajes anteriores, etapas ni asignaciones. Sólo se preparó el enlace aditivo y el catálogo de permisos.')
        print(texto,end='')
    print('WHATSAPP_CONTEXTUAL_OK: no se configuró Meta ni se enviaron mensajes.')

if __name__=='__main__':
    try:main()
    except (motor.Bloqueo,OSError,ValueError) as e:
        print('BLOQUEADO: '+str(e),file=sys.stderr);sys.exit(2)
