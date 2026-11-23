<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root',$argv[1]??'',null,33472);
$name='impe_consultas_repetidas_test';
$db->query("CREATE DATABASE IF NOT EXISTS $name CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$db->select_db($name);
try {
    $db->query('DROP TABLE IF EXISTS telefonia_ventas_llamadas,telefonia_disponibilidad');
    $db->query('CREATE TABLE telefonia_ventas_llamadas(id INT PRIMARY KEY,usuario_id INT,direccion VARCHAR(20),extension VARCHAR(12),pbx_call_id VARCHAR(100),solicitud_id INT NULL,nombre VARCHAR(150),origen_extension VARCHAR(12),estado_id INT,numero VARCHAR(30)) ENGINE=InnoDB');
    $db->query('CREATE TABLE telefonia_disponibilidad(id INT PRIMARY KEY,destino_usuario INT,destino_extension VARCHAR(12),referencia VARCHAR(100),estado VARCHAR(30),ruta_json TEXT,origen_extension VARCHAR(12),numero VARCHAR(30)) ENGINE=InnoDB');
    $calls=$db->prepare("INSERT INTO telefonia_ventas_llamadas VALUES(?,?,'Entrante','123',?,NULL,'','',0,'original')");
    $tasks=$db->prepare("INSERT INTO telefonia_disponibilidad VALUES(?,?,'123',?,'TRANSFERIDA',?,'101','fictional')");
    for($id=1;$id<=225;$id++) {
        $uid=$id===224?9:7;$ref='ref-test-'.$id;
        $route=['tipo'=>$id<=200?'VINCULACION':'PROSPECTO','responsable_id'=>$uid,'extension'=>'123','referencia_llamada'=>$ref,'institucion_nombre'=>'Entidad sintética '.$id,'estado_id'=>11];
        if($id>=206&&$id<=210)$route['responsable_id']=99;
        if($id>=211&&$id<=215)$route['extension']='999';
        if($id>=216&&$id<=220)$route['referencia_llamada']='otra-ref';
        $json=$id===221?'invalid-json':json_encode($route);
        $calls->bind_param('iis',$id,$uid,$ref);$calls->execute();
        $tasks->bind_param('iiss',$id,$uid,$ref,$json);$tasks->execute();
    }
    // Ambigüedad de una transferencia: conservarla sin vincular.
    $db->query('INSERT INTO telefonia_disponibilidad SELECT 1000,destino_usuario,destino_extension,referencia,estado,ruta_json,origen_extension,numero FROM telefonia_disponibilidad WHERE id=222');
    $db->query("UPDATE telefonia_ventas_llamadas SET direccion='Saliente' WHERE id=223");
    $db->query('UPDATE telefonia_ventas_llamadas SET solicitud_id=555 WHERE id=225');
    $snapshot=$db->query('SELECT * FROM telefonia_ventas_llamadas ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $fixture=file_get_contents(__DIR__.'/fixtures/rendimiento_metodos_instalados_20261123.txt');
    $part=explode('ARCHIVO: app/services/TelefoniaVentasLlamadasService.php — METODO: sincronizar',$fixture)[1];
    $method=explode('ARCHIVO: ',$part)[0];
    $block=substr($method,strpos($method,'        $metadata=$this->rows('));
    $block=preg_replace('/\}\s*$/','',$block);
    $spec=json_decode(file_get_contents(__DIR__.'/../tools/consultas_repetidas_release.json'),true);
    $h=$spec['files'][0]['hunks'][0];
    $old=str_replace("\r\n","\n",$block);
    if(substr_count($old,$h['before'])!==1)throw new RuntimeException('Fixture instalada no coincide');
    $new=str_replace($h['before'],$h['after'],$old);
    foreach(['Antes'=>$old,'Despues'=>$new] as $class=>$code) {
        eval('class '.$class.' { private mysqli $db; public function __construct(mysqli $db){$this->db=$db;} private function rows(string $sql,array $args):array{$s=$this->db->prepare($sql);$s->bind_param(str_repeat("s",count($args)),...$args);$s->execute();return $s->get_result()->fetch_all(MYSQLI_ASSOC);} public function run(int $uid):void{'.$code.'}}');
    }
    $stats=static function():int{$s=mysqli_get_client_stats();return (int)$s['com_query']+(int)$s['com_stmt_execute'];};
    $before=$stats();(new Antes($db))->run(7);$oldCount=$stats()-$before;
    $expected=$db->query('SELECT * FROM telefonia_ventas_llamadas ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $db->query("UPDATE telefonia_ventas_llamadas SET solicitud_id=NULL,nombre='',origen_extension='',estado_id=0,numero='original' WHERE id<>225");
    $before=$stats();(new Despues($db))->run(7);$newCount=$stats()-$before;
    $actual=$db->query('SELECT * FROM telefonia_ventas_llamadas ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    if($expected!==$actual)throw new RuntimeException('Cambió la atribución o la metadata');
    $updated=[];
    foreach($actual as $i=>$row)if($row!==$snapshot[$i])$updated[]=(int)$row['id'];
    if($updated!==[201,202,203,204,205])throw new RuntimeException('Cambió una llamada ajena, ambigua o no elegible');
    if($oldCount<220||$newCount!==6)throw new RuntimeException('No se eliminó el crecimiento de consultas');
    // Segunda ejecución y conjunto vacío: ninguna mutación adicional.
    (new Despues($db))->run(7);(new Despues($db))->run(999);
    if($db->query('SELECT * FROM telefonia_ventas_llamadas ORDER BY id')->fetch_all(MYSQLI_ASSOC)!==$actual)throw new RuntimeException('No es idempotente');
    echo "PASS MariaDB: $oldCount -> $newCount consultas; misma atribución, exclusión de rutas ajenas/ambiguas, JSON inválido y segunda ejecución.\n";
} finally {
    $db->query("DROP DATABASE $name");
}
