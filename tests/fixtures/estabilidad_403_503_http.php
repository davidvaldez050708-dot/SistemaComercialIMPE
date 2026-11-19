<?php
// Dobles de prueba para php -n -S. Nunca se incluye en el CRM.
define('DB_HOST', 'localhost'); define('DB_USER','TEST'); define('DB_PASSWORD','TEST'); define('DB_NAME','TEST');
define('ROOT_PATH', dirname(__DIR__)); define('BASE_URL', '/');
$GLOBALS['fixture_counts']=['connections'=>0,'catalog'=>0,'permissions'=>0,'extensions'=>0,'mutations'=>0,'hosts'=>[]];
register_shutdown_function(static function(){file_put_contents(ROOT_PATH.'/metrics.json',json_encode($GLOBALS['fixture_counts']));});
class mysqli_sql_exception extends RuntimeException { public function getSqlState(){return 'HY000';} }
class mysqli {
    public function options($key,$value){return true;}
    public function real_connect($host,...$args){
        $GLOBALS['fixture_counts']['connections']++;$GLOBALS['fixture_counts']['hosts'][]=$host;
        $fault=(int)($_GET['fault']??0);
        if($fault)throw new mysqli_sql_exception('PRIVATE_SENTINEL Operation not permitted', $fault);
        return true;
    }
    public function set_charset($charset){return true;}
    public function close(){}
}
function mysqli_init(){return new mysqli();}
function mysqli_connect_errno(){return 0;}
define('MYSQLI_OPT_CONNECT_TIMEOUT',0);
require_once __DIR__.'/db_connection.php';
class UsuarioModel {
    public function __construct(?mysqli $db=null){}
    public function buscarPorId($id){return ['estado'=>($_GET['inactive']??'')==='1'?0:1,'rol_estado'=>1,'rol_id'=>3,'rol'=>'Ventas'];}
}
class RolModel {
    private $db;
    public function __construct(?mysqli $db=null){$this->db=$db??(new Database())->connect();}
    public function inicializarPermisosSistema(){$GLOBALS['fixture_counts']['catalog']++;}
    public function obtenerCodigosPermisosPorRol($rol){
        $GLOBALS['fixture_counts']['permissions']++;
        return ($_GET['revoked']??'')==='1'?[]:['telefonia.usar','telefonia.recibir'];
    }
}
class TelefoniaExtensionService {
    private $db;
    public function __construct(){$GLOBALS['fixture_counts']['extensions']++;$this->db=(new Database())->connect();}
}
class TelefoniaDisponibilidadService {
    private $db;
    public function __construct(?mysqli $db=null){$this->db=$db??(new Database())->connect();}
    public function sondear(...$args){
        if(isset($_GET['query_fault']))throw new mysqli_sql_exception('PRIVATE_QUERY', (int)$_GET['query_fault']);
        return ['solicitud'=>null,'entrante'=>['fixture_user'=>(int)$args[0]],'devoluciones'=>0];
    }
    public function presencia(...$args){if(isset($_GET['query_fault']))throw new mysqli_sql_exception('PRIVATE_QUERY',(int)$_GET['query_fault']);}
    public function obtener(...$args){return null;}
    public function entrante($user){return ['fixture_user'=>$user];}
    public function devoluciones(...$args){return [];}
    public function accion(...$args){$GLOBALS['fixture_counts']['mutations']++;return ['estado'=>'ACEPTADA'];}
}
function tienePermiso($code){return isset($_SESSION['usuario_id'])&&in_array($code,$_SESSION['permisos']??[],true);}
