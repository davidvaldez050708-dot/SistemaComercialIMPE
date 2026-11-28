<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';
/** Evidencia por segmento de la estadística oficial. No depende de roles o números. */
final class TelefoniaPbxSegmentosService
{
    /** Hechos del tramo: comunes a todos los roles, separados del resultado comercial.
     * El marcador pertenece a una operación persistida, no al reloj del navegador.
     * Conservamos START reales anteriores sólo como contexto; un END anterior
     * al marcador nunca puede finalizar la operación nueva.
     */
    public static function hechos(array $events, string $ext, string $source = '', int $afterEventId = 0): array
    {
        $allEvents = $events;
        // Una devolución explícita abre otro tramo dentro del mismo grupo PBX.
        // Su respuesta/cierre no puede heredarse del tramo transferido anterior.
        $sentTo='';$returnAt=null;
        foreach($events as $position=>$event){
            $payload=json_decode((string)($event['payload_json']??''),true)?:[];
            $internal=(string)($event['internal']??'');$type=$event['evento']??'';
            $from=(string)($event['transfer_from']??$payload['transfer_from']??'');
            if($from===''&&!empty($payload['transfer_from']))$from=(string)$payload['transfer_from'];
            if(in_array($type,['NOTIFY_INTERNAL','NOTIFY_ANSWER'],true)&&$internal!==$ext&&$from===$ext&&preg_match('/^[0-9]{3,6}$/D',$internal))$sentTo=$internal;
            if($type==='NOTIFY_INTERNAL'&&$internal===$ext&&$sentTo!==''&&$from===$sentTo){$returnAt=$position;$sentTo='';}
        }
        if($returnAt!==null){
            $context=$source!==''?array_values(array_filter(array_slice($events,0,$returnAt),static fn($e)=>
                (string)($e['internal']??'')===$source&&in_array($e['evento']??'',['NOTIFY_INTERNAL','NOTIFY_OUT_START'],true))):[];
            $events=array_merge($context,array_slice($events,$returnAt));
        }

        if ($afterEventId > 0) {
            $events = array_values(array_filter($events, static function ($event) use ($afterEventId, $source) {
                return (int)($event['id'] ?? 0) > $afterEventId ||
                    ($source !== '' && (string)($event['internal'] ?? '') === $source &&
                        in_array($event['evento'] ?? '', ['NOTIFY_INTERNAL', 'NOTIFY_OUT_START'], true));
            }));
        }
        $answer = false;
        $end = false;
        $duration = null;
        $disposition = '';
        $answerAt = '';
        $endAt = '';
        $endId = 0;
        $sourceSeen = false;
        $ownAnswerSeen = false;
        $offered = false;
        $attemptEnded = false;
        $attemptEndAt = '';
        foreach ($events as $event) {
            $type = (string)($event['evento'] ?? '');
            $internal = trim((string)($event['internal'] ?? ''));
            if ($source !== '' && $source !== $ext && $internal === $source &&
                in_array($type, ['NOTIFY_INTERNAL', 'NOTIFY_OUT_START'], true)) $sourceSeen = true;
            if ($type === 'NOTIFY_ANSWER' && $internal === $ext) $ownAnswerSeen = true;
            $payload = json_decode((string)($event['payload_json'] ?? ''), true) ?: [];
            if ($afterEventId > 0 && (int)($event['id'] ?? 0) > $afterEventId &&
                $type === 'NOTIFY_INTERNAL' && $internal === $ext &&
                (string)($event['transfer_from'] ?? $payload['transfer_from'] ?? '') === $source) $offered = true;
        }
        $started = false;
        $answeredAfterSource = false;
        foreach ($events as $event) {
            $type = (string)($event['evento'] ?? '');
            $internal = trim((string)($event['internal'] ?? ''));
            $payload = json_decode((string)($event['payload_json'] ?? ''), true) ?: [];
            $date = self::fechaEvento((string)($event['received_at'] ?? ''));
            if ($source !== '' && $source !== $ext && $internal === $source &&
                in_array($type, ['NOTIFY_INTERNAL', 'NOTIFY_OUT_START'], true)) $started = true;
            $own = $internal === $ext;
            if ($own && $type === 'NOTIFY_ANSWER') {
                $answer = true;
                if ($started) $answeredAfterSource = true;
                if ($date !== '' && ($answerAt === '' || $date < $answerAt)) $answerAt = $date;
            }
            $last = trim((string)($event['last_internal'] ?? $payload['last_internal'] ?? ''));
            if ($last === '') $last = trim((string)($payload['last_internal'] ?? ''));
            // En una operación persistida, ANSWER puede llegar después del END.
            // Los dos deben pertenecer al mismo tramo posterior al marcador.
            $boundAnswer = $afterEventId > 0 && $sourceSeen && $ownAnswerSeen;
            $sourceEnd = $source !== '' && $source !== $ext && $internal === $source &&
                $type === 'NOTIFY_OUT_END' && in_array($last, ['', $source], true) &&
                (($started && $answeredAfterSource) || $boundAnswer);
            $anonymousOwn = $internal === '' && $last === '' && $type === 'NOTIFY_OUT_END' &&
                self::cierreSalienteSinExtension($event,$allEvents,$ext);
            // Un intento ofrecido puede terminar en el origen sin ANSWER del destino.
            // Esto libera la operación; no acredita conversación, duración ni entrega.
            if ($afterEventId > 0 && $sourceSeen && $offered && $internal === $source &&
                in_array($type, ['NOTIFY_END', 'NOTIFY_OUT_END'], true) &&
                in_array($last, ['', $source, $ext], true)) {
                $attemptEnded = true;
                if ($date !== '' && ($attemptEndAt === '' || $date > $attemptEndAt)) $attemptEndAt = $date;
            }
            if (!in_array($type, ['NOTIFY_END', 'NOTIFY_OUT_END'], true) ||
                !($own || $last === $ext || $sourceEnd || $anonymousOwn)) continue;
            $end = true;
            $disposition = strtolower(trim((string)($event['disposition'] ?? '')));
            if ($date !== '' && ($endAt === '' || $date > $endAt)) $endAt = $date;
            $endId = max($endId, (int)($event['id'] ?? 0));
            if ($own) {
                $known = array_key_exists('has_duration', $event) ? (bool)$event['has_duration'] :
                    ($payload ? array_key_exists('duration', $payload) : array_key_exists('duration', $event));
                if ($known) $duration = max($duration ?? 0, max(0, (int)($event['duration'] ?? 0)));
                if (in_array($disposition, ['answered', 'completed'], true)) $answer = true;
            }
            // PBX/inicio/único participante acreditan el cierre. La duración
            // anónima y su grabación siguen sin atribuirse a un tramo propio.
            if($anonymousOwn && in_array($disposition,['answered','completed'],true))$answer=true;
        }
        $transfer = self::transferencia($events, $ext, $source);
        $allowed = $transfer['atendida'] && $transfer['finalizada'] && $transfer['origen'] !== '' ? [$transfer['origen']] : [];
        $audio = self::deEventos($allEvents, $ext, $allowed);
        return [
            'finalizada' => $end, 'conectada' => $answer, 'segundos' => $end ? $duration : null,
            'answer_at' => $answerAt, 'end_at' => $endAt, 'evento_fin_id' => $endId,
            'disposition' => $disposition,
            'intento_finalizado' => $attemptEnded, 'fin_intento_at' => $attemptEndAt,
            'estado_pbx' => !$end ? ($attemptEnded ? 'Respuesta del destino sin confirmar' : ($answer ? 'Respuesta confirmada · Cierre pendiente' : 'Cierre pendiente')) :
                ($answer ? 'Conectada' : (['busy' => 'Ocupada', 'no answer' => 'Sin respuesta',
                    'no-answer' => 'Sin respuesta', 'no_answer' => 'Sin respuesta', 'cancel' => 'Cancelada',
                    'canceled' => 'Cancelada', 'cancelled' => 'Cancelada', 'failed' => 'Fallida'][$disposition] ?? 'Sin respuesta')),
            'audio_extensiones' => $allowed
        ] + $audio;
    }

