<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_v4_v5_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../config/db_connection.php';require_once __DIR__.'/../app/services/AliadoRegistroService.php';
$db=(new Database())->connect(false);$service=new AliadoRegistroService($db);$count=0;
$check=static function($ok,$message)use(&$count){if(!$ok)throw new RuntimeException($message);$count++;echo "OK $message\n";};
$deny=static function(callable $fn,int $code,string $message)use($check){try{$fn();}catch(DomainException $e){$check($e->getCode()===$code,$message);return;}throw new RuntimeException('Aceptado: '.$message);};
$permissions=['convenios.ver','convocatorias.ver','convocatorias.crear','formularios_aliados.gestionar_enlaces'];
$session=static function(int $role,string $name,array $permissions){$_SESSION=['usuario_id'=>$role===5?4:2,'rol_id'=>$role,'rol'=>$name,'permisos'=>$permissions];};
$snapshot=static fn()=>$db->query('SELECT r.*,a.token FROM formulario_aliado_registro r JOIN formulario_aliados a ON a.id=r.aliado_id ORDER BY r.aliado_id')->fetch_all(MYSQLI_ASSOC);
$originals=$snapshot();$relations=$db->query('SELECT * FROM formulario_aliado_programas ORDER BY aliado_id,programa_codigo')->fetch_all(MYSQLI_ASSOC);
$assignment=$db->query('SELECT * FROM asignaciones_territorio WHERE id=1')->fetch_assoc();
try{
    $session(5,'Marketing',$permissions);$marketing=$service->enlace('institucional',10,'generar');$url=$marketing['url'];$check($url!=='','Marketing recupera el enlace publicado');
    $session(6,'Cuenta Clave',$permissions);$cc=$service->enlace('institucional',10,'generar');$check($cc['url']===$url&&$cc['habilitado']===$marketing['habilitado'],'Cuenta Clave recupera enlace de Marketing sin cambiar token o disponibilidad');
    $check($service->enlace('institucional',10,'generar')['url']===$url,'generar repetidamente conserva la URL');
    $manual=(int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='V4 Otro'")->fetch_row()[0];
    $check($service->enlace('manual',$manual,'generar')['url']!=='','Cuenta Clave genera enlace manual dentro de su territorio');
    $outside=(int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='V4 Manual'")->fetch_row()[0];
    $before=$snapshot();$deny(fn()=>$service->enlace('manual',$outside,'generar'),403,'permiso no permite otro territorio');$check($snapshot()===$before,'rechazo territorial no modifica perfiles');
    $db->query("UPDATE asignaciones_territorio SET activo=0 WHERE id=1");$deny(fn()=>$service->enlace('institucional',10,'generar'),403,'asignación inactiva rechazada');
    $db->query("UPDATE asignaciones_territorio SET activo=1,fecha_fin=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=1");$deny(fn()=>$service->enlace('institucional',10,'generar'),403,'asignación vencida rechazada');
    $db->query("UPDATE asignaciones_territorio SET fecha_fin=NULL,fecha_inicio=DATE_ADD(CURDATE(),INTERVAL 1 DAY) WHERE id=1");$deny(fn()=>$service->enlace('institucional',10,'generar'),403,'asignación futura rechazada');
    $db->query("UPDATE asignaciones_territorio SET fecha_inicio=NULL WHERE id=1");
    $session(6,'Cuenta Clave',array_diff($permissions,['formularios_aliados.gestionar_enlaces']));$before=$snapshot();$deny(fn()=>$service->enlace('institucional',10,'generar'),403,'sin permiso de enlaces se deniega');$check($snapshot()===$before,'rechazo de permiso no modifica perfiles');
    foreach([[4,'Analista de Datos'],[3,'Asesor'],[1,'Administrador']] as [$role,$name]){$session($role,$name,$permissions);$deny(fn()=>$service->enlace('institucional',10,'generar'),403,"$name no hereda la operación");}
    $session(6,'Cuenta Clave',$permissions);$disabled=$service->enlace('institucional',10,'deshabilitar');$enabled=$service->enlace('institucional',10,'habilitar');$check(!$disabled['habilitado']&&$enabled['habilitado']&&$enabled['url']===$url,'permiso permite gestionar disponibilidad conservando URL');
    $perfil=(int)$db->query('SELECT aliado_id FROM formulario_aliado_registro WHERE seguimiento_id=10')->fetch_row()[0];$db->query("UPDATE formulario_aliado_registro SET publicado=0,habilitado=0 WHERE aliado_id=$perfil");
    $fresh=$service->enlace('institucional',10,'generar');$check($fresh['publicado']&&!$fresh['habilitado']&&$fresh['url']===$url,'primera publicación del enlace respeta token y requiere habilitación explícita');
    $before=$snapshot();$deny(fn()=>$service->enlace('institucional',10,'rotar'),0,'acción desconocida rechazada');$check($snapshot()===$before,'acción inválida revierte transacción');
    $session(5,'Marketing',$permissions);$check($service->enlace('manual',$outside,'generar')['url']!=='','Marketing conserva alcance nacional');
    $check($db->query('SELECT * FROM formulario_aliado_programas ORDER BY aliado_id,programa_codigo')->fetch_all(MYSQLI_ASSOC)===$relations,'generar enlaces conserva programas autorizados');
    $check(array_column($snapshot(),'token','aliado_id')===array_column($originals,'token','aliado_id'),'ninguna operación rota tokens existentes');
}finally{
    $stmt=$db->prepare('UPDATE formulario_aliado_registro SET publicado=?,habilitado=?,actualizado_por=?,updated_at=? WHERE aliado_id=?');
    foreach($originals as $r){$stmt->bind_param('iiisi',$r['publicado'],$r['habilitado'],$r['actualizado_por'],$r['updated_at'],$r['aliado_id']);$stmt->execute();}
    $stmt=$db->prepare('UPDATE asignaciones_territorio SET activo=?,fecha_inicio=?,fecha_fin=? WHERE id=1');$stmt->bind_param('iss',$assignment['activo'],$assignment['fecha_inicio'],$assignment['fecha_fin']);$stmt->execute();
}
echo "PASS $count comprobaciones de enlaces · ".$db->server_info."\n";
