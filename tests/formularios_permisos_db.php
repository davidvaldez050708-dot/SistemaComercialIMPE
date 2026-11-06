<?php
if(PHP_SAPI!=='cli')exit(1);
$name=getenv('IMPE_DB_NAME')?:'';
if(!preg_match('/^impe_test_formularios_[a-z0-9_]+$/D',$name))throw new RuntimeException('Base exclusiva de pruebas requerida.');
require dirname(__DIR__).'/config/db_connection.php';
$db=new mysqli(getenv('IMPE_DB_HOST')?:'127.0.0.1:33339',getenv('IMPE_DB_USER')?:'root',getenv('IMPE_DB_PASSWORD')?:'');
if(($argv[1]??'')==='limpiar'){$db->query("DROP DATABASE `$name`");echo "CLEAN OK\n";exit;}
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);
$db->query('CREATE TABLE roles(id INT PRIMARY KEY,nombre VARCHAR(100),estado INT) ENGINE=InnoDB');
$db->query("INSERT INTO roles VALUES(1,'Administrador',1),(2,'Director de Operaciones',1),(3,'Asesor de Ventas',1),(4,'Analista de Datos',1),(5,'Marketing',1),(6,'Cuenta Clave',1),(7,'Compartir',1),(8,'Sin acceso',1),(9,'Convocatorias',1)");
$db->query('CREATE TABLE permisos(id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(100),codigo VARCHAR(120) UNIQUE,nombre VARCHAR(190),descripcion TEXT,estado INT) ENGINE=InnoDB');
$db->query('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id)) ENGINE=InnoDB');
$db->query('CREATE TABLE auditoria_roles_permisos(id INT AUTO_INCREMENT PRIMARY KEY,rol_id INT,actor_usuario_id INT NULL,accion VARCHAR(100),permisos_anteriores TEXT,permisos_nuevos TEXT,permisos_agregados TEXT,permisos_removidos TEXT,created_at DATETIME) ENGINE=InnoDB');
$db->query("INSERT INTO permisos VALUES(1,'Usuarios','usuarios.ver','Ver usuarios','Prueba',1)");
$db->query('INSERT INTO rol_permisos VALUES(2,1)');
require dirname(__DIR__).'/app/models/RolModel.php';
function exigir($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$m=new RolModel();$m->inicializarPermisosSistema();
function codigos($m,$id){return array_values(array_filter($m->obtenerCodigosPermisosPorRol($id),static fn($c)=>str_starts_with($c,'formularios.')));}
exigir(count(codigos($m,5))===5,'Marketing conserva inicialmente cinco permisos');
exigir(count(codigos($m,2))===0,'Director no recibe acceso por nombre de rol');
exigir(in_array('usuarios.ver',$m->obtenerCodigosPermisosPorRol(2),true),'permiso anterior de otro módulo conservado');
exigir(count($m->obtenerPermisosAgrupados()['Formularios']??[])===5,'catálogo visible en Roles y Permisos');
$ids=array_column($db->query('SELECT codigo,id FROM permisos')->fetch_all(MYSQLI_ASSOC),'id','codigo');
exigir($m->actualizarPermisosRol(5,[],1),'revocar Marketing');$m->inicializarPermisosSistema();
exigir(codigos($m,5)===[],'inicialización no restaura revocaciones');
$_SESSION=['usuario_id'=>1,'rol_id'=>5,'rol'=>'Marketing','permisos'=>[]];
exigir(!FormularioPermisos::puede(FormularioPermisos::VER),'Marketing sin permiso no tiene acceso');
exigir($m->actualizarPermisosRol(2,[$ids[FormularioPermisos::INSCRIPCION]],1),'conceder al Director');
exigir(in_array(FormularioPermisos::VER,codigos($m,2),true),'dependencia de consulta automática');
$_SESSION=['usuario_id'=>2,'rol_id'=>2,'rol'=>'Director de Operaciones','permisos'=>$m->obtenerCodigosPermisosPorRol(2)];
exigir(FormularioPermisos::puede(FormularioPermisos::INSCRIPCION),'Director autorizado puede capturar');
exigir(!FormularioPermisos::puede(FormularioPermisos::REGISTRO),'Registro es independiente');
exigir(!FormularioPermisos::puede(FormularioPermisos::COMPROBANTES),'comprobantes protegidos por separado');
exigir($m->actualizarPermisosRol(2,[],1),'revocar Director');$m->inicializarPermisosSistema();
exigir(codigos($m,2)===[],'revocación del Director persistente');
exigir((int)$db->query('SELECT COUNT(*) n FROM auditoria_roles_permisos')->fetch_assoc()['n']>=3,'auditoría de cambios conservada');
// Configuraciones para las pruebas HTTP y de vistas. No son cuentas reales.
$sets=[2=>[FormularioPermisos::VER],3=>[FormularioPermisos::REGISTRO],4=>[FormularioPermisos::INSCRIPCION],5=>array_column(FormularioPermisos::catalogo(),'codigo'),6=>[FormularioPermisos::COMPROBANTES],7=>[FormularioPermisos::COMPARTIR],8=>[],9=>['convocatorias.crear']];
foreach($sets as $rol=>$set)exigir($m->actualizarPermisosRol($rol,array_map(static fn($c)=>(int)$ids[$c],$set),1),'preparar matriz de acceso');
echo "PASS DB: catálogo, concesión a otros roles, dependencias, auditoría y revocaciones persistentes.\n";
