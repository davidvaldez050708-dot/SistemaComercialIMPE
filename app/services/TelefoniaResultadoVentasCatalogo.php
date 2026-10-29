<?php

/** Clasificación declarada por el asesor; nunca acredita por sí sola audio o efectividad. */
final class TelefoniaResultadoVentasCatalogo
{
    public const VERSION = '3';
    public const INTERRUMPIDA_PERSONA = 'COMUNICACION_INTERRUMPIDA_PERSONA';

    public static function resultados(): array
    {
        return [
            'BUZON_VOZ'=>'Buzón de voz',
            'NUMERO_INEXISTENTE'=>'Número inexistente o incorrecto',
            'NO_INTERESADO'=>'No interesado',
            'PROSPECTO_POTENCIAL'=>'Prospecto potencial',
            'FUERA_PRESUPUESTO'=>'Fuera del presupuesto',
            'INSCRITO'=>'Inscrito',
            'NO_CUMPLE_REQUISITOS'=>'No cumple con los requisitos',
            'NO_SOLICITO_INFORMACION'=>'No solicitó información',
            'SIGUIENTE_APERTURA'=>'Siguiente apertura',
            'SIN_RESPUESTA'=>'No contestó',
            'COMUNICACION_INTERRUMPIDA'=>'Comunicación interrumpida',
        ];
    }

    public static function envios(): array
    {
        return ['SIN_ENVIO'=>'No se envió información','WHATSAPP'=>'Se envía WhatsApp',
            'SMS'=>'Se envía SMS','WHATSAPP_SMS'=>'Se envía WhatsApp y SMS'];
    }

    public static function opcionesSeleccion(): array
    {
        $out=[];foreach(self::resultados() as $code=>$label){$out[$code]=$label;
            if($code==='FUERA_PRESUPUESTO')$out+=['WHATSAPP'=>'Se envió por WhatsApp','SMS'=>'Se envió SMS'];}
        return $out;
    }

    public static function etiquetasEnvio(): array
    {
        return ['ENVIO_WHATSAPP'=>'Se envió por WhatsApp','ENVIO_SMS'=>'Se envió SMS','ENVIO_WHATSAPP_SMS'=>'Se envió por WhatsApp y SMS'];
    }

    public static function normalizarSeleccion(array $datos): array
    {
        $json=$datos['resultados_seleccionados']??null;
        if(!is_string($json)||substr(ltrim($json),0,1)!=='[')throw new InvalidArgumentException('Selecciona uno o hasta dos resultados.');
        try{$seleccion=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new InvalidArgumentException('La selección de resultados no es válida.');}
        if(!is_array($seleccion)||array_keys($seleccion)!==range(0,count($seleccion)-1)||count($seleccion)<1||count($seleccion)>2)
            throw new InvalidArgumentException('Selecciona uno o hasta dos resultados.');
        foreach($seleccion as $code)if(!is_string($code)||!isset(self::opcionesSeleccion()[$code]))throw new InvalidArgumentException('Selecciona resultados de la lista actual.');
        if(count(array_unique($seleccion))!==count($seleccion))throw new InvalidArgumentException('No repitas un resultado.');
        $humanos=array_values(array_filter($seleccion,[self::class,'conPersona']));
        $sinContacto=array_values(array_intersect($seleccion,['BUZON_VOZ','NUMERO_INEXISTENTE','SIN_RESPUESTA']));
        if(count($sinContacto)>1||($sinContacto&&$humanos)||
            (in_array('PROSPECTO_POTENCIAL',$seleccion,true)&&in_array('NO_INTERESADO',$seleccion,true)))
            throw new InvalidArgumentException('Los resultados seleccionados se contradicen. Revisa lo que ocurrió en esta llamada.');
        $whatsapp=in_array('WHATSAPP',$seleccion,true);$sms=in_array('SMS',$seleccion,true);
        $envio=$whatsapp?($sms?'WHATSAPP_SMS':'WHATSAPP'):($sms?'SMS':'SIN_ENVIO');
        $bases=array_values(array_intersect($seleccion,array_keys(self::resultados())));
        $principal=$humanos[0]??($bases[0]??('ENVIO_'.$envio));
        $contacto='';
        if(in_array('COMUNICACION_INTERRUMPIDA',$seleccion,true)){
            $contacto=$humanos?'SI':($datos['contacto_antes_corte']??null);
            if(!in_array($contacto,['SI','NO'],true))throw new InvalidArgumentException('Indica si hablaste con una persona antes del corte.');
            if($contacto==='SI'&&$sinContacto)throw new InvalidArgumentException('No combines un resultado sin contacto con una conversación antes del corte.');
            if($principal==='COMUNICACION_INTERRUMPIDA'&&$contacto==='SI')$principal=self::INTERRUMPIDA_PERSONA;
        }
        return ['resultado'=>$principal,'resultado_principal'=>$principal===self::INTERRUMPIDA_PERSONA?'COMUNICACION_INTERRUMPIDA':$principal,
            'envio_informacion'=>$envio,'motivo_sin_whatsapp'=>'','contacto_antes_corte'=>$contacto,
            'contacto_humano'=>self::conPersona($principal),'resultados_seleccionados'=>$seleccion];
    }

