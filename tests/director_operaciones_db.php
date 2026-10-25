<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/models/RolModel.php';
require_once dirname(__DIR__).'/app/services/DirectorOperacionesRolService.php';
require_once dirname(__DIR__).'/app/services/AgendaReunionRepository.php';
require_once dirname(__DIR__).'/app/helpers/PermissionHelper.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');
if (!$port) throw new RuntimeException('Sólo ejecutar en la instancia aislada IMPE_TEST_MYSQL_PORT.');
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_director_test_'.getmypid();$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');
function checkDirector($ok,string $why): void {if (!$ok) throw new RuntimeException($why);}
function injected(string $class, mysqli $db) { $r=new ReflectionClass($class);$obj=$r->newInstanceWithoutConstructor();$r->getProperty('connection')->setValue($obj,$db);return $obj; }
try {
    $db->query('CREATE TABLE roles(id INT PRIMARY KEY,nombre VARCHAR(50) UNIQUE,descripcion VARCHAR(255),estado INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    $db->query("INSERT INTO roles(id,nombre,estado) VALUES (1,'Administrador',1),(2,'Coordinador Comercial',1),(3,'Asesor de Ventas',1),(4,'Analista de Datos',1),(6,'Cuenta Clave',1)");
    $db->query('CREATE TABLE permisos(id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(80),codigo VARCHAR(100) UNIQUE,nombre VARCHAR(120),descripcion VARCHAR(255),estado INT)');
    $db->query('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id))');
    $db->query('CREATE TABLE usuarios(id INT PRIMARY KEY,rol_id INT,estado INT,nombre VARCHAR(90),apellidos VARCHAR(90),correo VARCHAR(190))');
    $db->query("INSERT INTO usuarios VALUES (10,2,1,'Dirección','',''),(20,4,1,'Analista A','',''),(21,4,1,'Analista B','',''),(30,6,1,'Cuenta A','',''),(31,6,1,'Cuenta B','','')");
    $model=injected(RolModel::class,$db);$model->inicializarPermisosSistema();
    $ids=[];foreach ($db->query('SELECT id,codigo FROM permisos')->fetch_all(MYSQLI_ASSOC) as $p) $ids[$p['codigo']]=(int)$p['id'];
    checkDirector($model->actualizarPermisosRol(2,[$ids['reportes.usuarios'],$ids['territorios.ver'],$ids['telefonia.configurar']]),'Guardar permisos');
    $perms=$model->obtenerCodigosPermisosPorRol(2);
    checkDirector(in_array('reportes.usuarios',$perms,true)&&in_array('reportes.ver',$perms,true)&&!in_array('telefonia.configurar',$perms,true),'Reporte habilitable y gobierno reservado');
    $svc=new DirectorOperacionesRolService($db);$before=$db->query('SELECT * FROM rol_permisos WHERE rol_id=2 ORDER BY permiso_id')->fetch_all(MYSQLI_ASSOC);
    checkDirector($svc->comprobar()['nombre']==='Coordinador Comercial','Check sin renombrar');
    $svc->renombrar('Coordinador Comercial',$svc::NOMBRE);
    checkDirector($db->query('SELECT rol_id FROM usuarios WHERE id=10')->fetch_assoc()['rol_id']==='2','Usuario conserva rol');
    checkDirector($before===$db->query('SELECT * FROM rol_permisos WHERE rol_id=2 ORDER BY permiso_id')->fetch_all(MYSQLI_ASSOC),'Renombrar conserva permisos');
    checkDirector($svc->comprobar()['nombre']===$svc::NOMBRE,'Nombre nuevo');
    $model->actualizarPermisosRol(2,[$ids['reportes.ver']]);$model->inicializarPermisosSistema();
    checkDirector($model->obtenerCodigosPermisosPorRol(2)===['reportes.ver'],'Revocación no se reconstruye; reporte de convocatorias independiente');
    $model->actualizarPermisosRol(2,[]);$model->inicializarPermisosSistema();
    checkDirector($model->obtenerCodigosPermisosPorRol(2)===[],'Se pueden revocar todos los permisos de Dirección');
    $model->actualizarPermisosRol(2,[$ids['reportes.convocatorias']]);
    $perms=$model->obtenerCodigosPermisosPorRol(2);checkDirector(in_array('reportes.ver',$perms,true),'El hijo conserva el permiso padre requerido');
    $model->actualizarPermisosRol(3,[$ids['reportes.usuarios']]);checkDirector(!in_array('reportes.usuarios',$model->obtenerCodigosPermisosPorRol(3),true),'Otros roles no obtienen reporte reservado');
    $catalogo=$db->query('SELECT id,codigo FROM permisos WHERE estado=1')->fetch_all(MYSQLI_ASSOC);
    $probados=0;
    foreach ($catalogo as $permiso) {
        if (permisoReservadoParaRol(2,$permiso['codigo'])) continue;
        checkDirector($model->actualizarPermisosRol(2,[(int)$permiso['id']]),'Activar '.$permiso['codigo']);
        checkDirector(in_array($permiso['codigo'],$model->obtenerCodigosPermisosPorRol(2),true),'Permiso activado '.$permiso['codigo']);
        $model->actualizarPermisosRol(2,[]);
        checkDirector(!in_array($permiso['codigo'],$model->obtenerCodigosPermisosPorRol(2),true),'Permiso revocado '.$permiso['codigo']);
        ++$probados;
    }
    $svc->renombrar($svc::NOMBRE,'Coordinador Comercial');checkDirector($svc->comprobar()['nombre']==='Coordinador Comercial','Restaurar sólo nombre');
    try {$svc->renombrar('Nombre concurrente',$svc::NOMBRE);throw new LogicException('Debió rechazar nombre inesperado');}catch(RuntimeException $e) {checkDirector($svc->comprobar()['nombre']==='Coordinador Comercial','Cambio concurrente protegido');}
    // Dos equipos diferentes; Dirección no pertenece a ninguno.
    $db->query('CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(80))');$db->query("INSERT INTO estados VALUES (1,'Estado A'),(2,'Estado B')");
    $db->query('CREATE TABLE municipios(id INT PRIMARY KEY,nombre VARCHAR(80))');
    $db->query('CREATE TABLE seguimientos_vinculacion(id INT PRIMARY KEY,nombre_entidad VARCHAR(90),contacto_nombre VARCHAR(90),contacto_cargo VARCHAR(90),correo_verificado VARCHAR(190),correo_fuente VARCHAR(190),municipio_id INT,estado_id INT)');
    $db->query("INSERT INTO seguimientos_vinculacion VALUES (1,'Aliado A','','','','',NULL,1),(2,'Aliado B','','','','',NULL,2)");
    $db->query('CREATE TABLE reuniones_vinculacion(id INT PRIMARY KEY,seguimiento_id INT,analista_id INT,cuenta_clave_id INT,fecha_propuesta DATETIME,estado VARCHAR(40))');
    $db->query("INSERT INTO reuniones_vinculacion VALUES (1,1,20,30,'2026-10-25 10:00:00','SOLICITADA'),(2,2,21,31,'2026-10-25 11:00:00','CONFIRMADA')");
    $repo=injected(AgendaReunionRepository::class,$db);
    checkDirector(count($repo->reunionesMes(10,2,'2026-10-01','2026-11-01'))===2,'Dirección ve ambos equipos');
    checkDirector(count($repo->reunionesMes(20,4,'2026-10-01','2026-11-01'))===1,'Analista ve su equipo');
    checkDirector($repo->reunion(2,20,4)===null && $repo->reunion(2,10,2)!==null,'Ficha de reunión protege equipo ajeno y admite Dirección');
    echo "MySQL: renombrado reversible, $probados permisos activables/revocables y reuniones de ambos equipos OK\n";
} finally { $db->query("DROP DATABASE `$name`"); }
