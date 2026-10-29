<?php
/** Exclusivamente fixture local: no ejecuta red ni usa claves de producción. */
class ZadarmaRecordingService {
    public static array $consultados=[];
    public function __construct(...$args){}
    public function obtenerGrabacionPorCallId(string $id): array {
        self::$consultados[]=$id;
        if(!str_starts_with($id,'audio-verificado-'))throw new RuntimeException('Referencia ajena a la prueba');
        return ['link'=>'https://audio.invalid/'.$id];
    }
    public function descargarGrabacion(string $url,string $range=''): array {
        if(!str_starts_with($url,'https://audio.invalid/audio-verificado-'))throw new RuntimeException('URL de prueba inválida');
        return ['body'=>'AUDIO-SINTETICO','content_type'=>'audio/mpeg','status'=>200,'headers'=>[]];
    }
}
