<?php
// Crea exclusivamente un esquema nuevo local. No borra ni reutiliza bases.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!str_starts_with(getenv('IMPE_DB_HOST')?:'','127.0.0.1:'))throw new RuntimeException('Sólo DB local aislada');
$schema=getenv('IMPE_PROSPECTOS_TEST_DB')?:'impe_test_prospectos_permisos';
if(!preg_match('/^impe_test_prospectos_permisos[a-z0-9_]*$/D',$schema))throw new RuntimeException('Esquema inválido');
$port=(int)substr(getenv('IMPE_DB_HOST'),strlen('127.0.0.1:'));
$db=new mysqli('127.0.0.1','root','', '',$port);
$db->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");$db->select_db($schema);putenv('IMPE_DB_NAME='.$schema);
require_once __DIR__.'/../app/models/RolModel.php';
require_once __DIR__.'/../app/helpers/PermissionHelper.php';
$db->query('CREATE TABLE roles (id INT PRIMARY KEY,nombre VARCHAR(80),estado INT DEFAULT 1) ENGINE=InnoDB');
$db->query("INSERT INTO roles VALUES (1,'Administrador',1),(5,'Marketing',1),(6,'Cuenta Clave',1),(7,'Director de Operaciones',1),(8,'Asesor de Ventas',1)");
$db->query('CREATE TABLE permisos (id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(100),codigo VARCHAR(100) UNIQUE,nombre VARCHAR(180),descripcion VARCHAR(255),estado INT) ENGINE=InnoDB');
$db->query('CREATE TABLE rol_permisos (rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id)) ENGINE=InnoDB');
$m=new RolModel();$catalogo=(new ReflectionMethod(RolModel::class,'obtenerCatalogoInicialPermisos'))->invoke($m);
$stmt=$db->prepare('INSERT INTO permisos (modulo,codigo,nombre,descripcion,estado) VALUES (?,?,?,?,1)');
foreach($catalogo as $p){$stmt->bind_param('ssss',$p['modulo'],$p['codigo'],$p['nombre'],$p['descripcion']);$stmt->execute();}
$db->query("UPDATE permisos SET estado=0 WHERE codigo='prospectos.ver_todos'");
$db->query("INSERT INTO rol_permisos SELECT 1,id FROM permisos WHERE codigo='roles.ver'");
$count=0;
function assertProspectosDb($ok,$msg){global $count;if(!$ok)throw new RuntimeException($msg);$count++;}
$m->inicializarPermisosSistema();
$grupo=$m->obtenerPermisosAgrupados()['Prospectos']??[];
assertProspectosDb(array_slice(array_column($grupo,'codigo'),0,2)===['prospectos.ver_todos','prospectos.importar_meta'],'Ver e importar son permisos independientes en el catálogo');
assertProspectosDb(str_contains($grupo[0]['descripcion'],'formularios de Registro'),'Descripción del listado real');
assertProspectosDb(str_contains($grupo[1]['descripcion'],'CSV o Excel'),'Descripción de importación en el panel');
assertProspectosDb(!in_array('prospectos.importar_meta',$m->obtenerCodigosPermisosPorRol(1),true),'Administrador no opera importaciones');
assertProspectosDb($m->obtenerCodigosPermisosPorRol(5)===[],'Sin otorgar acceso automático a Marketing');
assertProspectosDb($m->obtenerCodigosPermisosPorRol(6)===[],'Sin otorgar acceso automático a Cuenta Clave');
$id=(int)$grupo[0]['id'];
$importId=(int)$grupo[1]['id'];
foreach([5,6,7,8] as $rolId){
    assertProspectosDb($m->actualizarPermisosRol($rolId,[$id],1),'Admin concede permiso');
    $m->inicializarPermisosSistema();
    $_SESSION=['usuario_id'=>2,'rol_id'=>$rolId,'permisos'=>$m->obtenerCodigosPermisosPorRol($rolId)];
    assertProspectosDb(tienePermiso('prospectos.ver_todos'),'Asignación efectiva tras recarga de catálogo');
    assertProspectosDb(count($_SESSION['permisos'])===1,'No concede permisos ajenos');
    assertProspectosDb($m->actualizarPermisosRol($rolId,[],1),'Admin revoca permiso');
    $m->inicializarPermisosSistema();
    $_SESSION['permisos']=$m->obtenerCodigosPermisosPorRol($rolId);
    assertProspectosDb(!tienePermiso('prospectos.ver_todos'),'Revocación persiste tras reinicialización');
    assertProspectosDb($m->actualizarPermisosRol($rolId,[$importId],1),'Se puede conceder Importar desde Roles y permisos');
    $asignados=$m->obtenerCodigosPermisosPorRol($rolId);
    assertProspectosDb(in_array('prospectos.importar_meta',$asignados,true),'El rol recibe Importar Meta');
    assertProspectosDb(in_array('prospectos.ver_todos',$asignados,true),'Importar agrega la dependencia de consulta');
    $m->inicializarPermisosSistema();
    assertProspectosDb(in_array('prospectos.importar_meta',$m->obtenerCodigosPermisosPorRol($rolId),true),'El permiso persiste en el catálogo');
    assertProspectosDb($m->actualizarPermisosRol($rolId,[$id],1),'El permiso de importación se puede revocar');
    $m->inicializarPermisosSistema();
    assertProspectosDb(!in_array('prospectos.importar_meta',$m->obtenerCodigosPermisosPorRol($rolId),true),'La revocación no se restaura');
}
// Simula instalación anterior sin el permiso nuevo: concesión inicial única
// para Marketing que ya tenía lectura; nunca para Dirección.
assertProspectosDb($m->actualizarPermisosRol(5,[$id],1),'Marketing ya tenía consulta antes de instalar el permiso nuevo');
$db->query("DELETE FROM permisos WHERE codigo='prospectos.importar_meta'");
$m->inicializarPermisosSistema();
assertProspectosDb(in_array('prospectos.importar_meta',$m->obtenerCodigosPermisosPorRol(5),true),'Marketing conserva la operación previa una sola vez');
assertProspectosDb(!in_array('prospectos.importar_meta',$m->obtenerCodigosPermisosPorRol(7),true),'Dirección debe obtener permiso explícito');
assertProspectosDb($m->actualizarPermisosRol(5,[$id],1),'Marketing puede revocar la importación');
$m->inicializarPermisosSistema();
assertProspectosDb(!in_array('prospectos.importar_meta',$m->obtenerCodigosPermisosPorRol(5),true),'La revocación de Marketing no se revierte');

