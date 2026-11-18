<?php
if(PHP_SAPI!=='cli' || getenv('IMPE_WHATSAPP_CONTEXT_TEST')!=='YES')exit(2);
[$script,$site,$schema,$render]=$argv;
if(!preg_match('/^impe_whatsapp_test_[0-9a-f]+$/D',$schema))exit(2);
require $site.'/app/helpers/PermissionHelper.php';
require $site.'/app/models/RolModel.php';
$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),$schema,(int)getenv('MYSQL_PORT'));
$reflection=new ReflectionClass(RolModel::class);$model=$reflection->newInstanceWithoutConstructor();
$reflection->getProperty('connection')->setValue($model,$db);
function comprobarPermisosWa(bool $v,string $why): void {if(!$v)throw new RuntimeException($why);}
$_SESSION=['usuario_id'=>1,'rol_id'=>1,'permisos'=>['roles.ver','roles.asignar_permisos','roles.cambiar_estado']];
$model->inicializarPermisosSistema();$model->inicializarPermisosSistema();
$ids=array_column($db->query('SELECT id,codigo FROM permisos')->fetch_all(MYSQLI_ASSOC),'id','codigo');
comprobarPermisosWa(array_values(array_filter($model->obtenerCodigosPermisosPorRol(3),static fn($c)=>str_starts_with($c,'whatsapp.')))===['whatsapp.enviar','whatsapp.ver'],'asesor conserva únicamente sus accesos WhatsApp previos');
comprobarPermisosWa((int)$db->query("SELECT estado FROM permisos WHERE codigo='whatsapp.ver_todas'")->fetch_assoc()['estado']===0,'no se reactiva el duplicado al inicializar');
comprobarPermisosWa(!in_array('whatsapp.responder_todas',$model->obtenerCodigosPermisosPorRol(1),true),'Administrador no recupera permiso operativo de respuesta');
$all=$model->obtenerPermisosAgrupados();
$wa=$all['WhatsApp'];
comprobarPermisosWa(count(array_filter($wa,static fn($p)=>$p['nombre']==='Consultar todas las conversaciones'))===1,'un solo permiso visible de consulta global');
comprobarPermisosWa(!in_array('whatsapp.ver_todas',array_column($wa,'codigo'),true),'duplicado fuera del panel');
$normalizer=$reflection->getMethod('normalizarDependenciasPermisos');
$reply=$normalizer->invoke($model,[(int)$ids['whatsapp.responder_todas']]);
foreach(['whatsapp.ver','whatsapp.enviar','whatsapp.gestionar_conversaciones','whatsapp.responder_todas'] as $code)comprobarPermisosWa(in_array((int)$ids[$code],$reply,true),'dependencia de respuesta: '.$code);
// Un Administrador puede conceder un alcance mayor expresamente después de la instalación.
comprobarPermisosWa($model->actualizarPermisosRol(3,[(int)$ids['whatsapp.responder_todas']],1),'concesión explícita');
$model->inicializarPermisosSistema();
comprobarPermisosWa(in_array('whatsapp.gestionar_conversaciones',$model->obtenerCodigosPermisosPorRol(3),true),'inicializar no revoca una concesión posterior');
comprobarPermisosWa($model->actualizarPermisosRol(3,[(int)$ids['whatsapp.ver'],(int)$ids['whatsapp.enviar']],1),'revocación explícita');
$model->inicializarPermisosSistema();
comprobarPermisosWa(!in_array('whatsapp.gestionar_conversaciones',$model->obtenerCodigosPermisosPorRol(3),true),'inicializar no restaura accesos revocados');
if($render!=='-') {
    $roles=[['id'=>3,'nombre'=>'Asesor de Ventas','descripcion'=>'Prueba','estado'=>1]];
    $rolSeleccionado=$roles[0];$rolSeleccionadoId=3;
    $permisosAgrupados=$model->obtenerPermisosAgrupados();$permisosRol=$model->obtenerPermisosPorRol(3);
    ob_start();require $site.'/app/views/roles/index.php';file_put_contents($render,ob_get_clean());
}
echo "CHECK_OK catálogo: un permiso global, dependencias de respuesta, asesor propio, Admin protegido y concesiones/revocaciones posteriores persistentes.\n";
