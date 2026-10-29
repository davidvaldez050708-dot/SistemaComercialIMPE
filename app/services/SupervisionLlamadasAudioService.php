<?php
declare(strict_types=1);
require_once __DIR__.'/SupervisionLlamadasRepository.php';
require_once __DIR__.'/TelefoniaGrabacionesTransferenciaVentasService.php';
require_once __DIR__.'/TelefoniaGrabacionesTransferenciaInstitucionService.php';
require_once __DIR__.'/TelefoniaInternaGrabacionService.php';
require_once __DIR__.'/TelefoniaResultadoVentasService.php';

/** Transporte separado del teléfono personal. Nunca suplanta al titular. */
final class SupervisionLlamadasAudioService
{
    private SupervisionLlamadasRepository $repo;
    public function __construct(private mysqli $db){$this->repo=new SupervisionLlamadasRepository($db);}
    public function resolver(int $uid,string $key,object $provider,bool $verify=false): array
    {
        $call=$this->repo->obtener($uid,$key,true);
        $ref=$call['pbx_call_id'];$ext=$call['extension'];$owner=$call['usuario_id'];
        // El resultado comercial no determina si existe evidencia reproducible.
        if($call['area']==='asesores'&&!TelefoniaVentasLlamadasService::audioConsultable($call))throw new DomainException('Falta confirmar la respuesta y el cierre o la transferencia autorizada de esta llamada.');
        if($call['area']==='asesores'&&$call['origen_extension']!==''&&$call['solicitud_id']){
            $out=(new TelefoniaGrabacionesTransferenciaVentasService($this->db))->resolver($ref,$owner,'destino',$ext,[$provider,'obtenerGrabacionPorCallId'],$verify?[$provider,'estadisticasPbx']:null);
            return self::sanear($out);
        }
        if($call['area']==='analistas'&&str_starts_with($ref,'out_')){
            $svc=new TelefoniaGrabacionesTransferenciaInstitucionService($this->db);
            if($svc->identidad($ref,$owner,$ext,$call['seguimiento_id']))return self::sanear($svc->resolver($ref,$owner,$ext,$call['seguimiento_id'],[$provider,'obtenerGrabacionPorCallId']));
        }
        $ids=[];$infos=[];
        if($call['session_id']!==''&&$call['clasificacion']==='INTERNA'&&$this->repo->tabla('telefonia_webrtc_grabaciones_internas')){
            $link=(new TelefoniaInternaGrabacionService($this->db))->obtener($owner,$call['session_id']);
            if($link)$ids[]=$link['call_id_with_rec'];
        }elseif($ref!==''&&preg_match('/^[0-9]{3,6}$/D',$ext)&&$call['conectada']){
            $events=$this->repo->eventos($ref);
            // Para entradas transferidas de analistas, exigir también la operación
            // de entrega y su ruta exacta antes de permitir el tramo de Recepción.
            if($call['area']==='analistas'&&$this->repo->tabla('telefonia_transferencia_operaciones')&&$this->repo->tabla('telefonia_disponibilidad')){
                $ops=$this->repo->rows("SELECT o.*,d.ruta_json FROM telefonia_transferencia_operaciones o JOIN telefonia_disponibilidad d
                    ON d.referencia=BINARY o.referencia AND d.origen_usuario=o.origen_usuario AND d.destino_usuario=o.destino_usuario
                    AND d.origen_extension=BINARY o.origen_extension AND d.destino_extension=BINARY o.destino_extension
                    WHERE o.referencia=BINARY ? AND o.destino_usuario=? AND o.destino_extension=BINARY ? AND o.estado='ENTREGADA'
                    AND d.estado IN ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA') LIMIT 2",[$ref,$owner,$ext]);
                if(count($ops)===1){
                    $o=$ops[0];$route=json_decode($o['ruta_json'],true)?:[];
                    if(($route['tipo']??'')==='INSTITUCION'&&(int)($route['responsable_id']??0)===$owner&&($route['extension']??'')===$ext&&($route['referencia_llamada']??'')===$ref){
                        $refs=TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($events,(string)$o['origen_extension'],$ext);
                        $ids=array_keys($refs);
                    }
                }
            }
            if(!$ids){
                $own=TelefoniaPbxSegmentosService::deEventos($events,$ext);
                $ids=$own['segmentos'];$infos=$own['segmentos_info'];
                if($call['area']==='asesores'){
                    $ids=$call['hechos']['segmentos']??$ids;$infos=$call['hechos']['segmentos_info']??$infos;
                }
                // Un único participante firmado permite identificar los avisos
                // de grabación sin extensión; nunca escoger el primer audio global.
                $participants=[];$records=[];
                foreach($events as $e){
                    $p=json_decode((string)($e['payload_json']??''),true)?:[];
                    foreach([$e['internal']??'',$p['last_internal']??'',$p['transfer_from']??''] as $member)
                        if(preg_match('/^[0-9]{3,6}$/D',(string)$member))$participants[(string)$member]=true;
                    if($e['evento']==='NOTIFY_RECORD'&&ZadarmaPbxIdentifierHelper::callIdValido((string)($e['call_id_with_rec']??'')))$records[(string)$e['call_id_with_rec']]=true;
                }
                if(count($participants)===1&&isset($participants[$ext])&&count($records)<=8){$ids=array_keys($records);$infos=[];}
            }
        }
        if(count($ids)>8)throw new DomainException('La identidad de los audios requiere revisión.');
        $records=[];$info=[];$temporary=false;
        foreach($ids as $i=>$id){
            try{$record=$provider->obtenerGrabacionPorCallId($id);if(!empty($record['link'])){$records[$i]=$record;$info[]=($infos[$i]??[])+['indice'=>$i,'etiqueta'=>'Parte '.($i+1).' de la llamada'];}}
            catch(Throwable $e){$temporary=true;}
        }
        return self::sanear(['ok'=>true,'disponible'=>(bool)$records,'segmentos'=>count($records),'segmentos_info'=>$info,'records'=>$records,
            'estado'=>$records?(count($records)<count($ids)?'DISPONIBLE_PARCIAL':'DISPONIBLE'):($temporary?'ERROR_TEMPORAL':($ids?'GRABACION_EN_PREPARACION':'TRAMO_PENDIENTE_VINCULACION'))]);
    }
    public static function sanear(array $response): array
    {
        $info=[];
        foreach($response['segmentos_info']??[] as $i=>$part){
            $index=(int)($part['indice']??$i);
            $label=(string)($part['etiqueta']??'');
            if($label===''||preg_match('/PBX|WebRTC|SDK/i',$label))$label='Parte '.($index+1).' de la llamada';
            $info[]=['indice'=>$index,'etiqueta'=>$label,'segundos'=>isset($part['segundos'])?(int)$part['segundos']:null];
        }
        return ['ok'=>true,'disponible'=>(bool)($response['disponible']??false),'segmentos'=>count($info),'segmentos_info'=>$info,
            'estado'=>(string)($response['estado']??'TRAMO_PENDIENTE_VINCULACION'),'records'=>$response['records']??[]];
    }
    /** Valida y recorta cuando el proveedor ignora Range; no acepta HTML. */
    public static function respuesta(array $audio,string $range=''): array
    {
        if($range!==''&&!preg_match('/^bytes=\d*-\d*$/D',$range))return ['status'=>416,'headers'=>[],'body'=>''];
        $body=(string)($audio['body']??'');$type=strtolower(trim((string)($audio['content_type']??'')));
        if(!preg_match('~^(audio/[a-z0-9.+-]+|application/octet-stream)(?:;[^\r\n]*)?$~D',$type))throw new RuntimeException('El archivo recibido no es audio.');
        if(!$body)throw new RuntimeException('El audio todavía no está disponible.');
        $headers=['Content-Type'=>$type,'Accept-Ranges'=>'bytes'];$status=200;
        if($range!==''){
            $provided=(string)($audio['headers']['content-range']??'');
            if((int)($audio['status']??200)===206){
                if(!preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/D',$provided,$p)||(int)$p[2]<(int)$p[1]||(int)$p[3]<=(int)$p[2]||strlen($body)!==(int)$p[2]-(int)$p[1]+1)throw new RuntimeException('Respuesta de audio incompleta.');
                $headers['Content-Range']=$provided;$status=206;
            }else{
                preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m);$size=strlen($body);
                $start=$m[1]===''?max(0,$size-(int)$m[2]):(int)$m[1];$end=$m[1]===''||$m[2]===''?$size-1:min($size-1,(int)$m[2]);
                if(($m[1]===''&&(int)$m[2]<=0)||$start>=$size||$end<$start)return ['status'=>416,'headers'=>['Content-Range'=>'bytes */'.$size],'body'=>''];
                $body=substr($body,$start,$end-$start+1);$status=206;$headers['Content-Range']="bytes $start-$end/$size";
            }
        }
        $headers['Content-Length']=(string)strlen($body);return ['status'=>$status,'headers'=>$headers,'body'=>$body];
    }
}
