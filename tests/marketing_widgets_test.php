<?php
// SQL real en SQLite en memoria; sin conexión al CRM/Hostinger.
putenv('IMPE_BASE_URL=https://marketing-widgets.test/');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../app/models/MarketingWidgetsModel.php';
require_once __DIR__.'/../app/models/ConvocatoriaModel.php';
function mwCheck($ok,$why) { if (!$ok) { throw new RuntimeException($why); } }
class MwRows {
    private $rows; public function __construct($rows) { $this->rows=$rows; }
    public function fetch_assoc() { return array_shift($this->rows); }
}
class MwDb {
    public $pdo; public $queries=[];
    public function __construct() { $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); }
    public function query($sql) {
        mwCheck(str_starts_with(trim($sql),'SELECT'),'Sólo SELECT');$this->queries[]=$sql;
        $sql=str_replace('CURDATE()',"'2026-10-23'",$sql);
        $sql=str_replace(["DATE_FORMAT('2026-10-23', '%Y-%m-01')","DATE_FORMAT('2026-10-23', '%Y-%m')"],["'2026-10-01'","'2026-10'"],$sql);
        $sql=preg_replace("/DATE_ADD\('2026-10-01', INTERVAL 1 MONTH\)/","'2026-11-01'",$sql);
        $sql=preg_replace("/DATE_ADD\('2026-10-23', INTERVAL 7 DAY\)/","'2026-10-30'",$sql);
        return new MwRows($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
}
$db=new MwDb();$p=$db->pdo;
$p->exec("CREATE TABLE convocatorias(id INTEGER PRIMARY KEY,titulo TEXT,estado INTEGER,fecha_inicio TEXT,fecha_termino TEXT,created_at TEXT,updated_at TEXT,tipo_convocatoria TEXT,subtipo_convocatoria TEXT);
CREATE TABLE estados(id INTEGER PRIMARY KEY,nombre TEXT,estado INTEGER);
CREATE TABLE convocatoria_estados(convocatoria_id INTEGER,estado_id INTEGER);
INSERT INTO estados VALUES(1,'Aguascalientes',1),(11,'Guanajuato',1),(14,'Jalisco',1),(19,'Nuevo León',1),(25,'Sinaloa',1),(9,'Ciudad de México',0);
INSERT INTO convocatorias VALUES
(1,'Registro del primer día',1,'2026-10-01','2026-10-23','2026-10-01 00:00:00',NULL,'bachillerato','bachillerato-286'),
(2,'Programa de profesionalización',1,'2026-10-01','2026-10-30','2026-10-23 10:21:00',NULL,'titulacion','experiencia-laboral'),
(3,'Beca para formación profesional',1,'2026-10-01','2026-10-31','2026-10-23 10:22:00',NULL,'titulacion','experiencia-laboral'),
(4,'Convocatoria inactiva',0,'2026-10-01','2026-10-31','2026-10-22 16:45:00',NULL,'bachillerato','bachillerato-286'),
(5,'Registro de septiembre',1,'2026-10-01','2026-10-26','2026-09-30 23:59:59',NULL,'titulacion',''),
(6,'Convocatoria finalizada',1,'2026-10-01','2026-10-22','2026-10-23 10:21:00',NULL,'titulacion',''),
(7,'Próxima apertura de inscripciones',0,'2026-10-24','2026-11-05','2026-10-23 10:21:00',NULL,'bachillerato',''),
(8,'Registro de noviembre',1,'2026-11-01','2026-11-30','2026-11-01 00:00:00',NULL,'titulacion',''),
(9,'Sin marca de publicación',0,'2026-01-01','2026-01-31',NULL,NULL,'titulacion',''),
(10,'Inicio futuro con bandera activa',1,'2026-10-24','2026-10-29','2026-09-29 00:00:00',NULL,'titulacion','');
INSERT INTO convocatoria_estados VALUES(1,11),(1,11),(1,25),(1,1),(2,11),(2,14),(3,14),(3,19),(5,25),(5,19),(6,11),(7,11),(8,19),(4,14),(1,9),(10,25);");
// Un registro con fecha futura sólo prueba el límite del mes, no participa en la captura de recientes.
$model=new MarketingWidgetsModel($db);$datos=$model->obtener();
mwCheck($datos['resumen']===['periodo'=>'2026-10','hoy'=>'2026-10-23','total'=>6,'publicadas'=>3,'inactivas'=>3,'proximas'=>3],'Mes calendario, vigencia y próximos 7 días globales');
mwCheck(array_column($datos['territorios'],'id')===[11,14,19,25],'Top cuatro, desempate estable y estado de catálogo activo');
mwCheck(array_column($datos['territorios'],'convocatorias_activas')===[2,2,2,2],'DISTINCT multiestado, sin vencidas/inactivas/futuras');
mwCheck(count($db->queries)===2,'Sólo dos lecturas nuevas');
$reflection=new ReflectionClass(ConvocatoriaModel::class);$conv=$reflection->newInstanceWithoutConstructor();$reflection->getProperty('connection')->setValue($conv,$db);
$p->exec('DELETE FROM convocatorias WHERE id=8'); // Retirar únicamente la fila de límite futuro del fixture en memoria.
$recientes=$conv->obtenerRecientesDashboard(4);
mwCheck(array_column($recientes,'id')===[3,7,6,2],'Consulta compartida de recientes: timestamp y desempate por ID');
mwCheck(array_column($recientes,'estado_proceso')===['activa','inactiva','finalizada','proxima'],'Estados reales existentes, sin inventar estados editoriales');
$p->exec('UPDATE convocatorias SET estado=1 WHERE id=4');$cambiados=$model->obtener();mwCheck($cambiados['resumen']['publicadas']===4 && $cambiados['territorios'][0]['id']===14,'Datos dinámicos, no cantidades fijas');
$p->exec('UPDATE convocatorias SET estado=0 WHERE id=4');
if (in_array('--render',$argv,true)) {
    $_SESSION=['nombre'=>'Prueba'];
    function tienePermiso($p) { return !in_array('--sin-permiso',$GLOBALS['argv'],true); }
    $widgetsMarketing=in_array('--vacio',$argv,true)?['resumen'=>['periodo'=>'2026-10','hoy'=>'2026-10-23','total'=>0,'publicadas'=>0,'inactivas'=>0,'proximas'=>0],'territorios'=>[]]:$datos;
    $publicacionesRecientesMarketing=in_array('--vacio',$argv,true)?[]:$recientes;
    $resumenMarketing=$conv->obtenerResumenDashboard();
    $indicadoresClaveMarketing=['publicaciones'=>$resumenMarketing['total'],'proximas'=>$resumenMarketing['proximas_finalizar'],'total_estados'=>6,'estados_cubiertos'=>5];
    require getenv('IMPE_WIDGETS_BASELINE') ?: __DIR__.'/../app/views/dashboard/marketing.php';
    exit;
}
$p->exec('DELETE FROM convocatorias');$vacio=$model->obtener();mwCheck($vacio['resumen']['total']===0 && $vacio['territorios']===[],'Vacío real sin estados de relleno');
$failure=new class { public function query($sql) { throw new RuntimeException('Privado no debe registrarse'); } };
$fallo=(new MarketingWidgetsModel($failure))->obtener();mwCheck($fallo===['resumen'=>null,'territorios'=>null],'Error parcial no inventa ceros');
echo "OK: SELECT aislados, mes calendario, vigencia, duplicados, multiestado, top 4, recientes, desempates, estados, vacíos y fallos. Sin producción.\n";
