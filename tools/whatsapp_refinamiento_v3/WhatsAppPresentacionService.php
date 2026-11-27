<?php
declare(strict_types=1);

/** Presentación de WhatsApp. No modifica registros ni la zona de otras conexiones.
 * Contrato de la instalación: entrada Meta enviado_at = México; created_at = UTC.
 * En salida se usa el instante de registro UTC, independiente de callbacks Meta.
 */
final class WhatsAppPresentacionService
{
    public static function nombre(array $fila): string
    {
        foreach(['prospecto_nombre','aliado_nombre','nombre_contacto','telefono_contacto'] as $k){
            $value=trim((string)($fila[$k]??''));
            if($value!=='')return $value;
        }
        return 'Contacto';
    }

    private static function fecha(?string $value,string $zone): ?DateTimeImmutable
    {
        if(!$value)return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone($zone));
        return $d && $d->format('Y-m-d H:i:s')===$value?$d:null;
    }

    public static function instante(array $m): ?DateTimeImmutable
    {
        $entrada=($m['direccion']??'')==='ENTRANTE';
        if($entrada && ($d=self::fecha($m['enviado_at']??null,'America/Mexico_City')))return $d;
        return self::fecha($m['created_at']??null,'UTC');
    }

    public static function horaVisible(array $m): ?string
    {
        $d=self::instante($m);
        return $d?$d->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s'):null;
    }

    public static function resumen(array $row): array
    {
        $latest=null;$when=null;
        foreach(['entrada','salida'] as $prefix){
            if(empty($row[$prefix.'_id']))continue;
            $m=['id'=>(int)$row[$prefix.'_id'],'direccion'=>$prefix==='entrada'?'ENTRANTE':'SALIENTE',
                'contenido'=>$row[$prefix.'_contenido']??'','enviado_at'=>$row[$prefix.'_enviado']??null,
                'created_at'=>$row[$prefix.'_creado']??null];
            $d=self::instante($m);
            if($d && ($when===null || $d>$when || ($d==$when && $m['id']>$latest['id']))){$when=$d;$latest=$m;}
        }
        if($latest){
            $row['ultimo_mensaje_at']=self::horaVisible($latest);
            $row['ultimo_mensaje_preview']=mb_substr((string)$latest['contenido'],0,300);
            $row['ultimo_mensaje_direccion']=$latest['direccion'];
            $row['ultimo_mensaje_id']=$latest['id'];
            $row['ultimo_mensaje_epoch']=$when->getTimestamp();
        }
        foreach(['entrada','salida'] as $prefix)foreach(['id','contenido','enviado','creado'] as $suffix)unset($row[$prefix.'_'.$suffix]);
        return $row;
    }

    public static function ordenar(array $rows): array
    {
        $rows=array_map([self::class,'resumen'],$rows);
        usort($rows,static function($a,$b){
            return (($b['ultimo_mensaje_epoch']??0)<=>($a['ultimo_mensaje_epoch']??0))
                ?: (($b['ultimo_mensaje_id']??0)<=>($a['ultimo_mensaje_id']??0))
                ?: ((int)$b['id']<=>(int)$a['id']);
        });
        return $rows;
    }
}
