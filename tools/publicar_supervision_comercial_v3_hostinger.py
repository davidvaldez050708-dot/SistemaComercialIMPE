#!/usr/bin/env python3
"""Supervisión comercial V3: vistas compartidas, expediente y consulta sin operaciones."""
import sys
import publicar_seguimiento_acciones_v3_hostinger as entrega

ARCHIVOS = [('app/services/EmbudoComercialRepository.php', 'f3761b4a749308f3c538f09294d96e7c16101a7f', '591023ccd75aef4be31b334cf34fa75be83ce205'), ('app/views/embudo_comercial/operativo_v3.php', '94ffd131aad08d1125bddc86ebe6c46eedc12556', 'ed45e1374f3f62961850fdcd0cd37ffd7d8b4f0a'), ('app/views/embudo_comercial/bandejas_v3.php', '6dcdc616a46f9d4093e3a46303190e8afb81ec27', '665c5b511bdb01deb982a72b9c1e3ba001e4fb2b'), ('app/views/embudo_comercial/detalle.php', 'd2b23a3ae7423df81e7fa07023aae1a405f3c6b6', 'd49d30db98ea861834e9c214246ac0fc1ea78b2c'), ('app/views/embudo_comercial/expedientes.php', '1c36aadf75599f77d22ccb3b17212c68bd9fc180', 'f05b8af71461037ef76b76e4e75eda9e88780537'), ('app/controllers/EmbudoComercialController.php', 'e518765e4d02b588b886bb40e08410618f5ce3c9', '20299073d4d263c3f37a641f6830e98efd497e97')]
DEPENDENCIAS = entrega.DEPENDENCIAS.copy()
for relativo,_,_ in ARCHIVOS:DEPENDENCIAS.pop(relativo,None)
DEPENDENCIAS.update({'app/helpers/EmbudoComercialAcceso.php': '43f1d22e16ba0b98d27c5fbcb767e8570765df33', 'app/services/EmbudoComercialV3ProximaAccion.php': 'f87c3d688cdafa3118158af88453baa02dd178a4', 'app/services/EmbudoComercialPoliticaPropuesta.php': '7db5633df7417252a1d84b5298b44bf6612c2a1d', 'app/services/EmbudoComercialV3SeguimientoService.php': 'cc1cd1d61d9f59fbe4d56d68a7dfffb2be27e619', 'app/services/EmbudoComercialV3GestionPotencialService.php': '86519bcf2d6aea461a619035d7d6acbea814b27c', 'app/services/EmbudoComercialV3InscripcionService.php': '78117b36187694a383a638aecf4de1bae64606b4', 'app/services/EmbudoComercialResultadoBridge.php': 'a9666352990f2a5965deaaf98264f6a22e4be40d', 'public/javascript/embudo_comercial_v3_operativo.js': '28e3b4b26d5dd5c94dc1b877bc1a34ad45fb663f', 'public/javascript/embudo_comercial_v3_filtros.js': '3a46fdafc6acef9aa5030040e132439f54f73c13', 'public/javascript/embudo_comercial_v3_inscripcion.js': '6da0995c4ccc3d9ca912895095e32c7d7450d3d5', 'public/javascript/embudo_comercial_v3_bandejas.js': 'b382ae0dfacf39f976c00dcbd9a002acedb671fd', 'public/css/embudo_v3_operativo.css': '47245d519bad1252a42af5cec0395c598aca5388', 'public/css/embudo_comercial_compacto_v1.css': 'c0ea09e7b35ee8c54d61f9386902bb8bcbac2ee4', 'public/css/embudo_v3_bandejas.css': 'b7aa3c9acccefcc70f67acd7a43c53a1b0b6bc6e'})

publicador = entrega.publicador
TELEFONIA_REL = entrega.TELEFONIA_REL
TELEFONIA_HOSTINGER = entrega.TELEFONIA_HOSTINGER
comprobar_base = entrega.comprobar_esquema

def comprobar_esquema(sitio):
    import subprocess
    comprobar_base(sitio)
    codigo=r"""
require $argv[1].'/config/db_connection.php';
$db=(new Database())->connect(false);
$tablas=[
 'usuarios'=>['id','nombre','apellidos'],
 'prospecto_asignaciones'=>['id','prospecto_id','asesor_id','vigente','finalizado_at'],
 'prospectos_comerciales'=>['id','nombre_completo','telefono_original','correo','pais_nombre','estado_provincia','ciudad'],
 'prospecto_embudo_inscripciones_v3'=>['asignacion_id','formulario_inscripcion_id','verificado_at','vinculado_por'],
 'formulario_inscripciones'=>['id','created_at'],
 'prospecto_embudo_llamadas'=>['id','asignacion_id','usuario_id','extension','pbx_call_id','resultado','creado_at'],
 'telefonia_ventas_resultados'=>['usuario_id','extension','pbx_call_id','observacion']
];
foreach($tablas as $tabla=>$campos){
 $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $s->bind_param('s',$tabla);$s->execute();$fila=$s->get_result()->fetch_assoc();$s->close();
 if(!$fila)exit(2);
 $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $s->bind_param('s',$tabla);$s->execute();$columnas=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');$s->close();
 if(array_diff($campos,$columnas))exit(3);
}
$db->close();echo 'ESQUEMA_SUPERVISION_OK';
"""
    try:
        salida=subprocess.check_output(['php','-r',codigo,'--',sitio],stderr=subprocess.STDOUT).decode().strip()
    except (OSError,subprocess.CalledProcessError):
        raise publicador.Bloqueo('esquema de consulta/expedientes incompatible; sin cambios.')
    if salida!='ESQUEMA_SUPERVISION_OK':
        raise publicador.Bloqueo('respuesta inesperada en metadatos de supervisión; sin cambios.')
    print(salida)

def configurar_dependencias(sitio):
    entrega.DEPENDENCIAS=DEPENDENCIAS
    entrega.configurar_dependencias(sitio)

def main():
    entrega.ARCHIVOS=ARCHIVOS
    entrega.DEPENDENCIAS=DEPENDENCIAS
    entrega.comprobar_esquema=comprobar_esquema
    entrega.main()

if __name__=='__main__':
    try:
        main()
    except (publicador.Bloqueo,OSError,ValueError) as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr)
        sys.exit(2)
