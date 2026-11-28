<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';

/** Elegir dentro del catálogo YA autorizado. Un ID del navegador no concede
 * propiedad. La referencia estable evita cambiar de audio si llega otro tramo. */
final class TelefoniaGrabacionSelector
{
    public static function catalogo(array $ids): string
    {
        return hash('sha256',json_encode($ids,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    public static function indice(array $ids,array $query): int
    {
        if(!$ids)throw new DomainException('No hay una grabación propia disponible.',404);
        if(count($ids)>8)throw new DomainException('El catálogo de grabaciones requiere revisión.',409);
        foreach($ids as $key=>$id)
            if(!is_int($key)||$key<0||$key>7||!is_string($id)||!ZadarmaPbxIdentifierHelper::callIdValido($id))
                throw new DomainException('El catálogo de grabaciones requiere revisión.',409);
        if(count(array_unique($ids,SORT_STRING))!==count($ids))
            throw new DomainException('El catálogo contiene referencias repetidas.',409);
        if(array_key_exists('grabacion',$query)){
            $id=$query['grabacion'];
            if(!is_string($id)||!ZadarmaPbxIdentifierHelper::callIdValido($id)||($key=array_search($id,$ids,true))===false)
                throw new DomainException('La grabación solicitada ya no está autorizada para esta llamada.',404);
            return $key;
        }
        if(!array_key_exists('segmento',$query)){
            if(count($ids)===1)return array_key_first($ids);
            throw new DomainException('Selecciona la grabación que deseas consultar.',409);
        }
        $key=filter_var($query['segmento'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>7]]);
        if($key===false||!array_key_exists($key,$ids))throw new DomainException('El tramo solicitado no está disponible.',404);
        // Compatibilidad posicional únicamente con un catálogo que no cambió.
        if(count($ids)>1 && (!is_string($query['catalogo']??null)||!hash_equals(self::catalogo($ids),$query['catalogo'])))
            throw new DomainException('El catálogo cambió. Vuelve a consultar las grabaciones.',409);
        return $key;
    }

    public static function referencias(array $records,array $info): array
    {
        $ids=[];
        foreach($info as $part){
            $index=$part['indice']??null;$id=$part['referencia']??null;
            if(is_int($index)&&isset($records[$index])&&is_string($id))$ids[$index]=$id;
        }
        return $ids;
    }
}
