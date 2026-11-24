<?php
// Publicador CLI: lista cerrada y versiones conocidas, sin sobrescribir código ajeno.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const ARCHIVOS_AGENDA = [
    'app/services/AgendaComentarioRepository.php',
    'app/services/AgendaComentarioService.php',
    'app/views/seguimiento_vinculacion/partials/agenda_comentarios.php',
    'database/migrations/2026_11_24_agenda_comentarios_destinatarios.sql',
    'public/css/agenda_destinatarios.css',
    'public/javascript/agenda_comentarios.js',
];

function leerArchivo(string $path): string {
    $text = file_get_contents($path);
    if ($text === false) throw new RuntimeException('No se pudo leer: '.$path);
    return $text;
}
function normalizar(string $text): string { return str_replace("\r\n", "\n", $text); }
function guardarArchivo(string $path, string $text): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true)) throw new RuntimeException('No se pudo preparar el directorio.');
    if (file_put_contents($path,$text)!==strlen($text)) throw new RuntimeException('No se pudo preparar el archivo.');
}
function sinEnlaces(string $live, string $name): void {
    $path=$live;
    foreach (explode('/',$name) as $segment) {
        $path.='/'.$segment;
        if (is_link($path)) throw new RuntimeException('No se modifica un enlace simbólico: '.$name);
    }
}

try {
    $action=$argv[1]??'';
    $source=__DIR__;
    $live=realpath($argv[2]??'');
    $tmp=realpath($argv[3]??'');
    if (!$live || !$tmp || !is_file($live.'/index.php')) throw new RuntimeException('No se encontró la raíz del CRM o la preparación.');
    if ($action==='preparar') {
        $manifest=json_decode(leerArchivo($source.'/instalacion.json'),true,512,JSON_THROW_ON_ERROR);
        if (array_keys($manifest)!==ARCHIVOS_AGENDA) throw new RuntimeException('Lista de instalación inválida.');
        $plan=[];
        foreach (ARCHIVOS_AGENDA as $name) {
            sinEnlaces($live,$name);
            $target=normalizar(leerArchivo($source.'/files/'.$name));
            if (hash('sha256',$target)!==$manifest[$name]['sha256']) throw new RuntimeException('Paquete alterado: '.$name);
            $path=$live.'/'.$name;
            $exists=is_file($path);
            $current=$exists?leerArchivo($path):null;
            $hash=$exists?hash('sha256',normalizar($current)):null;
            if (!$exists && !$manifest[$name]['nuevo']) throw new RuntimeException('Falta el archivo del CRM: '.$name);
            if ($exists && $hash!==$manifest[$name]['sha256'] && !in_array($hash,$manifest[$name]['permitidos'],true)) {
                throw new RuntimeException('Versión distinta a las verificadas; se conserva sin cambios: '.$name);
            }
            $changed=$hash!==$manifest[$name]['sha256'];
            if ($exists && substr_count($current,"\r\n")>0 && substr_count($current,"\r\n")===substr_count($current,"\n")) $target=str_replace("\n","\r\n",$target);
            if (!$changed) $target=$current;
            guardarArchivo($tmp.'/stage/'.$name,$target);
            if ($exists) guardarArchivo($tmp.'/original/'.$name,$current);
            $plan[$name]=['antes'=>$exists?hash('sha256',$current):null,'despues'=>hash('sha256',$target),'cambio'=>$changed];
        }
        guardarArchivo($tmp.'/plan.json',json_encode($plan,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        echo 'OK: '.count(array_filter($plan,fn($p)=>$p['cambio'])).' archivos por instalar; selector múltiple y permisos verificados.'.PHP_EOL;
        exit;
    }
    $plan=json_decode(leerArchivo($tmp.'/plan.json'),true,512,JSON_THROW_ON_ERROR);
    if (array_keys($plan)!==ARCHIVOS_AGENDA) throw new RuntimeException('Plan inválido.');
    if ($action==='listar') {
        foreach ($plan as $name=>$item) if ($item['cambio']) echo $name."\n";
        exit;
    }
    if (!in_array($action,['antes','despues'],true)) throw new RuntimeException('Acción inválida.');
    foreach ($plan as $name=>$item) {
        sinEnlaces($live,$name);
        $path=$live.'/'.$name;
        $hash=is_file($path)?hash_file('sha256',$path):null;
        if ($hash!==$item[$action]) throw new RuntimeException('La comprobación de archivos falló: '.$name);
    }
    if ($action==='despues') {
        $view=leerArchivo($live.'/app/views/seguimiento_vinculacion/partials/agenda_comentarios.php');
        if (!str_contains($view,'data-agenda-recipients-version="multi3"') || !str_contains($view,'?v=multi3-')) throw new RuntimeException('No está instalado el selector múltiple visible.');
        echo 'OK: selector con casillas multi3, JavaScript, estilos y servicios instalados y verificados.'.PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);
    exit(1);
}
