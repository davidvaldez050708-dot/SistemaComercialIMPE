<?php
declare(strict_types=1);

/**
 * Recuperación privada de un SOLO tramo grabado de una saliente propia, sin
 * NOTIFY_OUT_END. No convierte un CDR en evento ni modifica la base de datos.
 * La autorización del usuario y propiedad (uid, extensión, PBX) se realiza
 * ANTES de llamar a resolver() mediante TelefoniaVentasLlamadasService::obtener.
 */
final class TelefoniaVentasGrabacionCdrService
{
    public static function resolver(
        array $call,
        ?callable $estadisticas = null,
        ?DateTimeImmutable $ahora = null
    ): ?array {
        require_once __DIR__.'/TelefoniaResultadoVentasService.php';
        require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';

        $resultado=(string)($call['registro']['resultado']??'');
        if (($call['direccion']??'')!=='Saliente' ||
            !empty($call['finalizada']) || empty($call['conectada']) ||
            $resultado==='' || !TelefoniaResultadoVentasService::esConversacion($resultado) ||
            !empty($call['origen_extension'])) return null;

        $ref=trim((string)($call['pbx_call_id']??''));
        $ext=trim((string)($call['extension']??''));
        $fecha=trim((string)($call['fecha']??''));
        $owner=(int)($call['usuario_id']??0);
        if ($owner<=0 || !preg_match('/^out_[A-Za-z0-9_-]{6,100}$/D',$ref) ||
            !preg_match('/^\\d{3,6}$/D',$ext) ||
            !preg_match('/^\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d$/D',$fecha)) return null;

        $tz=new DateTimeZone('America/Mexico_City');
        $inicio=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$fecha,$tz);
        $ahora=($ahora??new DateTimeImmutable('now',$tz))->setTimezone($tz);
        if (!$inicio || $inicio->format('Y-m-d H:i:s')!==$fecha ||
            $inicio>$ahora->modify('-45 seconds') ||
            $inicio<$ahora->modify('-89 days')) return null;

        $desde=$inicio->modify('-5 minutes');
        $hasta=$inicio->modify('+4 hours');
        if($hasta>$ahora)$hasta=$ahora;
        if($hasta<=$desde)return null;
        if($estadisticas===null){
            require_once __DIR__.'/ZadarmaCallLookupService.php';
            $api=new ZadarmaCallLookupService();
            $estadisticas=static function(string $de,string $a)use($api):array{
                return $api->estadisticasPbx($de,$a);
            };
        }
        $filas=$estadisticas($desde->format('Y-m-d H:i:s'),
                             $hasta->format('Y-m-d H:i:s'));
        if(!is_array($filas))return null;
        // No elegir último/audio por teléfono ni por fecha próxima:
        // CDR y extensión de esta llamada exacta, sin segmentos ambiguos.
        $propios=[];
        foreach($filas as $fila){
            if(!is_array($fila) ||
               !hash_equals($ref,trim((string)($fila['pbx_call_id']??''))))continue;
            if(hash_equals($ext,trim((string)($fila['sip']??''))))$propios[]=$fila;
            if(count($propios)>1)return null;
        }
        if(count($propios)!==1)return null;
        $row=$propios[0];
        if(strtolower(trim((string)($row['disposition']??'')))!=='answered' ||
           !filter_var($row['is_recorded']??false,FILTER_VALIDATE_BOOLEAN))return null;
        $seconds=$row['seconds']??null;
        if(!is_int($seconds) && (!is_string($seconds)||!ctype_digit($seconds)))return null;
        if((int)$seconds<1||(int)$seconds>14400)return null;

        $when=(string)($row['callstart']??'');
        if(!preg_match('/^\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d$/D',$when))return null;
        $from=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$when,$tz);
        if(!$from || $from->format('Y-m-d H:i:s')!==$when ||
           abs($inicio->getTimestamp()-$from->getTimestamp())>300 ||
           $from->getTimestamp()+(int)$seconds+15>$ahora->getTimestamp())return null;

        // IMPORTANTE: los decimales de Zadarma son IDs opacos; nunca formar
        // call_id a partir de un float convertido por json_decode().
        $callId=ZadarmaPbxIdentifierHelper::callIdDeFila($row);
        if($callId==='')return null;
        return [
            'call_id'=>$callId,
            'seconds'=>(int)$seconds,
            'extension'=>$ext,
            'pbx_call_id'=>$ref,
        ];
    }
}
