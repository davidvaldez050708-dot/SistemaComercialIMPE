<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
require_once __DIR__.'/TelefoniaGrabacionesTransferenciaInstitucionService.php';
require_once __DIR__.'/TelefoniaLimpiezaGuard.php';

/** Sólo lectura. Recibe una interacción cuyo expediente YA autorizó el endpoint.
 * Usa su titular histórico, nunca la extensión actual del visitante/supervisor. */
final class TelefoniaGrabacionInteraccionService
{
    public function __construct(private mysqli $db){}

    public static function extensionHistorica(array $events,array $bindings): string
    {
        $extensions=[];
        foreach($bindings as $binding){
            $ext=(string)($binding['extension']??'');
            if(!preg_match('/^[0-9]{3,6}$/D',$ext))throw new DomainException('PARTICIPACION_PENDIENTE',409);
            if(isset($binding['pbx_inicio_evento'])){
                $proof=false;
                foreach($events as $event)if((int)$event['id']===(int)$binding['pbx_inicio_evento']&&
                    (string)$event['internal']===$ext&&in_array($event['evento'],['NOTIFY_OUT_START','NOTIFY_INTERNAL','NOTIFY_ANSWER'],true))$proof=true;
                if(!$proof)throw new DomainException('PARTICIPACION_PENDIENTE',409);
            }
            $extensions[$ext]=true;
        }
        if(count($extensions)>1)throw new DomainException('PARTICIPACION_AMBIGUA',409);
        return $extensions?(string)array_key_first($extensions):'';
    }

    public static function referenciasPropias(array $events,string $extension): array
    {
        $participants=[];$records=[];$unknown=false;
        foreach($events as $event){
            $payload=json_decode((string)($event['payload_json']??''),true)?:[];
            foreach([$event['internal']??'',$event['last_internal']??$payload['last_internal']??'',$payload['transfer_from']??''] as $member){
                $member=trim((string)$member);if($member==='')continue;
                if(!preg_match('/^[0-9]{3,6}$/D',$member))$unknown=true;
                else $participants[$member]=true;
            }
            $id=(string)($event['call_id_with_rec']??'');
            if(($event['evento']??'')==='NOTIFY_RECORD'&&ZadarmaPbxIdentifierHelper::callIdValido($id))$records[$id]=true;
        }
        // Compatibilidad histórica: una referencia nativa y un único participante
        // firmado permiten los RECORD sin extensión. Nunca usar teléfono/horario.
        $single=!$unknown&&count($participants)===1;
        if($extension===''){
            if(!$single)throw new DomainException('PARTICIPACION_PENDIENTE',409);
            $extension=(string)array_key_first($participants);
        }
        $own=TelefoniaPbxSegmentosService::deEventos($events,$extension);
        $ids=$single&&isset($participants[$extension])?array_map('strval',array_keys($records)):$own['segmentos'];
        if(count($ids)>8)throw new DomainException('CATALOGO_REQUIERE_REVISION',409);
        return ['extension'=>$extension,'ids'=>$ids];
    }

    public function resolver(array $interaction): array
    {
        $ref=(string)$interaction['id_externo'];$owner=(int)$interaction['usuario_id'];
        $iid=(int)$interaction['id'];$sid=(int)$interaction['seguimiento_id'];
        if($owner<=0||!ZadarmaPbxIdentifierHelper::pbxValido($ref)||!preg_match('/^(?:out|in)_/D',$ref))
            throw new DomainException('REFERENCIA_PENDIENTE',409);
        $q=$this->db->prepare('SELECT id,evento,internal,call_id_with_rec,payload_json,is_recorded FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? ORDER BY id');
        $q->bind_param('s',$ref);$q->execute();$events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $q=$this->db->prepare('SELECT extension,pbx_call_id FROM telefonia_analista_entrantes WHERE usuario_id=? AND seguimiento_id=? AND interaccion_id=? LIMIT 3');
        $q->bind_param('iii',$owner,$sid,$iid);$q->execute();$bindings=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if(count($bindings)>=3)throw new DomainException('PARTICIPACION_AMBIGUA',409);
        foreach($bindings as $binding)if(!hash_equals($ref,(string)$binding['pbx_call_id'])&&!str_starts_with((string)$binding['pbx_call_id'],'sdk-'))
            throw new DomainException('REFERENCIAS_CONTRADICTORIAS',409);
        $q=$this->db->prepare('SELECT extension,pbx_inicio_evento FROM telefonia_analista_webrtc_sesiones WHERE usuario_id=? AND protocolo=2 AND pbx_call_id=BINARY ? AND pbx_inicio_evento>0 LIMIT 9');
        $q->bind_param('is',$owner,$ref);$q->execute();$journal=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if(count($journal)>=9)throw new DomainException('PARTICIPACION_AMBIGUA',409);
        $extension=self::extensionHistorica($events,array_merge($bindings,$journal));
        $institution=new TelefoniaGrabacionesTransferenciaInstitucionService($this->db);
        if($extension!==''&&$institution->identidad($ref,$owner,$extension,$sid))
            $result=['extension'=>$extension,'ids'=>$institution->verificar($ref,$owner,$extension,$sid)['ids']];
        else $result=self::referenciasPropias($events,$extension);
        if(TelefoniaLimpiezaGuard::bloqueada($this->db,$ref,$result['extension']))throw new DomainException('AUDIO_NO_DISPONIBLE',404);
        return $result;
    }
}