    public static function conPersona(string $resultado): bool
    {
        return in_array($resultado,['NO_INTERESADO','PROSPECTO_POTENCIAL','FUERA_PRESUPUESTO',
            'INSCRITO','NO_CUMPLE_REQUISITOS','NO_SOLICITO_INFORMACION','SIGUIENTE_APERTURA',self::INTERRUMPIDA_PERSONA],true);
    }

    public static function normalizar(string $resultado,array $datos): array
    {
        if(!isset(self::resultados()[$resultado]))throw new InvalidArgumentException('Selecciona un resultado de la lista actual.');
        $envio=$datos['envio_informacion']??null;
        if(!is_string($envio)||!isset(self::envios()[$envio]))throw new InvalidArgumentException('Indica si se envió información por WhatsApp o SMS.');
        $motivo=$datos['motivo_sin_whatsapp']??'';
        if(!is_string($motivo))throw new InvalidArgumentException('Escribe el motivo por el que no se envió WhatsApp.');
        $motivo=trim($motivo);
        $whatsapp=in_array($envio,['WHATSAPP','WHATSAPP_SMS'],true);
        if(!$whatsapp&&mb_strlen($motivo)<3)throw new InvalidArgumentException('Indica el motivo por el que no se envió WhatsApp.');
        if(mb_strlen($motivo)>300)throw new InvalidArgumentException('Limita el motivo de WhatsApp a 300 caracteres.');
        if($resultado==='COMUNICACION_INTERRUMPIDA'){
            $contacto=$datos['contacto_antes_corte']??null;
            if(!in_array($contacto,['SI','NO'],true))throw new InvalidArgumentException('Indica si hablaste con una persona antes de que se interrumpiera la llamada.');
            if($contacto==='SI')$resultado=self::INTERRUMPIDA_PERSONA;
        }
        return ['resultado'=>$resultado,'resultado_principal'=>$resultado===self::INTERRUMPIDA_PERSONA?'COMUNICACION_INTERRUMPIDA':$resultado,
            'envio_informacion'=>$envio,'motivo_sin_whatsapp'=>$whatsapp?'':$motivo,
            'contacto_antes_corte'=>$resultado===self::INTERRUMPIDA_PERSONA?'SI':($resultado==='COMUNICACION_INTERRUMPIDA'?'NO':''),
            'contacto_humano'=>self::conPersona($resultado)];
    }

    /** Consulta sólo detalles del resultado ya autorizado por su propietario/tramo. Sin DDL. */
    public static function completarRegistro(mysqli $db,?array $registro): ?array
    {
        if(!$registro||empty($registro['id']))return $registro;
        if(!$db->query("SHOW TABLES LIKE 'telefonia_ventas_resultado_detalles'")->num_rows)return $registro;
        $s=$db->prepare('SELECT d.resultado_principal,d.envio_informacion,d.motivo_sin_whatsapp,d.contacto_antes_corte,d.catalogo_version
            FROM telefonia_ventas_resultado_detalles d JOIN telefonia_ventas_resultados r ON r.id=d.resultado_id
            WHERE r.id=? AND r.usuario_id=? AND r.extension=BINARY ? AND r.pbx_call_id=BINARY ?');
        $s->bind_param('iiss',$registro['id'],$registro['usuario_id'],$registro['extension'],$registro['pbx_call_id']);
        $s->execute();$detalle=$s->get_result()->fetch_assoc();$s->close();
        if(!$detalle)return $registro;
        $registro=array_replace($registro,$detalle);
        if($detalle['catalogo_version']==='3'){
            $s=$db->prepare('SELECT codigo FROM telefonia_ventas_resultado_selecciones WHERE resultado_id=? ORDER BY posicion');
            $s->bind_param('i',$registro['id']);$s->execute();$registro['resultados_seleccionados']=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'codigo');$s->close();
        }
        return $registro;
    }
}
