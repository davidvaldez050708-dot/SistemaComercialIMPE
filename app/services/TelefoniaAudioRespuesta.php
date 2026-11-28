<?php
declare(strict_types=1);

/** Transporte binario común DESPUÉS de autorizar la referencia de grabación. */
final class TelefoniaAudioRespuesta
{
    public static function extension(string $type): string
    {
        $type=strtolower($type);
        return str_contains($type,'wav')?'wav':(str_contains($type,'ogg')?'ogg':(str_contains($type,'mp4')?'m4a':'mp3'));
    }
    private static function numero(string $value): int
    {
        $value=ltrim($value,'0');
        $max=(string)PHP_INT_MAX;
        return strlen($value)>strlen($max)||(strlen($value)===strlen($max)&&strcmp($value,$max)>0)
            ?PHP_INT_MAX:(int)$value;
    }

    private static function limites(string $range,int $size): ?array
    {
        if($size<=0||!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]===''))return null;
        if($m[1]===''){
            $suffix=self::numero($m[2]);
            return $suffix>0?[max(0,$size-$suffix),$size-1]:null;
        }
        $start=self::numero($m[1]);$end=$m[2]===''?$size-1:min($size-1,self::numero($m[2]));
        return $start<$size&&$end>=$start?[$start,$end]:null;
    }

    public static function respuesta(array $audio,string $range=''): array
    {
        $invalid=static fn(?int $size=null):array=>['status'=>416,'headers'=>$size===null?[]:['Content-Range'=>'bytes */'.$size],'body'=>''];
        if($range!==''&&(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]==='')))return $invalid();
        $status=(int)($audio['status']??200);
        // Una página de error o una respuesta parcial no solicitada nunca es audio completo.
        if(!in_array($status,[200,206],true)||($status===206&&$range===''))throw new RuntimeException('Respuesta de audio no disponible.');
        $type=strtolower(trim((string)($audio['content_type']??'')));
        if(!preg_match('~^(audio/[a-z0-9.+-]+|application/octet-stream)(?:;[^\r\n]*)?$~D',$type))throw new RuntimeException('El archivo recibido no es audio.');
        $body=(string)($audio['body']??'');
        if($body==='')throw new RuntimeException('El audio todavía no está disponible.');
        $headers=['Content-Type'=>$type,'Accept-Ranges'=>'bytes'];
        if($status===206){
            $provided=(string)($audio['headers']['content-range']??'');
            if(!preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/D',$provided,$p))throw new RuntimeException('Respuesta de audio incompleta.');
            foreach(array_slice($p,1) as $number)
                if((string)self::numero($number)!==ltrim($number,'0')&&$number!=='0')throw new RuntimeException('Tamaño de audio inválido.');
            [$start,$end,$size]=array_map('intval',array_slice($p,1));
            $expected=self::limites($range,$size);
            if($expected!==[$start,$end]||$end<$start||$end>=$size||strlen($body)!==$end-$start+1)
                throw new RuntimeException('Respuesta de audio no corresponde al tramo solicitado.');
            $headers['Content-Range']="bytes $start-$end/$size";
        }elseif($range!==''){
            $size=strlen($body);$bounds=self::limites($range,$size);
            if($bounds===null)return $invalid($size);
            [$start,$end]=$bounds;$body=substr($body,$start,$end-$start+1);$status=206;
            $headers['Content-Range']="bytes $start-$end/$size";
        }
        $headers['Content-Length']=(string)strlen($body);
        return ['status'=>$status,'headers'=>$headers,'body'=>$body];
    }
}
