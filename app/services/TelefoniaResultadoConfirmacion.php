<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaBorradorService.php';

/** Confirmación común. El endpoint valida permiso/CSRF; el adaptador valida la
 * llamada y escribe el registro nativo. Sólo su callback, antes del COMMIT,
 * puede generar el recibo. Un borrador nunca ejecuta un resultado por sí solo.
 */
final class TelefoniaResultadoConfirmacion
{
    public function __construct(private mysqli $db) {}

    public function ejecutar(int $usuario,string $adaptador,array $datos,callable $guardar):array
    {
        $meta=$datos['captura_confirmacion']??null;
        unset($datos['captura_confirmacion']);
        // Compatibilidad de las pantallas anteriores durante publicación selectiva.
        if($meta===null)return $guardar($datos,null);
        if(!is_string($meta)||strlen($meta)>8192)throw new InvalidArgumentException('Confirmación inválida.');
        try{$m=json_decode($meta,true,8,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new InvalidArgumentException('Confirmación incompleta.');}
        if(!is_array($m)||!is_array($m['ids']??null)||!is_int($m['revision']??null)||$m['revision']<1||
            !is_string($m['request_id']??null)||!preg_match('/^[A-Za-z0-9_-]{12,100}$/D',$m['request_id']))
            throw new InvalidArgumentException('Confirmación inválida.');
        $ids=TelefoniaBorradorService::validarReferencias($usuario,$m['ids']);
        $revision=$m['revision'];$request=$m['request_id'];
        $canonical=$datos;unset($canonical['csrf_token']);ksort($canonical);
        foreach($canonical as $v)if(!is_scalar($v)&&$v!==null)throw new InvalidArgumentException('Resultado inválido.');
        $hash=hash('sha256',json_encode([$adaptador,$revision,$canonical],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $drafts=new TelefoniaBorradorService($this->db);
        $group=$drafts->resolverReferencias($usuario,$ids);
        $references=[];
        foreach(['pbx_call_id','sdk_session_id','sdk_reference','llamada_pbx_id'] as $field)
            if(!empty($datos[$field]))$references[]=trim((string)$datos[$field]);
        if(!$references||array_diff($references,$group))throw new InvalidArgumentException('La captura no corresponde a esta llamada.');
        $leer=static function()use($drafts,$usuario,$ids,$request,$hash):?array{
            $receipt=$drafts->leerAplicacion($usuario,$ids,$request);
            if($receipt===null)return null;
            if(!hash_equals((string)($receipt['peticion_huella']??''),$hash)||!is_array($receipt['respuesta']??null))
                throw new DomainException('El intento ya confirmó otro contenido. Se conservó el registro original.');
            return $receipt['respuesta'];
        };
        $saved=$leer();if($saved!==null)return $saved;
        $confirmed=false;
        $confirmar=static function(string $tipo,int $nativeId,array $respuesta)use(
            $drafts,$usuario,$ids,$revision,$request,$adaptador,$datos,$hash,&$confirmed):void{
            $drafts->confirmarAplicacionEnTransaccion($usuario,$ids,$revision,$request,$adaptador,$tipo,
                $nativeId,$datos,$respuesta,$hash);
            $confirmed=true;
        };
        try{
            $result=$guardar($datos,$confirmar);
            if(!$confirmed)throw new LogicException('El adaptador no confirmó el resultado en su transacción.');
            return $result;
        }catch(Throwable $error){
            // El adaptador ya revirtió su transacción. Un envío concurrente
            // pudo confirmar el mismo intento mientras éste esperaba la clave única.
            $saved=$leer();if($saved!==null)return $saved;
            throw $error;
        }
    }
}
