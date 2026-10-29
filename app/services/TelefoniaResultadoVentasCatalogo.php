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
        return self::completarRegistros($db,[$registro])[0];
    }

    /** Los registros proceden de una consulta ya autorizada. Revalidar su identidad al unir detalles. */
    public static function completarRegistros(mysqli $db,array $registros): array
    {
        $ids=[];foreach($registros as $r)if($r&&!empty($r['id']))$ids[(int)$r['id']]=true;
        if(!$ids||!$db->query("SHOW TABLES LIKE 'telefonia_ventas_resultado_detalles'")->num_rows)return $registros;
        $detalles=[];$selecciones=[];$v3=[];
        foreach(array_chunk(array_keys($ids),500) as $chunk){
            $s=$db->prepare('SELECT d.*,r.usuario_id,r.extension,r.pbx_call_id FROM telefonia_ventas_resultado_detalles d
                JOIN telefonia_ventas_resultados r ON r.id=d.resultado_id WHERE r.id IN ('.implode(',',array_fill(0,count($chunk),'?')).')');
            $s->bind_param(str_repeat('i',count($chunk)),...$chunk);$s->execute();
            foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $d){$id=(int)$d['resultado_id'];$detalles[$id]=$d;if($d['catalogo_version']==='3')$v3[]=$id;}$s->close();
        }
        foreach(array_chunk($v3,500) as $chunk){
            $s=$db->prepare('SELECT resultado_id,codigo FROM telefonia_ventas_resultado_selecciones WHERE resultado_id IN ('.implode(',',array_fill(0,count($chunk),'?')).') ORDER BY resultado_id,posicion');
            $s->bind_param(str_repeat('i',count($chunk)),...$chunk);$s->execute();
            foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$selecciones[(int)$row['resultado_id']][]=$row['codigo'];$s->close();
        }
        foreach($registros as &$r){
            if(!$r||empty($r['id']))continue;$id=(int)$r['id'];$d=$detalles[$id]??null;
            if(!$d||(int)$d['usuario_id']!==(int)($r['usuario_id']??0)||$d['extension']!==($r['extension']??null)||$d['pbx_call_id']!==($r['pbx_call_id']??null))continue;
            $r=array_replace($r,array_intersect_key($d,array_flip(['resultado_principal','envio_informacion','motivo_sin_whatsapp','contacto_antes_corte','catalogo_version'])));
            if($d['catalogo_version']==='3')$r['resultados_seleccionados']=$selecciones[$id]??[];
        }unset($r);
        return $registros;
    }

    public static function opcionesConsulta(): array
    {
        require_once __DIR__.'/TelefoniaResultadoVentasService.php';
        return TelefoniaResultadoVentasService::opciones()+self::opcionesSeleccion();
    }

    public static function seleccionesRegistro(?array $registro): array
    {
        if(!$registro||empty($registro['resultado']))return [];
        if(!empty($registro['resultados_seleccionados'])&&is_array($registro['resultados_seleccionados']))
            return array_values(array_unique(array_filter($registro['resultados_seleccionados'],static fn($v)=>is_string($v)&&$v!=='')));
        $codes=[(string)$registro['resultado']];$envio=(string)($registro['envio_informacion']??'');
        if(in_array($envio,['WHATSAPP','WHATSAPP_SMS'],true))$codes[]='WHATSAPP';
        if(in_array($envio,['SMS','WHATSAPP_SMS'],true))$codes[]='SMS';
        return array_values(array_unique($codes));
    }

    public static function opcionesFiltro(): array
    {
        $opciones=self::opcionesConsulta();unset($opciones['ENVIO_WHATSAPP'],$opciones['ENVIO_SMS']);
        return $opciones;
    }

    public static function etiquetasRegistro(?array $registro): array
    {
        $labels=self::opcionesConsulta();$out=[];
        foreach(self::seleccionesRegistro($registro) as $code){
            $labelCode=$code==='COMUNICACION_INTERRUMPIDA'&&($registro['contacto_antes_corte']??'')==='SI'?self::INTERRUMPIDA_PERSONA:$code;
            $out[]=$labels[$labelCode]??$code;
        }return $out;
    }

    public static function textoRegistro(?array $registro): string
    {
        return implode(' · ',self::etiquetasRegistro($registro))?:'Sin resultado registrado';
    }

    public static function aclaracionesRegistro(?array $registro): string
    {
        $parts=[];$corte=$registro['contacto_antes_corte']??'';
        if($corte==='SI')$parts[]='Habló con una persona antes del corte.';
        elseif($corte==='NO')$parts[]='No hubo conversación con una persona antes del corte.';
        if(trim((string)($registro['motivo_sin_whatsapp']??''))!=='')$parts[]='Motivo registrado anteriormente: '.$registro['motivo_sin_whatsapp'];
        return implode(' ',$parts);
    }

    public static function coincideRegistro(?array $registro,string $codigo): bool
    {
        if($codigo==='')return true;if($codigo==='PENDIENTE')return !$registro;
        if(!$registro)return false;
        return in_array($codigo,self::codigosFiltroRegistro($registro),true);
    }

    public static function codigosFiltroRegistro(?array $registro): array
    {
        if(!$registro)return [];
        $codes=self::seleccionesRegistro($registro);if(!empty($registro['resultado']))$codes[]=$registro['resultado'];
        if(($registro['envio_informacion']??'')==='WHATSAPP_SMS')$codes[]='ENVIO_WHATSAPP_SMS';
        if(($registro['resultado']??'')==='INFORMACION_ENVIADA_WHATSAPP')$codes[]='WHATSAPP';
        return array_values(array_unique($codes));
    }
}
