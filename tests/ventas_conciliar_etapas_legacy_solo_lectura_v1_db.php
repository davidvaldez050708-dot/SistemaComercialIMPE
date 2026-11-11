<?php
/** Auditoría V3 de SOLO LECTURA contra base nueva desechable. */
if (getenv('IMPE_CONCILIACION_LEGACY_TEST_DB')!=='YES') {
    fwrite(STDERR, "BLOQUEADO: sólo base de prueba.\n");exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$host=getenv('MYSQL_HOST')?:'127.0.0.1';
$user=getenv('MYSQL_USER')?:'root';
$pass=getenv('MYSQL_PASSWORD')?:'';
$port=(int)(getenv('MYSQL_PORT')?:3306);
$db=new mysqli($host,$user,$pass,'',$port);
$db->set_charset('utf8mb4');
$name='impe_v3_legacy_test_'.bin2hex(random_bytes(5));
$created=false;$tmp=null;
function checkLegacy($ok,$why) {
    if(!$ok) throw new RuntimeException('ERROR LEGACY: '.$why);
}
try {
    $db->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $created=true;
    $db->select_db($name);
    $schema=[
        "CREATE TABLE prospecto_asignaciones
            (id BIGINT UNSIGNED PRIMARY KEY,prospecto_id BIGINT UNSIGNED, vigente TINYINT) ENGINE=InnoDB",
        "CREATE TABLE prospecto_embudo_etapas
            (asignacion_id BIGINT UNSIGNED PRIMARY KEY,etapa VARCHAR(40)) ENGINE=InnoDB",
        "CREATE TABLE prospecto_embudo_llamadas
            (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, asignacion_id BIGINT UNSIGNED,
             usuario_id INT, extension VARCHAR(20), pbx_call_id VARCHAR(100)) ENGINE=InnoDB",
        "CREATE TABLE prospecto_embudo_agenda
            (asignacion_id BIGINT UNSIGNED PRIMARY KEY,
             proxima_accion_at DATETIME NULL,revision_fecha DATE NULL) ENGINE=InnoDB",
        "CREATE TABLE prospecto_intereses
            (id INT AUTO_INCREMENT PRIMARY KEY,prospecto_id BIGINT UNSIGNED,
             programa_codigo VARCHAR(40)) ENGINE=InnoDB",
        "CREATE TABLE telefonia_ventas_resultados
            (id BIGINT UNSIGNED PRIMARY KEY,usuario_id INT,
             extension VARCHAR(20),pbx_call_id VARCHAR(100),
             resultado VARCHAR(36)) ENGINE=InnoDB",
        "CREATE TABLE telefonia_ventas_resultado_selecciones
            (resultado_id BIGINT UNSIGNED,codigo VARCHAR(36),posicion INT,
             PRIMARY KEY(resultado_id,codigo)) ENGINE=InnoDB"
    ];
    foreach ($schema as $sql) $db->query($sql);
    $db->query("INSERT INTO prospecto_asignaciones VALUES (11,101,1),(12,102,1),(13,103,0)");
    $db->query("INSERT INTO prospecto_embudo_etapas VALUES
        (11,'INTERESADO'),(12,'SEGUIMIENTO'),(13,'PAGO_CONFIRMADO')");
    $db->query("INSERT INTO prospecto_embudo_llamadas
        (asignacion_id,usuario_id,extension,pbx_call_id)
        VALUES (11,8,'150','out_11111111')");
    $db->query("INSERT INTO telefonia_ventas_resultados
        (id,usuario_id,extension,pbx_call_id,resultado)
        VALUES (91,8,'150','out_11111111','REQUIERE_SEGUIMIENTO')");
    $db->query("INSERT INTO telefonia_ventas_resultado_selecciones
        VALUES(91,'PROSPECTO_POTENCIAL',0),(91,'REQUIERE_SEGUIMIENTO',1)");
    $db->query("INSERT INTO prospecto_intereses(prospecto_id,programa_codigo)
        VALUES(101,'BACHILLERATO')");
    $db->query("INSERT INTO prospecto_embudo_agenda VALUES
        (12,'2026-11-20 19:00:00',NULL)");

    $tmp=sys_get_temp_dir().'/impe_legacy_v3_'.bin2hex(random_bytes(5));
    checkLegacy(mkdir($tmp,0700),'carpeta de prueba');
    $site=$tmp.'/site';
    checkLegacy(mkdir($site,0700),'raíz simulada');
    checkLegacy(mkdir($site.'/config',0700),'configuración falsa');
    file_put_contents($site.'/config/db_connection.php', <<<'PHP'
<?php
class Database {
    public function connect(bool $persistente=true): mysqli {
        $db=new mysqli(
            getenv('MYSQL_HOST')?:'127.0.0.1',
            getenv('MYSQL_USER')?:'root',
            getenv('MYSQL_PASSWORD')?:'',
            getenv('IMPE_CONCILIACION_DB_NAME'),
            (int)(getenv('MYSQL_PORT')?:3306)
        );
        $db->set_charset('utf8mb4');
        return $db;
    }
}
PHP
    );
    $script=dirname(__DIR__).'/tools/ventas_conciliar_etapas_legacy_solo_lectura_v1.php';
    $run=function(array $args)use($script,$site,$host,$user,$pass,$port,$name){
        $env=[
            'IMPE_SITE_ROOT'=>$site,'IMPE_CONCILIACION_DB_NAME'=>$name,
            'MYSQL_HOST'=>$host,'MYSQL_USER'=>$user,
            'MYSQL_PASSWORD'=>$pass,'MYSQL_PORT'=>(string)$port
        ];
        $cmd=implode(' ',array_map('escapeshellarg',array_merge([PHP_BINARY,$script],$args)));
        $h=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
            $pipes,null,$env);
        checkLegacy(is_resource($h),'subproceso');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);
        fclose($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[2]);return [proc_close($h),$out,$err];
    };
    $before=(int)$db->query('SELECT COUNT(*) n FROM prospecto_embudo_etapas')->fetch_assoc()['n'];
    $no=$run([]);
    checkLegacy($no[0]!==0 && strpos($no[2],'--check')!==false,
        'sin autorización sólo lectura se bloquea');
    $x=$run(['--check']);
    checkLegacy($x[0]===0,'auditoría sin escritura: '.$x[2]);
    $out=$x[1];
    foreach([
        'INTERESADO: 1','SEGUIMIENTO: 1',
        'CASO 1 · ETAPA INTERESADO · LLAMADAS VINCULADAS 1',
        'PROGRAMA CODIFICADO: SI',
        'POTENCIAL DECLARADO EN LLAMADA: SI',
        'REQUISITOS/DOCUMENTOS CONFIRMADOS: NO DETERMINABLE',
        'CASO 2 · ETAPA SEGUIMIENTO · LLAMADAS VINCULADAS 0',
        'PROXIMA ACCION REGISTRADA: SI',
        'CHECK OK: consulta sin cambios y sin datos personales.'
    ] as $expected) {
        checkLegacy(strpos($out,$expected)!==false,'faltó '.$expected);
    }
    checkLegacy(strpos($out,'PAGO_CONFIRMADO')===false,
        'no revela etapas inactivas');
    checkLegacy(strpos($out,'out_11111111')===false,
        'no revela PBX');
    checkLegacy(strpos($out,'BACHILLERATO')===false,
        'no revela programa');
    checkLegacy((int)$db->query('SELECT COUNT(*) n FROM prospecto_embudo_etapas')->fetch_assoc()['n']===$before,
        'nunca cambia etapas');
    $x2=$run(['--check']);
    checkLegacy($x2[0]===0 && $x2[1]===$out,'repetición idempotente de sólo lectura');
    echo "CHECK OK: auditoría legacy de solo lectura, multiselección V4, programa, siguiente acción, protección de PII y asignaciones vigentes. MySQL temporal.\n";
} finally {
    if($created)$db->query('DROP DATABASE '.$name);
    $db->close();
    if($tmp && is_dir($tmp)){
        $remove=function($p)use(&$remove){
            if(is_dir($p) && !is_link($p)){
                foreach(scandir($p) as $f) {
                    if($f==='.'||$f==='..')continue;
                    $remove($p.'/'.$f);
                }
                rmdir($p);
            }else unlink($p);
        };
        $remove($tmp);
    }
}
