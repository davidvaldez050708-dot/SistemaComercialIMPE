<?php

/** Clasificación declarada por el asesor; nunca acredita por sí sola audio o efectividad. */
final class TelefoniaResultadoVentasCatalogo
{
    public const VERSION = '2';
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
        return $detalle?array_replace($registro,$detalle):$registro;
    }
}
