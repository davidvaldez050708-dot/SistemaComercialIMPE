<?php
/** Transformaciones locales. Nunca asigna, llama ni fusiona personas. */
final class ProspectoCentralDatos
{
    public static function telefono(?string $original): ?string
    {
        if ($original===null || !preg_match('/^\+?[0-9\s().-]+$/D',trim($original))) return null;
        $digits=preg_replace('/\D/','',$original);
        if (str_starts_with(trim($original),'+') && strlen($digits)>=8 && strlen($digits)<=15) return '+'.$digits;
        if (strlen($digits)===10) return '+52'.$digits;
        if (strlen($digits)===12 && str_starts_with($digits,'52')) return '+'.$digits;
        return null;
    }

    public static function horario(?string $original): array
    {
        $out=['valor_original'=>$original,'dias_semana'=>null,'hora_desde'=>null,'hora_hasta'=>null,
            'zona_horaria'=>null,'interpretacion_estado'=>'NO_INDICADO'];
        if ($original===null || $original==='') return $out;
        $out['interpretacion_estado']='NO_INTERPRETADO';
        if ($original==='Sin preferencia') {$out['interpretacion_estado']='SIN_PREFERENCIA';return $out;}
        if (!preg_match('/^(.+) · ((?:[01][0-9]|2[0-3]):[0-5][0-9])–((?:[01][0-9]|2[0-3]):[0-5][0-9]) \(hora local\)$/uD',$original,$m) || $m[2]>=$m[3]) return $out;
        $map=['Lun'=>1,'Mar'=>2,'Mié'=>3,'Jue'=>4,'Vie'=>5,'Sáb'=>6,'Dom'=>7];$days=[];
        foreach (explode(', ',$m[1]) as $day) {if (!isset($map[$day]) || in_array($map[$day],$days,true)) return $out;$days[]=$map[$day];}
        if (!$days || count($days)>7) return $out;
        $out['dias_semana']=json_encode($days);$out['hora_desde']=$m[2];$out['hora_hasta']=$m[3];
        $out['interpretacion_estado']='DEFINIDO_ZONA_PENDIENTE';
        return $out; // «hora local» no identifica una zona IANA ni una cita.
    }

    public static function persona(array $d): array
    {
        $nombre=$d['nombre']??null;$apellidos=$d['apellidos']??$d['apellido']??null;
        $completo=$d['nombre_completo']??trim((string)$nombre.' '.(string)$apellidos);
        $telefono=$d['telefono_original']??$d['movil']??null;
        $out=['nombre'=>$nombre,'apellidos'=>$apellidos,'nombre_completo'=>$completo,
            'telefono_original'=>$telefono,'telefono_normalizado'=>self::telefono($telefono),
            'correo'=>$d['correo']??null,'estado_id'=>$d['estado_id']??null,'municipio_id'=>$d['municipio_id']??null,
            'pais_nombre'=>$d['pais_nombre']??null,'estado_provincia'=>$d['estado_provincia']??null,'ciudad'=>$d['ciudad']??null];
        foreach (['nombre'=>80,'apellidos'=>120,'nombre_completo'=>255,'telefono_original'=>64,'correo'=>190,'pais_nombre'=>120,'estado_provincia'=>120,'ciudad'=>120] as $key=>$max) {
            if ($out[$key]!==null && (!is_string($out[$key]) || !mb_check_encoding($out[$key],'UTF-8') || mb_strlen($out[$key])>$max || str_contains($out[$key],"\0"))) throw new DomainException('Datos de prospecto no válidos.');
        }
        $out['integridad_estado']=$completo!=='' && $out['telefono_normalizado']!==null ? 'BASICOS_COMPLETOS' : 'REVISAR';
        return $out;
    }
}
