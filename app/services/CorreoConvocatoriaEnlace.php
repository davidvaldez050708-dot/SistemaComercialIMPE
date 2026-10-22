<?php

/** Sólo valida/presenta la URL ya guardada. No genera enlaces ni consulta formularios. */
final class CorreoConvocatoriaEnlace
{
    public static function obtener(array $row,?string $base=null): ?array
    {
        $url=$row['enlace_ref']??'';
        $base??=defined('BASE_URL')?(string)BASE_URL:'';
        if(!is_string($url) || $url==='' || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f]|%0[ad]|%00/i',$url) || !filter_var($url,FILTER_VALIDATE_URL)) { return null; }
        $p=parse_url($url);
        if(!$p || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) { return null; }
        // Reutilizar la lista cerrada de hosts públicos que ya utiliza BASE_URL.
        $trusted=function_exists('impeResolverBaseUrlHostinger')?impeResolverBaseUrlHostinger($base,strtolower($p['host']??'')):$base;
        $b=parse_url($trusted);
        if(!$b || empty($b['host']) || strtolower($p['host']??'')!==strtolower($b['host']) || strtolower($p['scheme']??'')!==strtolower($b['scheme']??'') ||
            (int)($p['port']??(strtolower($p['scheme']??'')==='https'?443:80))!==(int)($b['port']??(strtolower($b['scheme']??'')==='https'?443:80)) ||
            ($p['path']??'')!==rtrim($b['path']??'','/').'/index.php') { return null; }
        if(strtolower($p['scheme']??'')!=='https' && !in_array(strtolower($b['host']),['localhost','127.0.0.1','[::1]'],true)) { return null; }
        $params=[];
        foreach(explode('&',$p['query']??'') as $part) {
            $pair=explode('=',$part,2);$key=urldecode($pair[0]);
            if(count($pair)!==2 || !in_array($key,['controller','action','aliado'],true) || array_key_exists($key,$params)) { return null; }
            $params[$key]=urldecode($pair[1]);
        }
        if(($params['controller']??'')!=='formularioPublico' || !in_array($params['action']??'',['registro','inscripcion'],true) ||
            (isset($params['aliado']) && !preg_match('/^[a-f0-9]{64}$/D',$params['aliado']))) { return null; }
        $registration=$params['action']==='registro';
        return ['url'=>$url,'tipo'=>$params['action'],'titulo'=>$registration?'Formulario de Registro':'Formulario de Inscripción',
            'texto'=>$registration?'Consulta la convocatoria y completa tu registro en el siguiente enlace:':'Consulta la convocatoria y completa tu inscripción en el siguiente enlace:',
            'etiqueta'=>$registration?'Acceder al formulario de registro':'Acceder al formulario de inscripción'];
    }
    public static function html(array $link): string
    {
        $escape=static fn($value)=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return '<div style="margin-top:18px"><strong>'.$escape($link['titulo']).'</strong><p>'.$escape($link['texto']).'</p><a href="'.$escape($link['url']).'">'.$escape($link['etiqueta']).'</a></div>';
    }
}
