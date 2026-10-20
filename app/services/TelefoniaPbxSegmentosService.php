<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';
/** Evidencia por segmento de la estadística oficial. No depende de roles o números. */
final class TelefoniaPbxSegmentosService
{
    public static function deEventos(array $events,string $ext): array {
        $owned=[];$ready=[];$foreign=[];$reported=false;
        foreach($events as $e){
            $type=(string)($e['evento']??'');$internal=trim((string)($e['internal']??''));$id=trim((string)($e['call_id_with_rec']??''));
            $recorded=in_array(strtolower((string)($e['is_recorded']??'')),['1','true'],true);
            if($internal===$ext&&$recorded)$reported=true;
            if(!ZadarmaPbxIdentifierHelper::callIdValido($id))continue;
            if(!in_array($type,['NOTIFY_RECORD','NOTIFY_END','NOTIFY_OUT_END'],true))continue;
            if($internal!==''&&$internal!==$ext){$foreign[$id]=true;continue;}
            if($type==='NOTIFY_RECORD')$ready[$id]=true;
            if($internal===$ext){
                $owned[$id]=($owned[$id]??false)||$type==='NOTIFY_RECORD'||$recorded;
            }
        }
        $ids=[];$ambiguous=0;
        foreach($owned as $id=>$confirmed){
            if(isset($foreign[$id])){$ambiguous++;continue;}
            if($confirmed||isset($ready[$id]))$ids[]=(string)$id;
        }
        $overflow=count($ids)>8;
        $reason=$overflow?'limite':($ids?'referencia_propia':($ambiguous?'compartida':($ready?'sin_tramo':($reported?'pendiente_referencia':'sin_referencia'))));
        return ['segmentos'=>$overflow?[]:$ids,'audio_motivo'=>$reason,
            'audio_evidencia'=>['referencias_propias'=>count($owned),'avisos_disponibilidad'=>count($ready),'referencias_ambiguas'=>$ambiguous]];
    }
    public static function propios(array $rows,string $pbx,string $extension): array
    {
        $segments=[];$foreign=[];$invalid=[];
        foreach($rows as $row){
            if(!is_array($row)||!hash_equals($pbx,(string)($row['pbx_call_id']??'')))continue;
            $id=ZadarmaPbxIdentifierHelper::callIdDeFila($row);if($id==='')continue;
            if(trim((string)($row['sip']??''))!==$extension){$foreign[$id]=true;continue;}
            if(strtolower((string)($row['disposition']??''))!=='answered')continue;
            $seconds=filter_var($row['seconds']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>86400]]);
            if($seconds===false)continue;
            $segment=['id'=>$id,'segundos'=>$seconds,'grabada'=>filter_var($row['is_recorded']??false,FILTER_VALIDATE_BOOLEAN)];
            // Una referencia contradictoria nunca se resuelve escogiendo la mayor duración.
            if(isset($segments[$id])&&$segments[$id]!==$segment)$invalid[$id]=true;
            $segments[$id]=$segment;
        }
        foreach($foreign+$invalid as $id=>$unused)unset($segments[$id]);
        // Sin intervalos de conversación inequívocos, no sumar tramos potencialmente solapados.
        return ['tramos'=>array_values($segments),'segundos'=>count($segments)===1?reset($segments)['segundos']:null,'rechazados'=>array_keys($foreign+$invalid)];
    }
}
