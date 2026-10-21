<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';
/** Evidencia por segmento de la estadística oficial. No depende de roles o números. */
final class TelefoniaPbxSegmentosService
{
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
            if(in_array($type,['NOTIFY_END','NOTIFY_OUT_END'],true)&&((string)($e['internal']??'')===$ext||$last===$ext||($last===''&&$source!==''&&$type==='NOTIFY_OUT_END'&&(string)($e['internal']??'')===$source)))$end=true;
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
    public static function propios(array $rows,string $pbx,string $extension,array $autorizadas=[]): array
    {
        $segments=[];$foreign=[];$invalid=[];
        foreach($rows as $row){
            if(!is_array($row)||!hash_equals($pbx,(string)($row['pbx_call_id']??'')))continue;
            $id=ZadarmaPbxIdentifierHelper::callIdDeFila($row);if($id==='')continue;
            $owner=trim((string)($row['sip']??''));
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
