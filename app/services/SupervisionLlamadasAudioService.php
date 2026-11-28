<?php
declare(strict_types=1);
require_once __DIR__.'/SupervisionLlamadasRepository.php';
require_once __DIR__.'/TelefoniaGrabacionesTransferenciaVentasService.php';
require_once __DIR__.'/TelefoniaGrabacionesTransferenciaInstitucionService.php';
require_once __DIR__.'/TelefoniaInternaGrabacionService.php';
require_once __DIR__.'/TelefoniaResultadoVentasService.php';
require_once __DIR__.'/SupervisionVentasAudioCdrPolicy.php';
require_once __DIR__.'/TelefoniaVentasGrabacionCdrService.php';

/** Transporte separado del teléfono personal. Nunca suplanta al titular. */
final class SupervisionLlamadasAudioService
{
    private SupervisionLlamadasRepository $repo;
    public function __construct(private mysqli $db){$this->repo=new SupervisionLlamadasRepository($db);}
    public function resolver(int $uid,string $key,object $provider,bool $verify=false): array
    {
        $call=$this->repo->obtener($uid,$key,true);
        $ref=$call['pbx_call_id'];$ext=$call['extension'];$owner=$call['usuario_id'];
        // Este punto ocurre DESPUÉS de obtener(..., true): el alcance vigente
        // del supervisor y la pertenencia de la llamada están comprobados.
        // Nunca usar el endpoint personal del asesor en nombre del supervisor.
        if(SupervisionVentasAudioCdrPolicy::candidata($call))
            return self::resolverCdrPendiente($call,$provider);
        // Un envío no anula un resultado sin contacto, ni habilita resultados pendientes.
        if($call['area']==='asesores'&&!TelefoniaVentasLlamadasService::audioConsultable($call))throw new DomainException('La grabación no está habilitada para el resultado y estado registrados en esta llamada.');
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
            try{$record=$provider->obtenerGrabacionPorCallId($id);if(!empty($record['link'])){$records[$i]=$record;$info[]=array_replace($infos[$i]??[],['indice'=>$i,'referencia'=>(string)$id])+['etiqueta'=>'Parte '.($i+1).' de la llamada'];}}
            catch(Throwable $e){$temporary=true;}
        }
        return self::sanear(['ok'=>true,'disponible'=>(bool)$records,'segmentos'=>count($records),'segmentos_info'=>$info,'records'=>$records,
            'estado'=>$records?(count($records)<count($ids)?'DISPONIBLE_PARCIAL':'DISPONIBLE'):($temporary?'ERROR_TEMPORAL':($ids?'GRABACION_EN_PREPARACION':'TRAMO_PENDIENTE_VINCULACION'))]);
    }
    /**
     * Solo saliente PROPIA sin OUT_END, confirmada en CDR Zadarma de esa PBX,
     * extensión y horario. No modifica eventos/duración almacenados ni audio.
     * Los tests pueden inyectar estadísticas para no contactar al proveedor.
     */
    public static function resolverCdrPendiente(
        array $call,
        object $provider,
        ?callable $stats=null,
        ?DateTimeImmutable $now=null
    ): array {
        if(!SupervisionVentasAudioCdrPolicy::candidata($call))
            throw new DomainException('La llamada no cumple los requisitos de grabación CDR propia.');
        $cdr=TelefoniaVentasGrabacionCdrService::resolver(
            SupervisionVentasAudioCdrPolicy::paraVerificar($call),$stats,$now);
        if(!$cdr)return self::sanear([
            'disponible'=>false,'segmentos_info'=>[],'records'=>[],
            'estado'=>'TRAMO_PENDIENTE_VINCULACION']);
        try {
            $record=$provider->obtenerGrabacionPorCallId($cdr['call_id']);
        } catch(Throwable $error) {
            return self::sanear([
                'disponible'=>false,'segmentos_info'=>[],'records'=>[],
                'estado'=>'ERROR_TEMPORAL']);
        }
        if(!is_array($record)||empty($record['link']))return self::sanear([
            'disponible'=>false,'segmentos_info'=>[],'records'=>[],
            'estado'=>'GRABACION_EN_PREPARACION']);
        return self::sanear([
            'disponible'=>true,'segmentos_info'=>[[
                'indice'=>0,'referencia'=>(string)$cdr['call_id'],'etiqueta'=>'Grabación de la llamada',
                'segundos'=>(int)$cdr['seconds']]],
            'records'=>[0=>$record],'estado'=>'DISPONIBLE']);
    }

    public static function sanear(array $response): array
    {
        $info=[];
        foreach($response['segmentos_info']??[] as $i=>$part){
            $index=(int)($part['indice']??$i);
            $label=(string)($part['etiqueta']??'');
            if($label===''||preg_match('/PBX|WebRTC|SDK/i',$label))$label='Parte '.($index+1).' de la llamada';
            $info[]=['indice'=>$index,'etiqueta'=>$label,'segundos'=>isset($part['segundos'])?(int)$part['segundos']:null];
            if(is_string($part['referencia']??null)&&ZadarmaPbxIdentifierHelper::callIdValido($part['referencia']))
                $info[array_key_last($info)]['referencia']=$part['referencia'];
        }
        return ['ok'=>true,'disponible'=>(bool)($response['disponible']??false),'segmentos'=>count($info),'segmentos_info'=>$info,
            'estado'=>(string)($response['estado']??'TRAMO_PENDIENTE_VINCULACION'),'records'=>$response['records']??[]];
    }
    /** Compatibilidad con consumidores existentes; transporte común. */
    public static function respuesta(array $audio,string $range=''): array
    {
        require_once __DIR__.'/TelefoniaAudioRespuesta.php';
        return TelefoniaAudioRespuesta::respuesta($audio,$range);
    }
}
