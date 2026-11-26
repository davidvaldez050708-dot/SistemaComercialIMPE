<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaPbxSegmentosService.php';

/** Audio de una transferencia con participantes acreditados en el mismo PBX.
 * El endpoint conserva sesión/permisos; aquí se comprueba atención y asignación. */
final class TelefoniaAudioTransferidoService
{
    public function __construct(private mysqli $db) {}
    public function resolver(string $pbx,string $ext,string $desde,callable $record,?callable $stats=null,array $cachedRows=[]): array {
        $q=$this->db->prepare('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id=? ORDER BY id');
        $q->bind_param('s',$pbx);$q->execute();$events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $proof=TelefoniaPbxSegmentosService::transferencia($events,$ext);
        $ownArrival=(bool)array_filter($events,static fn($e)=>$e['evento']==='NOTIFY_INTERNAL'&&(string)$e['internal']===$ext);
        if(!$proof['atendida']||($proof['origen']===''&&!$ownArrival)||$proof['answer_at']<$desde||strtotime($proof['answer_at'])<strtotime('-30 days'))
            throw new DomainException('ACCESO_NO_AUTORIZADO');
        $reply=['ok'=>true,'estado'=>'GRABACION_EN_PREPARACION','disponible'=>false,'segmentos'=>0,'segmentos_info'=>[],'ids'=>[],'records'=>[],'cache_tramos'=>[]];
        if(!$proof['finalizada'])return $reply;
        $allowed=$proof['origen']!==''?[$proof['origen']]:[];
        $evidence=TelefoniaPbxSegmentosService::deEventos($events,$ext,$allowed);
        $ids=$evidence['segmentos'];$info=$evidence['segmentos_info'];$temporary=false;
        if($stats||$cachedRows){
            try{
                $at=(new DateTimeImmutable($proof['answer_at'],new DateTimeZone('America/Mexico_City')))->setTimezone(new DateTimeZone('UTC'));
                $rows=$stats?$stats($at->modify('-1 day')->format('Y-m-d H:i:s'),$at->modify('+1 day')->format('Y-m-d H:i:s')):$cachedRows;
                if(count($rows)>=1000)throw new RuntimeException('STATISTICS_INCOMPLETE');
                $parts=TelefoniaPbxSegmentosService::propios($rows,$pbx,$ext,$allowed);
                $reply['cache_tramos']=array_map(static fn($p)=>['pbx_call_id'=>$pbx,'sip'=>$p['extension'],'call_id'=>$p['id'],'callstart'=>$p['inicio'],'seconds'=>$p['segundos'],'disposition'=>'answered','is_recorded'=>$p['grabada']],$parts['tramos']);
                foreach($parts['tramos'] as $part){
                    if(in_array($part['id'],$evidence['audio_bloqueadas'],true))continue;
                    $index=array_search($part['id'],$ids,true);
                    if($index===false){if(!$part['grabada'])continue;$index=count($ids);$ids[]=$part['id'];}
                    $info[$index]=['extension'=>$part['extension'],'segundos'=>$part['segundos'],'inicio'=>$part['inicio'],'etiqueta'=>$part['extension']===$ext?'Extensión '.$ext:'Recepción · Ext. '.$part['extension']];
                }
                foreach($parts['rechazados'] as $id){$i=array_search($id,$ids,true);if($i!==false){unset($ids[$i],$info[$i]);}}
            }catch(Throwable $e){$temporary=true;error_log('[audio_transferido] STATISTICS_TEMPORARY ref='.substr(hash('sha256',$pbx),0,12));}
        }
        $ids=array_values($ids);$info=array_values($info);
        // Ordenar sólo si todos los segmentos tienen inicio oficial. La misma
        // evidencia se conserva en sesión privada para Range y reproducción.
        if($ids&&!array_filter($info,static fn($i)=>empty($i['inicio']))){$order=array_keys($ids);usort($order,static fn($a,$b)=>strcmp($info[$a]['inicio'],$info[$b]['inicio']));$ids=array_map(static fn($i)=>$ids[$i],$order);$info=array_map(static fn($i)=>$info[$i],$order);}
        if(count($ids)>8)throw new DomainException('TRAMO_PENDIENTE_VINCULACION');
        $reply['ids']=$ids;
        foreach($ids as $i=>$id){
            try{$audio=$record($id);if(empty($audio['link']))continue;
                if(!filter_var($audio['link'],FILTER_VALIDATE_URL)||!str_starts_with(strtolower($audio['link']),'https://'))continue;
                $reply['records'][$i]=$audio;$reply['segmentos_info'][]=($info[$i]??[])+['indice'=>$i];
            }catch(Throwable $e){$temporary=true;error_log('[audio_transferido] RECORD_TEMPORARY ref='.substr(hash('sha256',$pbx),0,12));}
        }
        $reply['segmentos']=count($reply['records']);$reply['disponible']=$reply['segmentos']>0;
        $reply['estado']=$reply['disponible']?($reply['segmentos']<count($ids)?'DISPONIBLE_PARCIAL':'DISPONIBLE'):
            ($temporary?'ERROR_TEMPORAL':($ids?'GRABACION_EN_PREPARACION':($evidence['audio_motivo']==='sin_referencia'?'SIN_GRABACION_REPORTADA':'TRAMO_PENDIENTE_VINCULACION')));
        return $reply;
    }
}
