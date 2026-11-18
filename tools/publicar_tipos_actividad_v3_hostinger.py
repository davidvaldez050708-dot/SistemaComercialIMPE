#!/usr/bin/env python3
"""Tipos de actividad V3: CHECK de lectura, APPLY aditivo y ROLLBACK de código."""
import os
import stat
import subprocess
import sys
import publicar_acciones_tarjetas_v3_hostinger as anterior

ARCHIVOS = [('app/services/EmbudoComercialV3TipoActividad.php', None, 'ae74c30ace32fa9bdd97de53e56effb20cb72a18'), ('app/services/EmbudoComercialV3ProximaAccion.php', 'f87c3d688cdafa3118158af88453baa02dd178a4', '79f6e2624155d4f9aec2d88afa3f52130c1738a2'), ('app/services/EmbudoComercialV3SeguimientoService.php', 'cc1cd1d61d9f59fbe4d56d68a7dfffb2be27e619', 'b08655969d8a2e86f18d6ebf65b1cd93679f5142'), ('app/services/EmbudoComercialV3GestionPotencialService.php', '86519bcf2d6aea461a619035d7d6acbea814b27c', '42d2b1686a710af13f22ba9d07ab9ebac344c86f'), ('app/services/EmbudoComercialResultadoBridge.php', 'a9666352990f2a5965deaaf98264f6a22e4be40d', 'a68b83a6dfe5740eee9728c79596b050d2bae293'), ('app/services/EmbudoComercialRepository.php', '0ee88c0f7ff62a49d1e7dc20506fa51f1c243e9a', 'c562e2032939aceaad8b2e9c60f7765176d2eae6'), ('app/controllers/EmbudoComercialController.php', '20299073d4d263c3f37a641f6830e98efd497e97', 'bd9d42544409875d35a0ec2c980afc7c1d18c317'), ('app/views/embudo_comercial/detalle.php', 'd49d30db98ea861834e9c214246ac0fc1ea78b2c', '0f2c26d743cb1b4a3b05f7ec61f084cc41db51af'), ('app/views/embudo_comercial/operativo_v3.php', 'a1ea555e4da2659ac50019ac88802d02ed4eebb2', '875b513a097e8b3cf0c4127b7e780a97aaa67e01'), ('public/javascript/embudo_comercial_v3_operativo.js', '28e3b4b26d5dd5c94dc1b877bc1a34ad45fb663f', '435c9e2432ddb510221cf8fe2c077a073bb4d3cd')]
DEPENDENCIAS = anterior.DEPENDENCIAS.copy()
DEPENDENCIAS.update({rel:new for rel,_,new in anterior.ARCHIVOS})
for rel,_,_ in ARCHIVOS:DEPENDENCIAS.pop(rel,None)
publicador = anterior.publicador
TELEFONIA_REL = anterior.TELEFONIA_REL
TELEFONIA_HOSTINGER = anterior.TELEFONIA_HOSTINGER

def configurar_dependencias(sitio):
    anterior.DEPENDENCIAS=DEPENDENCIAS
    anterior.configurar_dependencias(sitio)

