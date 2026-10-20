<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';
/** Evidencia por segmento de la estadística oficial. No depende de roles o números. */
final class TelefoniaPbxSegmentosService
{
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
