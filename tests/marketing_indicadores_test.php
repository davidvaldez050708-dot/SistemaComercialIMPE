<?php
// SQL real contra SQLite en memoria; adaptación sólo de funciones de fecha MySQL.
if (in_array('--render', $argv, true)) { putenv('IMPE_BASE_URL=https://marketing-kpi.test/'); }
require_once __DIR__ . '/../app/models/MarketingIndicadoresModel.php';
require_once __DIR__ . '/../app/models/ConvocatoriaModel.php';
function kpiCheck($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
class KpiRows {
    private $rows; public function __construct(array $rows) { $this->rows=$rows; }
    public function fetch_assoc() { return array_shift($this->rows); }
}
class KpiDb {
    public $pdo; public $queries=[];
    public function __construct() { $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); }
    public function query($sql) {
        kpiCheck(str_starts_with(trim($sql),'SELECT'),'Sólo SELECT en los modelos del panel');$this->queries[]=$sql;
        if (str_contains($sql,'information_schema.tables')) { $sql="SELECT name AS nombre_tabla FROM sqlite_master WHERE type='table'"; }
        $sql=str_replace(['CURDATE()',"DATE_ADD('2026-10-23', INTERVAL 7 DAY)"],["'2026-10-23'","'2026-10-30'"],$sql);
        $sql=preg_replace('/DATEDIFF\(convocatorias.fecha_termino, \'2026-10-23\'\)/',"CAST(julianday(convocatorias.fecha_termino)-julianday('2026-10-23') AS INTEGER)",$sql);
        $sql=preg_replace('/GROUP_CONCAT\(\s*DISTINCT estados.nombre\s*ORDER BY estados.nombre\s*SEPARATOR \x27, \x27\s*\)/','GROUP_CONCAT(DISTINCT estados.nombre)',$sql);
        return new KpiRows($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
}
function kpiFixture(): array {
    $db=new KpiDb();$p=$db->pdo;
    $p->exec("CREATE TABLE estados(id INTEGER PRIMARY KEY,nombre TEXT,estado INTEGER);
        CREATE TABLE convocatorias(id INTEGER PRIMARY KEY,titulo TEXT,estado INTEGER,fecha_inicio TEXT,fecha_termino TEXT,tipo_convocatoria TEXT,subtipo_convocatoria TEXT);
        CREATE TABLE convocatoria_estados(convocatoria_id INTEGER,estado_id INTEGER);
        CREATE TABLE seguimientos_vinculacion(id INTEGER PRIMARY KEY,estado_id INTEGER,activo INTEGER);
        CREATE TABLE aliados_asignaciones(seguimiento_id INTEGER,activo INTEGER);
        CREATE TABLE seguimientos_vinculacion_post_envio(seguimiento_id INTEGER,convenio_formalizado_at TEXT);
        CREATE TABLE aliados_manuales(id INTEGER PRIMARY KEY,estado_id INTEGER,activo INTEGER);
        CREATE TABLE marketing_convenio_convocatorias(seguimiento_id INTEGER,convocatoria_id INTEGER);
        CREATE TABLE marketing_aliado_manual_convocatorias(manual_id INTEGER,convocatoria_id INTEGER);
        CREATE TABLE marketing_aliado_convocatorias_origen(origen_tipo TEXT,origen_id INTEGER,convocatoria_id INTEGER);");
    for($i=1;$i<=32;$i++) { $p->exec("INSERT INTO estados VALUES($i,'Entidad $i',1)"); }
    $p->exec("INSERT INTO convocatorias VALUES
        (1,'Hoy',1,'2026-01-01','2026-10-23','bachillerato',''),
        (2,'Siete días',1,'2026-01-01','2026-10-30','titulacion',''),
        (3,'Después',1,'2026-01-01','2026-10-31','titulacion',''),
        (4,'Ya vencida',0,'2026-01-01','2026-10-22','bachillerato',''),
        (5,'Inactiva',0,'2026-01-01','2026-10-25','bachillerato',''),
        (6,'Histórica',0,'2025-01-01','2025-12-31','bachillerato','');
        INSERT INTO convocatoria_estados VALUES(1,11),(1,11),(2,11),(2,25),(3,25),(4,1);
        INSERT INTO seguimientos_vinculacion VALUES(1,11,1),(2,25,1),(3,11,1),(4,1,0),(5,2,1),(6,3,1);
        INSERT INTO aliados_asignaciones VALUES(1,1),(1,1),(2,1),(3,1),(4,1),(5,0);
        INSERT INTO seguimientos_vinculacion_post_envio VALUES(1,'2026-01-01'),(3,'2026-01-01'),(4,'2026-01-01'),(5,'2026-01-01'),(6,'2026-01-01');
        INSERT INTO aliados_manuales VALUES(1,11,1),(2,25,1),(3,25,0);
        INSERT INTO marketing_convenio_convocatorias VALUES(1,1),(1,2),(2,3),(3,4);
        INSERT INTO marketing_aliado_manual_convocatorias VALUES(1,1),(1,2),(2,5);
        INSERT INTO marketing_aliado_convocatorias_origen VALUES('institucional',1,1),('manual',1,1),('manual',1,3),('manual',3,1);");
    $reflection=new ReflectionClass(ConvocatoriaModel::class);$conv=$reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('connection')->setValue($conv,$db);
    return [$db,$conv];
}
function kpiObtener($db,$conv): array {
    $resumen=$conv->obtenerResumenDashboard();$proximas=$conv->obtenerProximasFinalizarDashboard();
    $resumen['proximas_finalizar']=count($proximas);$cobertura=$conv->obtenerCoberturaTerritorialDashboard();
    return [(new MarketingIndicadoresModel($db))->obtener($resumen,$cobertura),$resumen,$cobertura,$proximas];
}
[$db,$conv]=kpiFixture();[$datos,$resumen,$cobertura,$proximas]=kpiObtener($db,$conv);
$pendientesIniciales=$conv->obtenerEstadosSinConvocatoriaActivaDashboard();
kpiCheck($datos['publicaciones']===6,'Total acumulado incluye históricos/inactivas, sin filtro mensual');
kpiCheck($datos['convenios']===5,'3 asignaciones únicas válidas + 2 manuales, sin duplicar asignaciones');
kpiCheck($datos['aliados']===6,'Formalizados 1/3/5/6 + manuales 1/2; población distinta de Convenios');
kpiCheck($datos['aliados_con_convocatoria']===2 && $datos['aliados_porcentaje']==33.3,'Sólo dos aliados únicos con convocatoria activa; ID 1 manual e institucional distintos');
kpiCheck($datos['estados_convenios']===2 && $datos['convenios_porcentaje']==6.3,'Estados únicos sobre 32');
kpiCheck($datos['total_estados']===32 && $datos['estados_cubiertos']===2 && $datos['cobertura_porcentaje']==6.3,'Cobertura real sin duplicados de estado');
kpiCheck($datos['proximas']===2 && $datos['proximas_porcentaje']==66.7,'Hoy y día 7 incluidos, día 8/vencidas/inactivas excluidos');
$inicial=$datos;
$db->pdo->exec('UPDATE convocatorias SET estado=1 WHERE id=4');
kpiCheck(count($conv->obtenerProximasFinalizarDashboard())===2,'Una vencida tampoco entra si su bandera sigue activa');
$db->pdo->exec('UPDATE convocatorias SET estado=0 WHERE id=4');
$db->pdo->exec("UPDATE convocatorias SET estado=0 WHERE id=1; INSERT INTO aliados_manuales VALUES(8,3,1)");
[$cambiados]=kpiObtener($db,$conv);
kpiCheck($cambiados['publicaciones']===6 && $cambiados['convenios']===6 && $cambiados['aliados']===7 && $cambiados['proximas']===1,'Se actualiza al cambiar datos, sin modificar código');
$db->pdo->exec('DELETE FROM marketing_convenio_convocatorias; DELETE FROM marketing_aliado_manual_convocatorias; DELETE FROM marketing_aliado_convocatorias_origen');
[$sinRelaciones]=kpiObtener($db,$conv);kpiCheck($sinRelaciones['aliados_porcentaje']==0,'Tablas existentes sin relaciones: 0 real');
$db->pdo->exec('DROP TABLE marketing_convenio_convocatorias; DROP TABLE marketing_aliado_manual_convocatorias; DROP TABLE marketing_aliado_convocatorias_origen');
[$noDisponible]=kpiObtener($db,$conv);kpiCheck($noDisponible['aliados_porcentaje']===null,'Esquema sin relaciones: no inventar 0%');
$db->pdo->exec('DELETE FROM convocatorias; DELETE FROM aliados_manuales; DELETE FROM aliados_asignaciones; DELETE FROM seguimientos_vinculacion_post_envio');
[$vacio]=kpiObtener($db,$conv);kpiCheck($vacio['publicaciones']===0 && $vacio['convenios']===0 && $vacio['aliados']===0 && $vacio['proximas_porcentaje']==0,'Vacío sin división por cero');
$fallo=new class { public function query($sql) { throw new RuntimeException('Fallo ficticio'); } };
$fallidos=(new MarketingIndicadoresModel($fallo))->obtener($resumen,$cobertura);
kpiCheck($fallidos['convenios']===null && $fallidos['publicaciones']===6,'Fallo parcial no fabrica cifras y conserva publicaciones');
if(in_array('--render',$argv,true)) {
    $_SESSION=['nombre'=>'Diana'];
    function tienePermiso($codigo) { return true; }
    $indicadoresClaveMarketing=$inicial;$resumenMarketing=$resumen;$coberturaMarketing=$cobertura;
    $proximasFinalizarMarketing=$proximas;
    $estadosSinConvocatoria=$pendientesIniciales;
    require in_array('--baseline',$argv,true) ? getenv('IMPE_KPI_BASELINE_VIEW') : __DIR__.'/../app/views/dashboard/marketing.php';
} else { echo "OK: SQL real aislado: acumulado, fuentes distintas, manuales/institucionales, duplicados, 32 estados, fechas, actualización, vacíos y esquema opcional. Sin producción.\n"; }
