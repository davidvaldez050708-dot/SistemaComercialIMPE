<?php
/**
 * Reglas puras para los motores Meta y Aliados.
 * La jornada del asesor está configurada en horario America/Mexico_City.
 * No inferir una zona de contacto a partir de un teléfono o campaña.
 */
final class ProspectoDistribucionReglas
{
    public const META_POR_ASESOR_DIA = 80;
    public const ZONA_ASESORES = 'America/Mexico_City';

    public static function fechaSiguiente(DateTimeImmutable $ahora): string
    {
        return $ahora->setTimezone(new DateTimeZone(self::ZONA_ASESORES))
            ->modify('tomorrow')->format('Y-m-d');
    }

    public static function zonaContacto(?string $pais, ?string $estado, ?string $zona): ?string
    {
        if ($zona !== null && $zona !== '') {
            // Sólo zonas confirmadas expresamente; sin abreviaturas ambiguas.
            return $zona === self::ZONA_ASESORES ? $zona : null;
        }
        $p = self::normalizar((string)$pais);
        if ($p !== '' && !in_array($p,['mexico','mx','estados unidos mexicanos'],true)) {
            return null;
        }
        $e = self::normalizar((string)$estado);
        // Entidades mexicanas sin ambigüedad de huso para el horario laboral.
        $centro = [
            'ciudad de mexico','cdmx','distrito federal','morelos','puebla',
            'mexico','estado de mexico','guanajuato','queretaro','hidalgo',
            'tlaxcala','guerrero','michoacan','veracruz','oaxaca','chiapas',
            'tabasco','san luis potosi'
        ];
        return in_array($e,$centro,true)?self::ZONA_ASESORES:null;
    }

    private static function normalizar(string $valor): string
    {
        $valor=mb_strtolower(trim($valor),'UTF-8');
        return preg_replace('/\s+/u',' ',strtr($valor,[
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u'
        ]));
    }

    public static function preferencia(array $fila): array
    {
        $estado=(string)($fila['interpretacion_estado']??'NO_INDICADO');
        if (in_array($estado,['NO_INDICADO','SIN_PREFERENCIA'],true)) {
            return ['tipo'=>'LIBRE','dias'=>[],'desde'=>null,'hasta'=>null];
        }
        if (!in_array($estado,['DEFINIDO_ZONA_PENDIENTE','DEFINIDO'],true)) {
            return ['tipo'=>'BLOQUEADA','motivo'=>'HORARIO_NO_INTERPRETADO'];
        }
        $dias=json_decode((string)($fila['dias_semana']??''),true);
        $desde=substr((string)($fila['hora_desde']??''),0,5);
        $hasta=substr((string)($fila['hora_hasta']??''),0,5);
        if (!is_array($dias)||!$dias||count($dias)>7||
            !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$desde)||
            !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$hasta)||
            $desde >= $hasta) {
            return ['tipo'=>'BLOQUEADA','motivo'=>'HORARIO_NO_INTERPRETADO'];
        }
        $map=[];
        foreach ($dias as $dia) {
            if (!is_int($dia)||$dia<1||$dia>7) {
                return ['tipo'=>'BLOQUEADA','motivo'=>'HORARIO_NO_INTERPRETADO'];
            }
            $map[$dia]=true;
        }
        if (!self::zonaContacto(
            $fila['pais_nombre']??null,$fila['estado_provincia']??null,
            $fila['zona_horaria']??null
        )) {
            return ['tipo'=>'BLOQUEADA','motivo'=>'ZONA_HORARIA_PENDIENTE'];
        }
        return [
            'tipo'=>'DEFINIDA','dias'=>array_keys($map),'desde'=>$desde,
            'hasta'=>$hasta
        ];
    }

    /**
     * Primer horario futuro en los próximos 15 días.
     * Ventanas y jornadas se comparan en la zona confirmada de México.
     * No crea una cita telefónica; fija la primera franja permitida de trabajo.
     */
    public static function turno(array $horarios,array $pref,DateTimeImmutable $ahora): ?array
    {
        if ($pref['tipo']==='BLOQUEADA') return null;
        $tz=new DateTimeZone(self::ZONA_ASESORES);
        $local=$ahora->setTimezone($tz);
        for ($d=0;$d<15;$d++) {
            $fecha=$local->setTime(0,0)->modify('+'.$d.' day');
            $dia=(int)$fecha->format('N');
            if (!isset($horarios[$dia])) continue;
            $turno=$horarios[$dia];
            $desde=substr((string)$turno['desde'],0,5);
            $hasta=substr((string)$turno['hasta'],0,5);
            if (!preg_match('/^\d{2}:\d{2}$/D',$desde) || 
                !preg_match('/^\d{2}:\d{2}$/D',$hasta) || $desde >= $hasta) continue;
            if ($pref['tipo']==='DEFINIDA') {
                if (!in_array($dia,$pref['dias'],true)) continue;
                $desde=max($desde,$pref['desde']);
                $hasta=min($hasta,$pref['hasta']);
                if ($desde >= $hasta) continue;
            }
            $inicio=new DateTimeImmutable($fecha->format('Y-m-d').' '.$desde.':00',$tz);
            $fin=new DateTimeImmutable($fecha->format('Y-m-d').' '.$hasta.':00',$tz);
            if ($fin <= $local) continue;
            if ($inicio < $local) {
                // El contacto puede comenzar en esta franja, pero no en el pasado.
                $inicio=$local->modify('+1 minute');
                if ($inicio >= $fin) continue;
            }
            return [
                'fecha_jornada'=>$fecha->format('Y-m-d'),
                'proxima_accion_utc'=>$inicio->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            ];
        }
        return null;
    }

    public static function elegir(array $candidatos,int $prospectoId,string $modo): ?array
    {
        if (!$candidatos) return null;
        usort($candidatos,static function(array $a,array $b)use($prospectoId,$modo):int {
            $aCupo=(int)($a['meta_dia']??0);
            $bCupo=(int)($b['meta_dia']??0);
            if ($modo==='META'&&$aCupo!==$bCupo) return $aCupo<=>$bCupo;
            $aTotal=(int)($a['total_activos']??0);
            $bTotal=(int)($b['total_activos']??0);
            if ($aTotal!==$bTotal) return $aTotal<=>$bTotal;
            // Empate rotativo estable, nunca siempre a quien tenga menor ID.
            $aKey=crc32($prospectoId.':'.(int)$a['usuario_id']);
            $bKey=crc32($prospectoId.':'.(int)$b['usuario_id']);
            return $aKey<=>$bKey;
        });
        return $candidatos[0];
    }
}
