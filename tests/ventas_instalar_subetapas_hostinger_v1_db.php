<?php
/**
 * End-to-end DB installation test on a NEW disposable MySQL/MariaDB schema.
 * Never use a production schema. No DROP in the installer itself.
 */
if (getenv('IMPE_SUBETAPAS_TEST_DB')!=='YES') {
    fwrite(STDERR,"BLOQUEADO: requiere base desechable para prueba.\n");exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__);
$script=$root.'/tools/ventas_instalar_subetapas_hostinger_v1.php';
$sql=$root.'/database/migrations/2026_11_11_ventas_subetapas_comerciales_propuesta.sql';
function assertSchema($ok,string $m): void {
    if(!$ok)throw new RuntimeException('FALLO INSTALADOR: '.$m);
}
$host=getenv('MYSQL_HOST')?:'127.0.0.1';
$user=getenv('MYSQL_USER')?:'root';
$pass=getenv('MYSQL_PASSWORD')?:'';
$port=(int)(getenv('MYSQL_PORT')?:3306);
$admin=new mysqli($host,$user,$pass,'',$port);
$admin->set_charset('utf8mb4');
$dbname='impe_test_subetapas_'.bin2hex(random_bytes(6));
$created=false;$tmp=null;
try {
    $admin->query('CREATE DATABASE '.$dbname.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created=true;
    $admin->select_db($dbname);
    $admin->query('CREATE TABLE prospecto_asignaciones (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        asesor_id INT NOT NULL, vigente TINYINT UNSIGNED NULL
    ) ENGINE=InnoDB');
    $admin->query('CREATE TABLE prospecto_embudo_etapas (
        asignacion_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        etapa VARCHAR(30) NOT NULL, cambiado_por INT NULL,
        actualizado_at DATETIME NOT NULL,
        FOREIGN KEY (asignacion_id) REFERENCES prospecto_asignaciones(id)
    ) ENGINE=InnoDB');
    $admin->query('INSERT INTO prospecto_asignaciones VALUES (101,8,1),(102,8,1)');
    $admin->query("INSERT INTO prospecto_embudo_etapas VALUES
        (101,'INTERESADO',8,'2026-11-10 20:00:00'),
        (102,'SEGUIMIENTO',8,'2026-11-10 20:01:00')");

    $tmp=sys_get_temp_dir().'/impe_subetapas_test_'.bin2hex(random_bytes(5));
    assertSchema(mkdir($tmp,0700),'crear carpeta privada de test');
    $site=$tmp.'/site';
    assertSchema(mkdir($site,0700),'crear raíz falsa');
    assertSchema(mkdir($site.'/config',0700),'crear config falsa');
    $config=<<<'PHP'
<?php
class Database {
    public function connect(bool $persistente=true): mysqli {
        $db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
            getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'',
            getenv('IMPE_SUBETAPAS_DB_NAME'),
            (int)(getenv('MYSQL_PORT')?:3306));
        $db->set_charset('utf8mb4');
        return $db;
    }
}
PHP;
    file_put_contents($site.'/config/db_connection.php',$config);
    $backup=$tmp.'/backups';
    $env= [
        'IMPE_SUBETAPAS_DB_NAME'=>$dbname,
        'MYSQL_HOST'=>$host,'MYSQL_USER'=>$user,
        'MYSQL_PASSWORD'=>$pass,
        'MYSQL_PORT'=>(string)$port
    ];
    $run=function(array $args)use($env,$script,$site,$sql):array{
        $argv=[PHP_BINARY,$script,'--site',$site,'--sql',$sql];
        foreach($args as $v)$argv[]=$v;
        $command=implode(' ',array_map('escapeshellarg',$argv));
        $process=proc_open($command,[
            0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
        ],$pipes,null,$env);
        assertSchema(is_resource($process),'subproceso PHP');
        fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
        return [proc_close($process),(string)$out,(string)$err];
    };
    $countTables=function()use($admin){
        return (int)$admin->query("SELECT COUNT(*) AS n
          FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME IN ('prospecto_embudo_subetapas',
                              'prospecto_embudo_subetapas_historial')")->fetch_assoc()['n'];
    };
    $before=(int)$admin->query('SELECT COUNT(*) n FROM prospecto_embudo_etapas')->fetch_assoc()['n'];
    $check=$run(['--check']);
    assertSchema($check[0]===0 && strpos($check[1],'PREPARADO:')!==false,
        'preflight de solo lectura, ambas tablas ausentes');
    assertSchema($countTables()===0 && !is_dir($backup),
        'check no crea tablas ni carpetas');
    $unauthorized=$run(['--install','--backup-root',$backup]);
    assertSchema($unauthorized[0]!==0 && $countTables()===0,
        'instalación sin confirmación está bloqueada');
    $wrong=$tmp.'/sql_incompatible.sql';
    file_put_contents($wrong,str_replace('prospecto_embudo_subetapas',
        'otra_tabla',file_get_contents($sql)));
    $badSource=$run(['--check','--sql',$wrong]);
    // The last --sql wins; test should reject tampered content.
    assertSchema($badSource[0]!==0 && $countTables()===0,
        'SQL de integridad distinta bloqueado');
    $install=$run(['--install','--backup-root',$backup,
        '--confirm','INSTALAR_SOLO_SUBETAPAS_IMPE_V1']);
    assertSchema($install[0]===0,
        'instalación autorizada: '.substr($install[2],0,400));
    assertSchema($countTables()===2,
        'instala únicamente ambas tablas nuevas');
    assertSchema(strpos($install[1],'POST-CHECK OK')!==false,
        'verificación posterior completa');
    assertSchema((int)$admin->query(
        'SELECT COUNT(*) n FROM prospecto_embudo_etapas')->fetch_assoc()['n']===$before,
        'no altera filas del embudo');
    $stat=(int)$admin->query(
        "SELECT COUNT(*) n FROM prospecto_embudo_etapas WHERE etapa='INTERESADO'"
    )->fetch_assoc()['n'];
    assertSchema($stat===1,
        'no reclasificar Interesados como Potenciales');
    $rows=(int)$admin->query(
        'SELECT COUNT(*) n FROM prospecto_embudo_subetapas')->fetch_assoc()['n'];
    $historical=(int)$admin->query(
        'SELECT COUNT(*) n FROM prospecto_embudo_subetapas_historial')->fetch_assoc()['n'];
    assertSchema($rows===0 && $historical===0,
        'tablas nuevas vacías');
    $backupFiles=glob($backup.'/*/preinstalacion.json');
    assertSchema(count($backupFiles)===1 && is_file($backupFiles[0]),
        'respaldo privado previo a SQL');
    assertSchema((fileperms($backupFiles[0])&0777)===0600,
        'manifest privado 0600');
    assertSchema((fileperms(dirname($backupFiles[0]))&0777)===0700,
        'directorio privado 0700');
    $m=json_decode(file_get_contents($backupFiles[0]),true);
    assertSchema($m['antes']['conteos']['INTERESADO']===1 &&
        $m['antes']['conteos']['SEGUIMIENTO']===1 &&
        $m['alcance']!=='',
        'manifiesto conserva totales anteriores sin datos personales');
    $again=$run(['--install','--backup-root',$backup,
        '--confirm','INSTALAR_SOLO_SUBETAPAS_IMPE_V1']);
    assertSchema($again[0]===0 && strpos($again[1],'SIN CAMBIOS')!==false,
        'repetición idempotente');
    assertSchema(count(glob($backup.'/*/preinstalacion.json'))===1,
        'repetición no produce otro backup');
    $verified=$run(['--check']);
    assertSchema($verified[0]===0 && strpos($verified[1],'YA INSTALADO')!==false,
        'comprobación posterior de estructura');

    // Incompatibilidad o instalaciones parciales se detectan antes de SQL.
    $admin->query('DROP TABLE prospecto_embudo_subetapas_historial');
    $partial=$run(['--check']);
    assertSchema($partial[0]!==0 && strpos($partial[2],'parcial')!==false,
        'bloquea estado parcial, no elimina la otra tabla');
    assertSchema($countTables()===1,'preflight no repara destructivamente');
    $admin->query('DROP TABLE prospecto_embudo_subetapas');
    $admin->query('CREATE TABLE prospecto_embudo_subetapas(id INT PRIMARY KEY) ENGINE=InnoDB');
    $bad=$run(['--check']);
    assertSchema($bad[0]!==0,'bloquea colisión con tabla personalizada');
    assertSchema($countTables()===1,'no altera tabla ajena existente');

    echo "CHECK OK: MySQL temporal, preflight sin escrituras, bloqueo confirmación, hash SQL, dos tablas aditivas, historial íntegro, backup privado, idempotencia y bloqueos parcial/personalizado.\n";
}finally{
    if($created)$admin->query('DROP DATABASE '.$dbname);
    $admin->close();
    if($tmp && is_dir($tmp)){
        $rm=function(string $path)use(&$rm):void{
            if(is_dir($path) && !is_link($path)){
                foreach(scandir($path) as $name){
                    if($name==='.'||$name==='..')continue;
                    $rm($path.'/'.$name);
                }
                rmdir($path);
            }else unlink($path);
        };
        $rm($tmp);
    }
}
