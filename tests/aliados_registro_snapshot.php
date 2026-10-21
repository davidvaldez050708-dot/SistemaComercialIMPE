<?php
// Fuerza el read-view previo a la espera que detectó la matriz MySQL/MariaDB.
if(PHP_SAPI!=='cli' || !preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:'')) exit(2);
require_once __DIR__.'/../config/db_connection.php';
$db=(new Database())->connect(false);
$row=$db->query('SELECT a.id,a.nombre,a.ubicacion FROM formulario_aliado_registro r JOIN formulario_aliados a ON a.id=r.aliado_id ORDER BY r.aliado_id LIMIT 1')->fetch_assoc();
$id=(int)$row['id']; $pipes=[]; $process=null;
$db->begin_transaction();
try {
    $db->query('SELECT id FROM formulario_aliados WHERE id='.$id.' FOR UPDATE');
    $process=proc_open([PHP_BINARY,__DIR__.'/aliados_registro_concurrente.php','snapshot'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if(!is_resource($process)) throw new RuntimeException('No inició la segunda conexión.');
    fclose($pipes[0]); $waiting=false;
    for($i=0;$i<100;$i++) {
        $result=$db->query('SHOW FULL PROCESSLIST');
        while($p=$result->fetch_assoc()) if(strpos($p['Info']??'','SELECT id, nombre, ubicacion, seguimiento_id FROM formulario_aliados')===0) $waiting=true;
        if($waiting) break; usleep(20000);
    }
    if(!$waiting) throw new RuntimeException('No se pudo forzar la espera del resolver.');
    $db->query("INSERT INTO formulario_registros (nombre,apellido,correo,movil,fecha_nacimiento,perfil_interes,estado_id,municipio_id,origen,created_at) VALUES ('Concurrencia','Snapshot','snapshot@example.test','7779876543','1990-01-01','Inglés',1,1,'PUBLICO',NOW())");
    $registro=(int)$db->insert_id;
    $clave=hash('sha256',json_encode(['snapshot@example.test','7779876543','1990-01-01','ingles'],JSON_UNESCAPED_UNICODE));
    $stmt=$db->prepare("INSERT INTO formulario_aliado_procedencias (aliado_id,tipo,registro_id,programa_codigo,aliado_nombre,aliado_ubicacion,clave) VALUES (?,'registro',?,'ingles',?,?,?)");
    $stmt->bind_param('iisss',$id,$registro,$row['nombre'],$row['ubicacion'],$clave);$stmt->execute();
    $db->commit();
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);$process=null;
    if($code!==0 || trim($output)!==(string)$registro) throw new RuntimeException('No recuperó el registro confirmado durante la espera: '.$error);
    if((int)$db->query("SELECT COUNT(*) n FROM formulario_registros WHERE correo='snapshot@example.test'")->fetch_assoc()['n']!==1) throw new RuntimeException('Duplicó prospecto al recuperar la procedencia.');
    echo "PASS: snapshot previo a bloqueo recupera la misma procedencia, sin duplicar prospectos\n";
} catch(Throwable $e) {
    $db->rollback();
    if(is_resource($process)){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
    throw $e;
}
