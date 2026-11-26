<?php
declare(strict_types=1);

/**
 * Verificación auxiliar del cierre de una saliente propia cuando NO llegó
 * NOTIFY_OUT_END. Únicamente admite estadísticas finales del proveedor,
 * con coincidencia exacta de PBX, extensión y hora de inicio.
 *
 * No actualiza eventos PBX, duración, grabaciones ni llamadas. El resultado
 * humano se guarda después en el servicio comercial con su índice único.
 */
final class TelefoniaVentasCierreApiVerificado
{
    public static function verificar(array $call, ?callable $estadisticas=null, ?DateTimeImmutable $ahora=null): bool
    {
        if (($call['direccion']??'')!=='Saliente' || !empty($call['finalizada'])
            || !empty($call['registro'])) return false;

        $ref=trim((string)($call['pbx_call_id']??''));
        $ext=trim((string)($call['extension']??''));
        $fecha=(string)($call['fecha']??'');
        if (!preg_match('/^out_[A-Za-z0-9_-]{6,100}$/D',$ref)
            || !preg_match('/^\d{3,6}$/D',$ext)
            || !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$fecha)) return false;

        $tz=new DateTimeZone('America/Mexico_City');
        $inicio=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$fecha,$tz);
        if (!$inicio || $inicio->format('Y-m-d H:i:s')!==$fecha) return false;
        $ahora=($ahora??new DateTimeImmutable('now',$tz))->setTimezone($tz);
        // La estadística no se debe usar para autorizar llamadas aún activas,
        // futuras o tan antiguas que el proveedor ya no pueda corroborarlas.
        if ($inicio>$ahora->modify('-45 seconds')
            || $inicio<$ahora->modify('-89 days')) return false;

        $desde=$inicio->modify('-5 minutes')->format('Y-m-d H:i:s');
        $hasta=$inicio->modify('+4 hours');
        if ($hasta>$ahora) $hasta=$ahora;
        if ($estadisticas===null) {
            require_once __DIR__.'/ZadarmaCallLookupService.php';
            $api=new ZadarmaCallLookupService();
            $estadisticas=static fn(string $de,string $a): array=>$api->estadisticasPbx($de,$a);
        }
        $filas=$estadisticas($desde,$hasta->format('Y-m-d H:i:s'));
        if (!is_array($filas)) return false;

        $coincidencias=[];
        foreach ($filas as $fila) {
            if (!is_array($fila)) continue;
            if (hash_equals($ref,(string)($fila['pbx_call_id']??''))) $coincidencias[]=$fila;
            if (count($coincidencias)>1) return false;
        }
        if (count($coincidencias)!==1) return false;
        $registro=$coincidencias[0];
        if (!hash_equals($ext,trim((string)($registro['sip']??'')))) return false;
        $estado=strtolower(trim((string)($registro['disposition']??'')));
        $contestada=!empty($call['conectada']);
        // Si PBX acredita ANSWER, sólo CDR answered puede cerrar esa llamada.
        // Sin ANSWER sólo admitimos estados finales sin contacto confirmado.
        if ($contestada ? $estado!=='answered'
            : !in_array($estado,['busy','no answer','no_answer','cancel','canceled','cancelled','failed'],true)) return false;
        $segundos=$registro['seconds']??null;
        if ((!is_int($segundos) && (!is_string($segundos) || !ctype_digit($segundos)))
            || (int)$segundos<($contestada?1:0) || (int)$segundos>14400) return false;

        $inicioProveedor=(string)($registro['callstart']??'');
        if (!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$inicioProveedor)) return false;
        $evento=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$inicioProveedor,$tz);
        if (!$evento || $evento->format('Y-m-d H:i:s')!==$inicioProveedor
            || abs($inicio->getTimestamp()-$evento->getTimestamp())>300) return false;

        // Sólo una llamada terminada: duración cerrada y fin anterior a ahora.
        return $evento->getTimestamp()+(int)$segundos+15 <= $ahora->getTimestamp();
    }
}
