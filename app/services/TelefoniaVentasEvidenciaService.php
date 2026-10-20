<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
/** Caché durable de evidencias de API; no genera webhooks, cierres ni resultados. */
final class TelefoniaVentasEvidenciaService
{
    private mysqli $db;
    public function __construct(mysqli $db){$this->db=$db;}
    public function disponible(): bool{return $this->db->query("SHOW TABLES LIKE 'telefonia_ventas_evidencia'")->num_rows>0;}
    public function enriquecer(array $calls,int $uid): array
    {
        if(!$calls||!$this->disponible())return $calls;
        $refs=array_values(array_unique(array_column($calls,'pbx_call_id')));$args=array_merge([$uid],$refs);
        $s=$this->db->prepare('SELECT extension,pbx_call_id,datos_json FROM telefonia_ventas_evidencia WHERE usuario_id=? AND pbx_call_id IN ('.implode(',',array_fill(0,count($refs),'?')).')');
        $s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$cache=[];
        foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$cache[$row['extension'].'|'.$row['pbx_call_id']]=json_decode($row['datos_json']??'',true)?:[];
        foreach($calls as &$call){
            if(empty($call['finalizada'])||empty($call['conectada']))continue;
            $data=$cache[$call['extension'].'|'.$call['pbx_call_id']]??[];
            $call['tramos_verificados']=$data['tramos']??[];
            if($call['segundos']===null&&isset($data['segundos']))$call['segundos']=$data['segundos'];
            $ids=array_column(array_filter($call['tramos_verificados'],static fn($r)=>!empty($r['audio_verificado'])),'id');
            $call['segmentos']=array_values(array_diff(array_unique(array_merge($call['segmentos'],$ids)),$data['rechazados']??[]));
            $call['segmentos_info']=[];
            foreach($call['segmentos'] as $id){$info=null;foreach($call['tramos_verificados'] as $n=>$part)if($part['id']===$id)$info=['tramo'=>$n+1,'segundos'=>$part['segundos']];$call['segmentos_info'][]=$info;}
            $overflow=count($call['segmentos'])>8;
            if($overflow){$call['segmentos']=[];$call['segmentos_info']=[];$call['audio_motivo']='limite';}
            elseif($call['segmentos'])$call['audio_motivo']='referencia_propia';
            elseif($call['tramos_verificados'])$call['audio_motivo']=array_filter($call['tramos_verificados'],static fn($p)=>!empty($p['grabada']))?'pendiente_referencia':'no_reportada';
        }unset($call);
        return $calls;
    }
    /** Sólo endpoint POST autorizado. Los callbacks son inyectables para pruebas aisladas. */
    public function consultar(array $call,?callable $stats=null,?callable $record=null): array
    {
        if(!$this->disponible())throw new DomainException('Falta la migración de evidencias comerciales. Solicita su publicación al administrador.');
        if(empty($call['finalizada'])||empty($call['conectada']))throw new DomainException('Primero debe confirmarse el cierre y la respuesta de tu extensión.');
        $uid=(int)$call['usuario_id'];$ext=$call['extension'];$ref=$call['pbx_call_id'];
        $s=$this->db->prepare('INSERT IGNORE INTO telefonia_ventas_evidencia(usuario_id,extension,pbx_call_id) VALUES(?,?,?)');$s->bind_param('iss',$uid,$ext,$ref);$s->execute();
        // Comparación atómica: dos pestañas no disparan dos consultas al proveedor.
        $s=$this->db->prepare('UPDATE telefonia_ventas_evidencia SET consultado_at=UTC_TIMESTAMP() WHERE usuario_id=? AND extension=? AND pbx_call_id=? AND (consultado_at IS NULL OR consultado_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 SECOND))');
        $s->bind_param('iss',$uid,$ext,$ref);$s->execute();if(!$s->affected_rows)return $this->enriquecer([$call],$uid)[0];
        if(!$stats||!$record){require_once __DIR__.'/ZadarmaRecordingService.php';$api=new ZadarmaRecordingService(false);$stats??=[$api,'estadisticasPbx'];$record??=[$api,'obtenerGrabacionPorCallId'];}
        $date=new DateTimeImmutable($call['fecha'],new DateTimeZone('America/Mexico_City'));
        $rows=$stats($date->modify('-1 day')->format('Y-m-d H:i:s'),$date->setTimezone(new DateTimeZone('UTC'))->modify('+1 day')->format('Y-m-d H:i:s'));
        if(count($rows)>=1000)throw new DomainException('La consulta está incompleta. No se vinculó ningún audio.');
        $data=TelefoniaPbxSegmentosService::propios($rows,$ref,$ext);
        if(count($data['tramos'])>8)throw new DomainException('Hay demasiados segmentos para verificarlos de forma segura.');
        foreach($data['tramos'] as &$part){$part['audio_verificado']=false;if($part['grabada']){
            try{$audio=$record($part['id']);$part['audio_verificado']=!empty($audio['link'])&&filter_var($audio['link'],FILTER_VALIDATE_URL)&&str_starts_with(strtolower($audio['link']),'https://');}catch(Throwable $e){/* Audio aún en preparación: reintento posterior sin inventarlo. */}
        }}unset($part);
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $s=$this->db->prepare('UPDATE telefonia_ventas_evidencia SET datos_json=? WHERE usuario_id=? AND extension=? AND pbx_call_id=?');$s->bind_param('siss',$json,$uid,$ext,$ref);$s->execute();
        return $this->enriquecer([$call],$uid)[0];
    }
}