    private static function cierreSalienteSinExtension(array $end,array $events,string $ext): bool
    {
        $payload=json_decode((string)($end['payload_json']??''),true)?:[];
        $ref=trim((string)($end['pbx_call_id']??$payload['pbx_call_id']??''));
        $start=trim((string)($end['call_start']??$payload['call_start']??''));
        if($ref==='' || $start==='')return false;
        $starts=0;
        foreach($events as $event){
            $p=json_decode((string)($event['payload_json']??''),true)?:[];
            if((string)($event['pbx_call_id']??$p['pbx_call_id']??'')!==$ref)continue;
            if(!in_array($event['evento']??'',['NOTIFY_OUT_START','NOTIFY_INTERNAL','NOTIFY_ANSWER','NOTIFY_END','NOTIFY_OUT_END'],true))continue;
            $internal=trim((string)($event['internal']??''));
            $from=trim((string)($event['transfer_from']??$p['transfer_from']??''));
            $last=trim((string)($event['last_internal']??$p['last_internal']??''));
            foreach([$internal,$from,$last] as $participant)if($participant!==''&&$participant!==$ext)return false;
            if(($event['evento']??'')==='NOTIFY_OUT_START'){
                if($internal!==$ext || (string)($event['call_start']??$p['call_start']??'')!==$start)return false;
                ++$starts;
            }
        }
        return $starts===1;
    }

