<?php
require_once __DIR__.'/TelefoniaVentasLlamadasService.php';
require_once __DIR__.'/TelefoniaResultadoVentasService.php';

final class VentasActividadMetricas
{
    public const PERIODOS = ['hoy'=>'Hoy','ayer'=>'Ayer','semana'=>'Esta semana','ultimos_7'=>'Últimos 7 días',
        'mes'=>'Este mes','mes_anterior'=>'Mes anterior','personalizado'=>'Personalizado'];

    public static function periodo(array $f, ?DateTimeImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? new DateTimeImmutable('today', new DateTimeZone('America/Mexico_City')))->setTime(0,0);
        $key = (string)($f['periodo'] ?? 'semana');
        if (!isset(self::PERIODOS[$key])) throw new InvalidArgumentException('Selecciona un periodo válido.');
        $from = $hoy; $until = $hoy;
        if ($key === 'ayer') $from = $until = $hoy->modify('-1 day');
        elseif ($key === 'semana') $from = $hoy->modify('monday this week');
        elseif ($key === 'ultimos_7') $from = $hoy->modify('-6 days');
        elseif ($key === 'mes') $from = $hoy->modify('first day of this month');
        elseif ($key === 'mes_anterior') { $from = $hoy->modify('first day of last month'); $until = $hoy->modify('last day of last month'); }
        elseif ($key === 'personalizado') {
            $dates = [];
            foreach (['fecha_desde','fecha_hasta'] as $name) {
                $value = (string)($f[$name] ?? '');
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $hoy->getTimezone());
                if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Completa las dos fechas del periodo.');
                $dates[] = $date;
            }
            [$from,$until] = $dates;
        }
        if ($from > $until || $until > $hoy || $from->diff($until)->days > 365)
            throw new InvalidArgumentException('Selecciona hasta 366 días, sin fechas futuras y con el inicio anterior al final.');
        return ['clave'=>$key,'label'=>self::PERIODOS[$key],'fecha_desde'=>$from->format('Y-m-d'), 'fecha_hasta'=>$until->format('Y-m-d')];
    }

    public static function vacio(): array
    {
        return array_fill_keys(['llamadas','salientes','entrantes','conectadas','conversaciones','conversaciones_confirmadas',
            'intentos','transferencias_pendientes','registradas','por_registrar','capturables','segundos','duraciones_confirmadas',
            'duraciones_pendientes','buzones'], 0);
    }

    public static function calcular(array $rows, array $personas, array $periodo): array
    {
        $summary = self::vacio(); $daily = []; $people = []; $seen = []; $results = [];
        foreach ($personas as $person) $people[(int)$person['id']] = $person + self::vacio();
        $date = new DateTimeImmutable($periodo['fecha_desde']);
        while ($date->format('Y-m-d') <= $periodo['fecha_hasta']) {
            $daily[$date->format('Y-m-d')] = ['fecha'=>$date->format('Y-m-d'),'primera_llamada'=>null,'ultima_llamada'=>null] + self::vacio();
            $date = $date->modify('+1 day');
        }
        $clean = [];
        foreach ($rows as $row) {
            $uid = (int)$row['usuario_id']; $day = substr($row['fecha'], 0, 10);
            if (!isset($people[$uid], $daily[$day])) continue;
            $ref = (string)$row['pbx_call_id'];
            $identity = $uid.'|'.$row['extension'].'|'.($ref !== '' ? $ref : $row['id']);
            if (isset($seen[$identity])) continue;
            $seen[$identity] = true;
            $m = self::vacio();
            if ($ref === '') {
                $m[str_starts_with((string)$row['id'], 'intento-') ? 'intentos' : 'transferencias_pendientes'] = 1;
            } else {
                $m['llamadas'] = 1;
                $m[$row['direccion'] === 'Saliente' ? 'salientes' : 'entrantes'] = 1;
                $m['conectadas'] = (int)!empty($row['conectada']);
                $m['conversaciones'] = (int)TelefoniaResultadoVentasService::esConversacion($row['registro']['resultado'] ?? '');
                $m['conversaciones_confirmadas'] = $m['conectadas'] && $m['conversaciones'] ? 1 : 0;
                $m['registradas'] = empty($row['registro']) ? 0 : 1;
                $m['capturables'] = TelefoniaVentasLlamadasService::resultadoCapturable($row) || $m['registradas'] ? 1 : 0;
                $m['por_registrar'] = $m['capturables'] && !$m['registradas'] ? 1 : 0;
                if ($m['conectadas']) {
                    if ($row['segundos'] === null) $m['duraciones_pendientes'] = 1;
                    else { $m['segundos'] = max(0, (int)$row['segundos']); $m['duraciones_confirmadas'] = 1; }
                }
                $codes=TelefoniaResultadoVentasCatalogo::seleccionesRegistro($row['registro']??null)?:['PENDIENTE'];
                $m['buzones'] = (int)in_array('BUZON_VOZ',$codes,true);
                foreach($codes as $code)$results[$code]=($results[$code]??0)+1;
                // fecha ya está expresada en México por el repositorio. Usar el
                // inicio PBX registrado, sin inferir horarios de intentos o del fin.
                $inicio=(string)$row['fecha'];
                if(self::horaInicio($inicio)!=='—') {
                    if($daily[$day]['primera_llamada']===null || $inicio<$daily[$day]['primera_llamada'])$daily[$day]['primera_llamada']=$inicio;
                    if($daily[$day]['ultima_llamada']===null || $inicio>$daily[$day]['ultima_llamada'])$daily[$day]['ultima_llamada']=$inicio;
                }
            }
            foreach ($m as $key=>$value) { $summary[$key] += $value; $daily[$day][$key] += $value; $people[$uid][$key] += $value; }
            $row['asesor'] = $people[$uid]['nombre_completo']; $clean[] = $row;
        }
        foreach ($people as &$person) $person = self::tasas($person);
        unset($person);
        usort($people, static fn($a,$b)=>strnatcasecmp($a['nombre_completo'],$b['nombre_completo']));
        return ['resumen'=>self::tasas($summary),'personas_resumen'=>$people,'dias'=>array_values($daily),'resultados'=>$results,'llamadas'=>$clean];
    }

    private static function tasas(array $m): array
    {
        $m['tasa_contacto'] = $m['llamadas'] ? round(100*$m['conversaciones_confirmadas']/$m['llamadas'],1) : null;
        $m['cumplimiento_registro'] = $m['capturables'] ? round(100*$m['registradas']/$m['capturables'],1) : null;
        $m['promedio_segundos'] = $m['duraciones_confirmadas'] ? (int)round($m['segundos']/$m['duraciones_confirmadas']) : null;
        return $m;
    }

    public static function tiempo(?int $seconds): string
    {
        return $seconds === null ? 'Pendiente' : sprintf('%02d:%02d:%02d', intdiv($seconds,3600),intdiv($seconds%3600,60),$seconds%60);
    }
    public static function tiempoConfirmado(array $metricas): string
    {
        return !empty($metricas['duraciones_confirmadas']) ? self::tiempo((int)$metricas['segundos']) : (!empty($metricas['duraciones_pendientes']) ? 'Pendiente' : '—');
    }

    /** Evitar presentar los extremos de varias personas como el horario de un asesor. */
    public static function mostrarHorarioIndividual(array $datos): bool
    {
        $personas=$datos['personas_resumen']??[];
        return count($personas)===1 && (($datos['vista']??'')==='propio' ||
            (int)($datos['persona_id']??0)===(int)($personas[0]['id']??0) && (int)($datos['persona_id']??0)>0);
    }

    public static function horaInicio(?string $fecha): string
    {
        if(!$fecha)return '—';
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$fecha,new DateTimeZone('America/Mexico_City'));
        return $date && $date->format('Y-m-d H:i:s')===$fecha ? $date->format('H:i:s') : '—';
    }
}