def esquema_tipos(sitio,aplicar=False):
    codigo=r"""
require $argv[1].'/config/db_connection.php';
$db=(new Database())->connect(false);
$r=$db->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE,COLUMN_DEFAULT
 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
 AND TABLE_NAME='prospecto_embudo_actividad_v3' AND COLUMN_NAME IN ('tipo_actividad','detalle_actividad')");
$cols=[];foreach($r->fetch_all(MYSQLI_ASSOC) as $c)$cols[$c['COLUMN_NAME']]=$c;
foreach($cols as $nombre=>$c){
 if($c['DATA_TYPE']!=='varchar' || (int)$c['CHARACTER_MAXIMUM_LENGTH']!==($nombre==='tipo_actividad'?32:190) ||
 $c['IS_NULLABLE']!==($nombre==='tipo_actividad'?'NO':'YES'))exit(3);
 if($nombre==='tipo_actividad' && trim($c['COLUMN_DEFAULT'],"'")!=='GESTION')exit(3);
}
if(count($cols)!==2){
 if($argv[2]!=='APPLY'){echo 'ESQUEMA_TIPOS_PENDIENTE';exit;}
 // MariaDB aislada verificada: cambio instantáneo, sin reconstruir ni actualizar registros.
 $db->query("SET SESSION lock_wait_timeout=5");
 $db->query("ALTER TABLE prospecto_embudo_actividad_v3
 ADD COLUMN IF NOT EXISTS tipo_actividad VARCHAR(32) NOT NULL DEFAULT 'GESTION',
 ADD COLUMN IF NOT EXISTS detalle_actividad VARCHAR(190) NULL, ALGORITHM=INSTANT, LOCK=NONE");
}
echo 'ESQUEMA_TIPOS_OK';
"""
    try:
        salida=subprocess.check_output(['php','-r',codigo,'--',sitio,'APPLY' if aplicar else 'CHECK'],stderr=subprocess.STDOUT).decode().strip()
    except (OSError,subprocess.CalledProcessError):
        raise publicador.Bloqueo('columnas de actividad incompatibles o ampliación instantánea no disponible; no se publica código.')
    if salida not in ('ESQUEMA_TIPOS_OK','ESQUEMA_TIPOS_PENDIENTE'):
        raise publicador.Bloqueo('respuesta inesperada de metadatos; no se publica código.')
    print(salida)
    return salida=='ESQUEMA_TIPOS_OK'

def comprobar_esquema(sitio):
    anterior.comprobar_esquema(sitio)
    listo=esquema_tipos(sitio)
    if not listo and publicador.estado(sitio)=='READY':
        raise publicador.Bloqueo('código publicado sin esquema completo; requiere revisión.')

def publicar_tipos(sitio,stage,root):
    if publicador.estado(sitio)=='READY':
        if not esquema_tipos(sitio):raise publicador.Bloqueo('falta esquema de actividades.')
        print('SIN_CAMBIOS: tipos de actividad ya publicados.')
        return
    directory,data=publicador.respaldar(sitio,root)
    print('RESPALDO_PRIVADO_OK: '+directory)
    print('ROLLBACK_BACKUP: '+directory)
    # Primero conservar y verificar código; después ampliar columnas y publicar.
    # Una reversión conserva las columnas y sus datos: el código anterior las ignora.
    try:
        esquema_tipos(sitio,True)
        if not esquema_tipos(sitio):raise publicador.Bloqueo('esquema no verificado después de ampliarlo.')
        for rel,old,new in ARCHIVOS:
            dest=publicador.confiable(sitio,rel);src=publicador.confiable(stage,rel)
            if old is None:
                if os.path.lexists(dest):raise publicador.Bloqueo('servicio nuevo inesperado: '+rel)
                mode=0o644
            else:
                if not os.path.isfile(dest) or publicador.blob(dest)!=old:
                    raise publicador.Bloqueo('base cambió antes de publicar: '+rel)
                mode=stat.S_IMODE(os.stat(dest).st_mode)
            publicador.atomico(dest,src,mode)
            if publicador.blob(dest)!=new:raise publicador.Bloqueo('integridad posterior incorrecta: '+rel)
            if rel.endswith('.php'):publicador.lint_php(dest)
            print('PUBLICADO: '+rel)
        if publicador.estado(sitio)!='READY':raise publicador.Bloqueo('comprobación posterior no concuerda.')
        data['estado']='PUBLICADO';publicador.manifest(directory,data)
        print('APPLY_OK: actividades tipificadas; ampliación aditiva verificada. Sin llamadas ni mensajes enviados.')
    except BaseException:
        publicador.restaurar(sitio,directory,data,permit_old=True)
        print('REVERSION_AUTOMATICA_OK: código anterior restaurado; columnas aditivas conservadas.')
        raise

def main():
    supervision=anterior.supervision
    supervision.ARCHIVOS=ARCHIVOS
    supervision.DEPENDENCIAS=DEPENDENCIAS
    supervision.comprobar_esquema=comprobar_esquema
    publicador.publicar=publicar_tipos
    supervision.main()

if __name__=='__main__':
    try:main()
    except (publicador.Bloqueo,OSError,ValueError) as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr)
        sys.exit(2)
