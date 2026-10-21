<?php
declare(strict_types=1);
// Ejecutar fuera de public_html. Sólo lectura; no consulta API ni modifica la PBX.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==4||!ctype_digit($argv[1])||!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$argv[2])||!preg_match('/^[0-9]{3,6}$/D',$argv[3])){
 fwrite(STDERR,"Uso: php diagnosticar_cierre_ventas.php USUARIO PBX EXTENSION\n");exit(2);
}
try{
 $root=rtrim(getenv('IMPE_RUNTIME_ROOT')?:dirname(__DIR__),'/\\');
 require_once $root.'/app/services/TelefoniaVentasLlamadasService.php';
 $db=(new Database())->connect(false);
 $svc=new TelefoniaVentasLlamadasService($db);
 // Verifica atribución histórica y permisos sin backfill ni crear tablas.
 $call=$svc->obtener((int)$argv[1],$argv[2],$argv[3]);
 $s=$db->prepare('SELECT id,evento,internal,received_at,duration,disposition,call_id_with_rec,is_recorded,payload_json FROM telefonia_zadarma_eventos WHERE pbx_call_id=? ORDER BY id');
 $s->bind_param('s',$argv[2]);$s->execute();$events=[];
 foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $e){
  $p=json_decode((string)$e['payload_json'],true)?:[];
  $e['last_internal']=$p['last_internal']??null;$e['transfer_from']=$p['transfer_from']??null;
  $e['duration']=array_key_exists('duration',$p)?$e['duration']:null;unset($e['payload_json']);$events[]=$e;
 }
 echo json_encode(['usuario'=>(int)$argv[1],'extension'=>$argv[3],'pbx_call_id'=>$argv[2],
  'hechos'=>array_intersect_key($call,array_flip(['finalizada','conectada','segundos','estado_pbx','audio_motivo','segmentos'])),
  'resultado_guardado'=>$call['registro']!==null,'eventos'=>$events],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'No se pudo revisar el tramo autorizado: '.$e->getMessage().PHP_EOL);exit(1);}
