<?php
/**
 * Preflight de archivos: prueba contra una copia temporal ficticia.
 * No necesita una BD ni tiene ruta o credenciales del sitio de Hostinger.
 */
function asV3($ok,string $why){
    if(!$ok)throw new RuntimeException('FALLO PREFLIGHT: '.$why);
}
$root=dirname(__DIR__);
$script=$root.'/tools/ventas_v3_preflight_integridad_archivos.php';
$paths=[
    'app/controllers/EmbudoComercialController.php',
    'app/services/EmbudoComercialRepository.php',
    'app/services/EmbudoComercialResultadoBridge.php',
    'app/services/EmbudoComercialMovimientoService.php',
    'app/services/EmbudoComercialEtapas.php',
    'app/services/EmbudoComercialPoliticaPropuesta.php',
    'app/services/EmbudoComercialIntentosPbxRepository.php',
    'app/services/EmbudoComercialPlanificadorV3.php',
    'app/services/EmbudoComercialV3PilotoControlado.php',
    'app/services/EmbudoComercialV3IndicadoresVista.php',
    'app/views/embudo_comercial/index.php',
    'app/views/embudo_comercial/previsualizacion_v3.php',
    'public/css/embudo_comercial.css',
    'public/css/embudo_comercial_compacto_v1.css',
    'public/css/embudo_v3_indicadores.css',
    'public/javascript/embudo_comercial_movimientos.js',
    'app/services/TelefoniaResultadoVentasService.php',
    'app/services/TelefoniaResultadoVentasCatalogo.php'
];
// La prueba certifica un manifiesto histórico FIJO de PR #255.
 // No debe copiar el HEAD actual, porque PR posteriores cambian los archivos
 // de asesor intencionalmente. Usa el commit original, verificable en Git.
$manifestRev='d7038a903465e1126b3532af5d4d9e2e48aab465';
$fuentesV3=[];
$tmp=sys_get_temp_dir().'/impe_preflight_v3_'.bin2hex(random_bytes(5));
$site=$tmp.'/site';
asV3(mkdir($tmp,0700),'carpeta temporal');
try{
    asV3(mkdir($site,0700),'raíz del sitio ficticio');
    asV3(mkdir($site.'/config',0700),'configuración ficticia');
    file_put_contents($site.'/config/db_connection.php','<?php /* datos falsos */');
    foreach($paths as $p){
        $source=$root.'/'.$p;
        asV3(is_file($source),'archivo de repo existente '.$p);
        $pipes=[];
        $proc=proc_open(['git','-C',$root,'show',$manifestRev.':'.$p],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        asV3(is_resource($proc),'git show fuente original '.$p);
        fclose($pipes[0]);
        $bytes=(string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error=(string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        asV3(proc_close($proc)===0,
            'requiere fetch-depth 0 y commit original: '.$p.' '.$error);
        $fuentesV3[$p]=$bytes;
        $destination=$site.'/'.$p;
        $dir=dirname($destination);
        if(!is_dir($dir))asV3(mkdir($dir,0700,true),'carpeta '.$dir);
        asV3(file_put_contents($destination,$bytes)!==false,
            'copia temporal original del PR #255 '.$p);
    }
    $run=static function(array $args)use($script){
        $cmd=implode(' ',array_map('escapeshellarg',
            array_merge([PHP_BINARY,$script],$args)));
        $process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
            $pipes);
        asV3(is_resource($process),'proceso PHP');
        fclose($pipes[0]);
        $out=(string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err=(string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return [proc_close($process),$out,$err];
    };
    $complete=$run(['--site',$site,'--check']);
    asV3($complete[0]===0,
        '18 SHA exactos reconocidos: '.substr($complete[1].$complete[2],0,1400));
    asV3(strpos($complete[1],'V3_PREPARADO: 18')!==false,
        'todos los archivos históricos originales reconocidos, no el HEAD actual');
    asV3(strpos($complete[1],'NO PUBLICADO')!==false,
        'comprobación nunca publica');

    $noInstall=$run(['--site',$site,'--install']);
    asV3($noInstall[0]!==0,'no acepta una opción de escritura');

    $new=$site.'/public/css/embudo_v3_indicadores.css';
    unlink($new);
    $withoutNew=$run(['--site',$site,'--check']);
    asV3($withoutNew[0]===0 &&
        strpos($withoutNew[1],'AUSENTE_NUEVO | public/css/embudo_v3_indicadores.css')!==false,
        'nuevo archivo todavía no desplegado se reconoce correctamente');
    asV3(file_put_contents($new,
        $fuentesV3['public/css/embudo_v3_indicadores.css'])!==false,
        'restauración del blob V3 original');

    $existing=$site.'/app/services/TelefoniaResultadoVentasService.php';
    $original=file_get_contents($existing);
    file_put_contents($existing,$original."\n// Personalización inventada para prueba\n");
    $custom=$run(['--site',$site,'--check']);
    asV3($custom[0]===3 &&
        strpos($custom[1],
          'PERSONALIZADO | app/services/TelefoniaResultadoVentasService.php')!==false,
        'modificación de telefonía activa bloquea publicación');
    file_put_contents($existing,$original);

    $base=$site.'/app/services/EmbudoComercialRepository.php';
    unlink($base);
    $missing=$run(['--site',$site,'--check']);
    asV3($missing[0]===3 &&
        strpos($missing[1],
          'AUSENTE_REQUERIDO | app/services/EmbudoComercialRepository.php')!==false,
        'archivo base ausente bloquea publicación');

    // El diagnóstico no contiene SQL, operaciones PHP de escritura,
    // llamadas HTTP ni conexiones nuevas a base.
    $source=file_get_contents($script);
    foreach(['new mysqli','new Database','curl_exec(', 'shell_exec(',
        '->query(', '->prepare(', 'rename(', 'unlink(', 'file_put_contents('] as $write){
        asV3(strpos($source,$write)===false,
            'preflight nunca usa llamadas externas o modifica archivos: '.$write);
    }
    echo "CHECK OK: 18 hashes V3, faltantes nuevos permitidos, archivos personalizados/ausentes bloqueados, opciones de escritura rechazadas y solo lectura.\n";
}finally{
    if(is_dir($tmp)){
        $rm=static function($path)use(&$rm){
            if(is_dir($path) && !is_link($path)){
                foreach(scandir($path) as $name){
                    if($name==='.'||$name==='..')continue;
                    $rm($path.'/'.$name);
                }
                rmdir($path);
            }elseif(file_exists($path)||is_link($path)){
                unlink($path);
            }
        };
        $rm($tmp);
    }
}
