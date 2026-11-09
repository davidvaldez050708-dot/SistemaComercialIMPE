<?php
// Sólo lectura, únicamente IDs y nombres institucionales; nunca contactos ni secretos.
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
ini_set('display_errors','0');
$options=getopt('',['estado:','root:']);
$root=realpath((string)($options['root']??dirname(__DIR__)));
$estado=filter_var($options['estado']??11,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
if(!$root || !$estado) { fwrite(STDERR,"CONVENIOS_ARGUMENTOS_INVALIDOS\n");exit(2); }
try {
    require_once $root.'/app/models/CorreoConvocatoriaConsultaModel.php';
    $db=(new Database())->connect();$catalog=(new CorreoConvocatoriaConsultaModel($db))->catalogo();
    $out=['version'=>'convenios-manuales-v1','estado_id'=>$estado,'avisos'=>$catalog['avisos'],'convenios'=>[]];
    foreach($catalog['convenios'] as $row) {
        if($row['estado_id']!==$estado) { continue; }
        $ids=[];
        foreach($catalog['convocatorias'] as $call) {
            if(in_array($row['id'],array_column($call['convenios'],'id'),true) && in_array($estado,array_column($call['estados'],'id'),true)) { $ids[]=$call['id']; }
        }
        $out['convenios'][]=['clave'=>$row['id'],'id_real'=>$row['origen_id']??$row['id'],'origen'=>$row['origen']??'institucional','nombre'=>$row['nombre'],'convocatorias_activas_del_estado'=>$ids];
    }
    $r=$db->prepare('SELECT id FROM aliados_manuales WHERE activo=1 AND estado_id=? ORDER BY id');
    $r->bind_param('i',$estado);$r->execute();$result=$r->get_result();$missing=[];$real=[];
    $listed=array_column($out['convenios'],'clave');
    while($row=$result->fetch_assoc()) {
        $id=(int)$row['id'];$real[]=$id;if(!in_array('manual:'.$id,$listed,true)) { $missing[]=$id; }
    }
    $out['ids_manuales_en_fuente']=$real;$out['ids_manuales_omitidos']=$missing;
    $out['duplicados_catalogo']=count($listed)-count(array_unique($listed));
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    echo "SOLO LECTURA. Sin envíos, tokens, cambios de datos ni recuperación.\n";
    exit($missing || $out['duplicados_catalogo'] || $catalog['avisos']?1:0);
} catch(Throwable $error) {
    fwrite(STDERR,'CONVENIOS_DIAGNOSTICO_ERROR clase='.get_class($error).' codigo='.(int)$error->getCode().". Sin datos privados.\n");exit(2);
}