// También se usa esta suite para la réplica del paquete histórico sin configuración.
$config=array_values(array_filter($grupo,static fn($p)=>$p['codigo']==='prospectos.configurar_formularios'));
if ($config) {
    assertProspectosDb(in_array('prospectos.configurar_formularios',$m->obtenerCodigosPermisosPorRol(1),true),'Administrador configura formularios sin importar');
    foreach ([5,6,7,8] as $role) {
        $m->actualizarPermisosRol($role,[(int)$config[0]['id']],1);$codes=$m->obtenerCodigosPermisosPorRol($role);
        assertProspectosDb(in_array('prospectos.configurar_formularios',$codes,true),'Configurar se concede por permiso a cualquier perfil');
        assertProspectosDb(in_array('prospectos.ver_todos',$codes,true)&&!in_array('prospectos.importar_meta',$codes,true),'Configurar requiere consulta y no concede importar');
        $m->actualizarPermisosRol($role,[],1);$m->inicializarPermisosSistema();
        assertProspectosDb(!in_array('prospectos.configurar_formularios',$m->obtenerCodigosPermisosPorRol($role),true),'Revocación de configuración persiste');
    }
}
echo "PASS $count comprobaciones DB: catálogo Admin, concesión y revocación persistentes, cuatro perfiles. Esquema $schema.\n";
