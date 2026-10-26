<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/helpers/PermissionHelper.php';
require_once dirname(__DIR__).'/app/controllers/TerritorioController.php';
require_once dirname(__DIR__).'/app/controllers/SeguimientoVinculacionController.php';
require_once dirname(__DIR__).'/app/controllers/SeguimientoVinculacionReporteController.php';
require_once dirname(__DIR__).'/app/controllers/SeguimientoReporteAnaliticaController.php';
require_once dirname(__DIR__).'/app/controllers/SeguimientoFlujoController.php';
require_once dirname(__DIR__).'/app/controllers/OficioCorreoController.php';
require_once dirname(__DIR__).'/app/controllers/OficioVinculacionController.php';
require_once dirname(__DIR__).'/app/controllers/ReporteAdministradorController.php';
function dcheck($ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function invokeScope(string $class, string $method, array $args = []) {
    $r = new ReflectionClass($class);
    return $r->getMethod($method)->invokeArgs($r->newInstanceWithoutConstructor(), $args);
}
class EstadosDireccionFixture extends TerritorioModel {
    public function __construct() {}
    public function obtenerEstados($filtros = []) { return array_map(static fn($id) => ['id'=>$id], range(1,32)); }
    public function obtenerEstadosAsignadosUsuario($uid, $tipo) { return [['id'=>$tipo==='CUENTA_CLAVE'?2:1]]; }
}
$_SESSION = ['usuario_id'=>50,'rol_id'=>2,'rol'=>'Director de Operaciones','permisos'=>[]];
dcheck(!tienePermiso('territorios.ver') && !tienePermiso('reportes.usuarios'), 'El rol no concede permisos por sí solo');
$_SESSION['permisos']=['territorios.ver','reportes.ver','reportes.usuarios','reportes.exportar'];
dcheck(tienePermiso('territorios.ver') && !tienePermiso('territorios.asignar'), 'Consulta sin asignación');
dcheck(count(invokeScope(TerritorioController::class,'obtenerEstadosPermitidos',[new EstadosDireccionFixture(),[]]))===32,'Dirección consulta 32 estados sin asignaciones');
invokeScope(ReporteAdministradorController::class,'validarAdministrador');
foreach ([SeguimientoVinculacionController::class=>'resolverModoSeguimiento',SeguimientoVinculacionReporteController::class=>'resolverModoSeguimiento',SeguimientoReporteAnaliticaController::class=>'resolverModoAcceso',SeguimientoFlujoController::class=>'resolverModoAcceso',OficioCorreoController::class=>'resolverModoAcceso',OficioVinculacionController::class=>'resolverModoAcceso'] as $class=>$method) {
    dcheck(invokeScope($class,$method)==='administrador', "$class permite lectura nacional");
}
foreach ([3,4,6] as $rol) {
    $_SESSION['rol_id']=$rol;
    dcheck(count(invokeScope(TerritorioController::class,'obtenerEstadosPermitidos',[new EstadosDireccionFixture(),[]]))===1,'Roles territoriales conservan sus asignaciones');
    dcheck(invokeScope(SeguimientoVinculacionController::class,'resolverModoSeguimiento')==='analista','No convertir otros roles en Dirección');
    dcheck(permisoReservadoParaRol($rol,'reportes.usuarios'),'Reporte de usuarios reservado a supervisión nacional');
}
$_SESSION['rol_id']=2;
dcheck(!permisoReservadoParaRol(2,'reportes.usuarios') && permisoReservadoParaRol(2,'telefonia.configurar'),'Dirección no hereda gobierno de telefonía');
dcheck(puedeConsultarAliadosNacional(),'Dirección con alcance nacional en Aliados');
$_SESSION['permisos']=[];
dcheck(!tienePermiso('territorios.ver') && !tienePermiso('reportes.usuarios'),'Revocar permisos corta autorización');
// Render real: reporte de usuarios editable en Dirección, credenciales bloqueadas.
$_SESSION['permisos']=['roles.asignar_permisos'];
$rolSeleccionado=['id'=>2,'nombre'=>'Director de Operaciones','estado'=>1];
$permisosAgrupados=['Reportes'=>[['id'=>1,'codigo'=>'reportes.usuarios','nombre'=>'Reporte de usuarios','descripcion'=>'']], 'Telefonía'=>[['id'=>2,'codigo'=>'telefonia.configurar','nombre'=>'Configuración','descripcion'=>'']]];
$permisosRol=[];ob_start();require dirname(__DIR__).'/app/views/roles/panel_permisos.php';$html=ob_get_clean();
preg_match('/<input[^>]*id="permiso_2_1"[^>]*>/s',$html,$users);preg_match('/<input[^>]*id="permiso_2_2"[^>]*>/s',$html,$phone);
dcheck(isset($users[0]) && !str_contains($users[0],'disabled'),'Checkbox de reporte habilitado');
dcheck(isset($phone[0]) && str_contains($phone[0],'disabled'),'Checkbox de gobierno bloqueado');
$_SESSION['permisos']=['reportes.ver'];
$tableroDireccion=['permisos'=>['reportes'=>false],'resumen'=>[]];
ob_start();require dirname(__DIR__).'/app/views/dashboard/director_operaciones.php';$dashboard=ob_get_clean();
dcheck(!str_contains($dashboard,'controller=reporte'),'No mostrar un acceso a reportes sin familias disponibles');
$_SESSION['permisos']=['usuarios.ver','territorios.ver','reportes.ver','reportes.usuarios'];
$tableroDireccion=['permisos'=>['reportes'=>true,'equipo'=>true,'territorios'=>true],'resumen'=>['equipo'=>[3=>1,4=>1,6=>1],'territorios'=>32],'cobertura'=>[]];
ob_start();require dirname(__DIR__).'/app/views/dashboard/director_operaciones.php';$dashboard=ob_get_clean();
dcheck(str_contains($dashboard,'controller=reporte') && str_contains($dashboard,'controller=usuario'),'Inicio refleja permisos habilitados');
echo "Director: alcance nacional, roles territoriales, reporte y controles de permisos OK\n";