    private static function fechaEvento(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) return '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('America/Mexico_City'));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $value : '';
    }

    /** Arista de transferencia del mismo grupo PBX, nunca teléfono/horario.
     * El origen persistido sólo complementa una respuesta propia y su inicio PBX. */
    public static function transferencia(array $events,string $ext,string $persistedSource=''): array {
        $source='';$starts=[];$answer=null;$end=false;$lastOwn=false;$position=0;$answerPosition=0;
        foreach($events as $e){
            ++$position;
            $p=json_decode((string)($e['payload_json']??''),true)?:[];
            $internal=(string)($e['internal']??'');$type=$e['evento']??'';
            if(in_array($type,['NOTIFY_INTERNAL','NOTIFY_OUT_START'],true))$starts[$internal]=$type;
            if($type==='NOTIFY_ANSWER'&&$internal===$ext){$answer=$e;$answerPosition=$position;}
            if(in_array($type,['NOTIFY_INTERNAL','NOTIFY_ANSWER'],true)&&$internal===$ext){$from=trim((string)($e['transfer_from']??''));if($from==='')$from=trim((string)($p['transfer_from']??''));if(preg_match('/^[0-9]{3,6}$/D',$from)&&$from!==$ext)$source=$from;}
            $last=trim((string)($e['last_internal']??''));if($last==='')$last=trim((string)($p['last_internal']??''));
            if(in_array($type,['NOTIFY_END','NOTIFY_OUT_END'],true)&&$last===$ext)$lastOwn=true;
        }
        $out=array_keys(array_filter($starts,static fn($t)=>$t==='NOTIFY_OUT_START'));
        if($source===''&&$answer&&$lastOwn&&count($out)===1&&(string)$out[0]!==$ext)$source=(string)$out[0];
        if($source===''&&$answer&&$persistedSource!==$ext&&($starts[$persistedSource]??'')==='NOTIFY_OUT_START')$source=$persistedSource;
        if(!isset($starts[$source])||!$answer)$source='';
        $position=0;foreach($events as $e){
            if(++$position<=$answerPosition)continue;
            $p=json_decode((string)($e['payload_json']??''),true)?:[];$type=$e['evento']??'';
            $last=trim((string)($e['last_internal']??''));if($last==='')$last=trim((string)($p['last_internal']??''));
            if(in_array($type,['NOTIFY_END','NOTIFY_OUT_END'],true)&&((string)($e['internal']??'')===$ext||$last===$ext||(in_array($last,['',$source],true)&&$source!==''&&$type==='NOTIFY_OUT_END'&&(string)($e['internal']??'')===$source)))$end=true;
        }
        return ['origen'=>$source,'atendida'=>$answer!==null,'answer_at'=>(string)($answer['received_at']??''),'finalizada'=>$end];
    }
    public static function deEventos(array $events,string $ext,array $autorizadas=[]): array {
        $owned=[];$ready=[];$reported=false;$owners=[];$levels=[];
        $allowed=array_fill_keys(array_merge([$ext],$autorizadas),true);
        foreach($events as $e){
            $type=(string)($e['evento']??'');$internal=trim((string)($e['internal']??''));$id=trim((string)($e['call_id_with_rec']??''));
            $recorded=in_array(strtolower((string)($e['is_recorded']??'')),['1','true'],true);
            if($internal===$ext&&$recorded)$reported=true;
            if(!ZadarmaPbxIdentifierHelper::callIdValido($id))continue;
            if($type==='NOTIFY_RECORD')$ready[$id]=true;
            $payload=json_decode((string)($e['payload_json']??''),true)?:[];
            $level=$type==='NOTIFY_RECORD'?3:(in_array($type,['NOTIFY_ANSWER','NOTIFY_INTERNAL'],true)?2:(in_array($type,['NOTIFY_END','NOTIFY_OUT_END','NOTIFY_OUT_START'],true)?1:0));
            if(!$level)continue;
            if(in_array($type,['NOTIFY_END','NOTIFY_OUT_END'],true)){$last=trim((string)($e['last_internal']??''));if($last==='')$last=trim((string)($payload['last_internal']??''));if($last!=='')$internal=$last;}
            if($internal!==''){
                if($level>($levels[$id]??0)){$owners[$id]=[];$levels[$id]=$level;}
                if($level===($levels[$id]??0))$owners[$id][$internal]=true;
            }
            if($recorded)$ready[$id]=true;
        }
        $ids=[];$ambiguous=0;$info=[];$denied=[];
        foreach($owners as $id=>$participants){
            if(array_diff_key($participants,$allowed))$denied[]=(string)$id;
            if(!array_intersect_key($participants,$allowed))continue;
            $owned[$id]=true;
            if(array_diff_key($participants,$allowed)){$ambiguous++;continue;}
            if(isset($ready[$id])){$ids[]=(string)$id;$owner=count($participants)===1?(string)array_key_first($participants):'';$info[]=['extension'=>$owner,'etiqueta'=>$owner===$ext?'Extensión '.$ext:($owner!==''?'Recepción · Ext. '.$owner:'Tramo compartido')];}
        }
        $overflow=count($ids)>8;
        $reason=$overflow?'limite':($ids?'referencia_propia':($ambiguous?'compartida':($ready?'sin_tramo':($reported?'pendiente_referencia':'sin_referencia'))));
        return ['segmentos'=>$overflow?[]:$ids,'segmentos_info'=>$overflow?[]:$info,'audio_bloqueadas'=>$denied,'audio_motivo'=>$reason,
            'audio_evidencia'=>['referencias_propias'=>count($owned),'avisos_disponibilidad'=>count($ready),'referencias_ambiguas'=>$ambiguous]];
    }
    /** En la estadística de un tramo entrante transferido, la extensión puede
     * estar en destination y el origen ser el teléfono externo. Dos extensiones
     * distintas en la misma fila no identifican una conversación propia. */
    public static function extensionDeFila(array $row): string
    {
        $source=trim((string)($row['sip']??''));
        $destination=trim((string)($row['destination']??''));
        $source=preg_match('/^[0-9]{3,6}$/D',$source)?$source:'';
        $destination=preg_match('/^[0-9]{3,6}$/D',$destination)?$destination:'';
        if($source!==''&&$destination!==''&&$source!==$destination)return '';
        return $source!==''?$source:$destination;
    }
    public static function propios(array $rows,string $pbx,string $extension,array $autorizadas=[]): array
    {
        $segments=[];$foreign=[];$invalid=[];
        foreach($rows as $row){
            if(!is_array($row)||!hash_equals($pbx,(string)($row['pbx_call_id']??'')))continue;
            $id=ZadarmaPbxIdentifierHelper::callIdDeFila($row);if($id==='')continue;
            $owner=self::extensionDeFila($row);
            if(!in_array($owner,array_merge([$extension],$autorizadas),true)){$foreign[$id]=true;continue;}
            if(strtolower((string)($row['disposition']??''))!=='answered')continue;
            $seconds=filter_var($row['seconds']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>86400]]);
            if($seconds===false)continue;
            $segment=['id'=>$id,'segundos'=>$seconds,'grabada'=>filter_var($row['is_recorded']??false,FILTER_VALIDATE_BOOLEAN),'extension'=>$owner,'inicio'=>(string)($row['callstart']??'')];
            // Una referencia contradictoria nunca se resuelve escogiendo la mayor duración.
            if(isset($segments[$id])&&$segments[$id]!==$segment)$invalid[$id]=true;
            $segments[$id]=$segment;
        }
        foreach($foreign+$invalid as $id=>$unused)unset($segments[$id]);
        uasort($segments,static fn($a,$b)=>strcmp($a['inicio'],$b['inicio']));
        // Sin intervalos de conversación inequívocos, no sumar tramos potencialmente solapados.
        $own=array_filter($segments,static fn($s)=>$s['extension']===$extension);
        return ['tramos'=>array_values($segments),'segundos'=>count($own)===1?reset($own)['segundos']:null,'rechazados'=>array_keys($foreign+$invalid)];
    }
}
