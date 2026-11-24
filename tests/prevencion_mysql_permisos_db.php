<?php
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../app/helpers/CatalogoPermisosInicializador.php';
// Ejecutar el lector real del candidato de producción sin cargar el arranque privado.
$path=__DIR__.'/../tmp/prevencion-actual-stage/app/models/RolModel.php';
$source=file_get_contents(is_file($path)?$path:__DIR__.'/../app/models/RolModel.php');
$inicio=strpos($source,'    public function obtenerCodigosPermisosPorRol(');
$fin=strpos($source,'    public function actualizarPermisosRol(',$inicio);
if($inicio===false||$fin===false)throw new RuntimeException('No se encontró el lector de permisos.');
eval('class LectorPermisosPrueba {private $connection;public function __construct($db){$this->connection=$db;}'.substr($source,$inicio,$fin-$inicio).'}');
function verificarPermiso($ok,$m){if(!$ok)throw new RuntimeException($m);}
$db=new mysqli('127.0.0.1','root','impe-local-test-only',null,33472);
$db->query('CREATE DATABASE IF NOT EXISTS impe_permisos_prevencion_test CHARACTER SET utf8mb4');
$db->select_db('impe_permisos_prevencion_test');
$dir=sys_get_temp_dir().'/impe-permisos-test-'.bin2hex(random_bytes(8));
try{
 $db->query('CREATE TABLE permisos (id INT PRIMARY KEY,codigo VARCHAR(160),estado INT) ENGINE=InnoDB');
 $db->query('CREATE TABLE rol_permisos (rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id)) ENGINE=InnoDB');
 $db->query("INSERT INTO permisos VALUES (1,'telefonia.usar',1),(2,'telefonia.transferir',1)");
 $db->query('INSERT INTO rol_permisos VALUES (4,1),(4,2)');
 $n=0;$instalar=static function()use(&$n){$n++;};$leer=new LectorPermisosPrueba($db);
 ImpeCatalogoPermisosInicializador::ejecutar($instalar,[$path],$dir);
 verificarPermiso(in_array('telefonia.transferir',$leer->obtenerCodigosPermisosPorRol(4),true),'Permiso concedido disponible');
 $db->query('DELETE FROM rol_permisos WHERE rol_id=4 AND permiso_id=2');
 ImpeCatalogoPermisosInicializador::ejecutar($instalar,[$path],$dir);
 verificarPermiso(!in_array('telefonia.transferir',$leer->obtenerCodigosPermisosPorRol(4),true),'Revocacion efectiva aunque el catalogo este vigente');
 $db->query("UPDATE permisos SET estado=0 WHERE codigo='telefonia.usar'");
 verificarPermiso($leer->obtenerCodigosPermisosPorRol(4)===[],'Permiso desactivado se retira inmediatamente');
 $db->query("UPDATE permisos SET estado=1 WHERE codigo='telefonia.usar'");
 verificarPermiso($leer->obtenerCodigosPermisosPorRol(4)===['telefonia.usar'],'Reactivacion inmediata sin reinicializar');
 verificarPermiso($n===1,'El catalogo solo se inicializa una vez');
 echo "OK: revocacion y desactivacion inmediatas; catalogo inicializado una vez; autorizacion sin cache.\n";
}finally{
 $db->query('DROP DATABASE impe_permisos_prevencion_test');$db->close();
 foreach(glob($dir.'/*')?:[] as $f)unlink($f);if(is_dir($dir))rmdir($dir);
}
