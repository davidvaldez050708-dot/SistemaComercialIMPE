<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
define('BASE_URL', 'https://crm.test/');
function tienePermiso(string $p): bool { return !($p==='telefonia.usar' && !empty($GLOBALS['audioDenyPermission'])); }
$dir = sys_get_temp_dir() . '/impe-audio-test-' . bin2hex(random_bytes(6));
mkdir($dir,0700);
$before = getenv('IMPE_PRIVATE_CONFIG_DIR');
putenv('IMPE_PRIVATE_CONFIG_DIR=' . $dir);
function renderAudioHost(array $session, string $extension, bool $capable = true): string {
    $_SESSION = $session + ['nombre'=>'Prueba','apellidos'=>'Llamadas','csrf_token'=>'test'];
    $_GET = ['display'=>'embed'];
    $asignacionTelefonica = ['extension'=>$extension,'permite_entrantes'=>$capable,'permite_salientes'=>$capable];
    ob_start();require ROOT_PATH . '/app/views/telefonia/host.php';return ob_get_clean();
}
try {
    $pilot=['usuario_id'=>33,'usuario'=>'Prueba.llamadas','rol_id'=>3,'rol'=>'Asesor de Ventas'];
    file_put_contents($dir . '/telefonia_audio.local.php', '<?php return ["enabled"=>false];');
    $off=renderAudioHost($pilot,'155');
    if (str_contains($off,'IMPE_AUDIO_DEVICES_CONFIG')) throw new RuntimeException('Piloto activo por defecto');
    file_put_contents($dir . '/telefonia_audio.local.php', '<?php return ["enabled"=>true];');
    $on=renderAudioHost($pilot,'155');
    if (!str_contains($on,'IMPE_AUDIO_DEVICES_CONFIG') || !str_contains($on,'"salesSdkIncoming":true')) throw new RuntimeException('Piloto u observador ausente');
    if (substr_count($on,'public/javascript/telefonia_audio_dispositivos.js?')!==1) throw new RuntimeException('Carga duplicada');
    if (strpos($on,'public/javascript/telefonia_audio_dispositivos.js?') > strpos($on,'public/javascript/telefonia_host.js?')) throw new RuntimeException('Orden incorrecto');
    foreach ([[$pilot,'150'],[array_replace($pilot,['usuario'=>'Mayra.mendoza']),'155'],[array_replace($pilot,['rol_id'=>4,'rol'=>'Analista de Datos']),'155'],[array_replace($pilot,['rol_id'=>5,'rol'=>'Marketing']),'100']] as [$session,$extension]) {
        if (str_contains(renderAudioHost($session,$extension),'IMPE_AUDIO_DEVICES_CONFIG')) throw new RuntimeException('Filtración a otro usuario/rol');
    }
    file_put_contents($dir . '/telefonia_audio.local.php', '<?php return ["enabled"=>true,"scope"=>"assigned"];');
    foreach ([['usuario_id'=>7,'usuario'=>'recepcion','rol_id'=>5,'rol'=>'Marketing'],['usuario_id'=>5,'usuario'=>'analista','rol_id'=>4,'rol'=>'Analista de Datos'],['usuario_id'=>8,'usuario'=>'asesora','rol_id'=>3,'rol'=>'Asesor de Ventas']] as $session) {
        if (!str_contains(renderAudioHost($session,'150'),'IMPE_AUDIO_DEVICES_CONFIG')) throw new RuntimeException('Extensión autorizada excluida');
    }
    if (str_contains(renderAudioHost($pilot,''),'IMPE_AUDIO_DEVICES_CONFIG') ||
        str_contains(renderAudioHost($pilot,'155',false),'IMPE_AUDIO_DEVICES_CONFIG')) throw new RuntimeException('Extensión no disponible incluida');
    $GLOBALS['audioDenyPermission']=true;
    if (str_contains(renderAudioHost($pilot,'155'),'IMPE_AUDIO_DEVICES_CONFIG')) throw new RuntimeException('Sin permiso se cargó el gestor');
    $GLOBALS['audioDenyPermission']=false;
    file_put_contents($dir . '/telefonia_audio.local.php', '<?php throw new RuntimeException("config inválida");');
    if (str_contains(renderAudioHost($pilot,'155'),'IMPE_AUDIO_DEVICES_CONFIG')) throw new RuntimeException('Configuración inválida no se cerró de forma segura');
    echo "PASS render host: apagado/piloto/assigned, extensión y capacidades, permisos, orden de scripts y observador de transferencias conservado.\n";
} finally {
    unlink($dir . '/telefonia_audio.local.php');rmdir($dir);
    putenv($before===false ? 'IMPE_PRIVATE_CONFIG_DIR' : 'IMPE_PRIVATE_CONFIG_DIR='.$before);
}
