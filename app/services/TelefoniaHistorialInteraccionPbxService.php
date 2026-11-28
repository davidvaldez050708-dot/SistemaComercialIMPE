<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaGrabacionInteraccionService.php';

/** Proyección por lotes; no llamadas al proveedor, escrituras ni nuevas conexiones. */
final class TelefoniaHistorialInteraccionPbxService
{
    public function __construct(private mysqli $db){}

    public static function proyectar(array $events,array $bindings): array
    {
        $ext=TelefoniaGrabacionInteraccionService::extensionHistorica($events,$bindings);
        $audio=TelefoniaGrabacionInteraccionService::referenciasPropias($events,$ext);
        $facts=TelefoniaPbxSegmentosService::hechos($events,$audio['extension']);
        $reported=(bool)$audio['ids'];
        foreach($events as $event)if((string)($event['internal']??'')===$audio['extension']&&
            filter_var($event['is_recorded']??false,FILTER_VALIDATE_BOOLEAN))$reported=true;
        return ['segundos'=>$facts['segundos'],'ids'=>$audio['ids'],'grabada'=>$reported,'estado_pbx'=>$facts['estado_pbx']];
    }

    public function lote(array $interactions,int $sid): array
    {
        $rows=[];
        foreach($interactions as $row)if(strtoupper((string)($row['proveedor_externo']??''))==='ZADARMA'&&
            strtoupper((string)($row['canal']??''))==='LLAMADA_IP'&&ZadarmaPbxIdentifierHelper::pbxValido((string)$row['id_externo']))$rows[]=$row;
        $result=[];
        foreach(array_chunk($rows,200) as $chunk){
            $refs=array_values(array_unique(array_column($chunk,'id_externo')));$ids=array_column($chunk,'id');
            $where=implode(' OR ',array_fill(0,count($refs),'pbx_call_id=BINARY ?'));
            $q=$this->db->prepare("SELECT * FROM telefonia_zadarma_eventos WHERE ($where) ORDER BY id");
            $q->bind_param(str_repeat('s',count($refs)),...$refs);$q->execute();$events=[];
            foreach($q->get_result()->fetch_all(MYSQLI_ASSOC) as $event)$events[$event['pbx_call_id']][]=$event;$q->close();
            $marksIds=implode(',',array_fill(0,count($ids),'?'));
            $q=$this->db->prepare("SELECT usuario_id,interaccion_id,pbx_call_id,extension FROM telefonia_analista_entrantes WHERE seguimiento_id=? AND interaccion_id IN ($marksIds)");
            $args=[$sid,...$ids];$q->bind_param(str_repeat('i',count($args)),...$args);$q->execute();$native=[];
            foreach($q->get_result()->fetch_all(MYSQLI_ASSOC) as $binding)$native[$binding['interaccion_id']][]=$binding;$q->close();
            $q=$this->db->prepare("SELECT usuario_id,pbx_call_id,extension,pbx_inicio_evento FROM telefonia_analista_webrtc_sesiones WHERE protocolo=2 AND pbx_inicio_evento>0 AND ($where)");
            $q->bind_param(str_repeat('s',count($refs)),...$refs);$q->execute();$journal=[];
            foreach($q->get_result()->fetch_all(MYSQLI_ASSOC) as $binding)$journal[$binding['usuario_id'].'|'.$binding['pbx_call_id']][]=$binding;$q->close();
            foreach($chunk as $row){
                $ref=(string)$row['id_externo'];$owner=(int)$row['usuario_id'];$bindings=[];
                try{
                    foreach($native[$row['id']]??[] as $binding){
                        if((int)$binding['usuario_id']!==$owner||(!hash_equals($ref,(string)$binding['pbx_call_id'])&&!str_starts_with((string)$binding['pbx_call_id'],'sdk-')))
                            throw new DomainException('PARTICIPACION_AMBIGUA');
                        $bindings[]=$binding;
                    }
                    $result[$row['id']]=self::proyectar($events[$ref]??[],array_merge($bindings,$journal[$owner.'|'.$ref]??[]));
                }catch(DomainException $e){$result[$row['id']]=['segundos'=>null,'ids'=>[],'grabada'=>false,'estado_pbx'=>'Participación pendiente'];}
            }
        }
        return $result;
    }
}
