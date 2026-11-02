<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
define('BASE_URL', 'https://crm.test/');
function tienePermiso(string $p): bool { return true; }
$dir = sys_get_temp_dir() . '/impe-audio-test-' . bin2hex(random_bytes(6));
mkdir($dir,0700);
$before = getenv('IMPE_PRIVATE_CONFIG_DIR');
putenv('IMPE_PRIVATE_CONFIG_DIR=' . $dir);
function renderAudioHost(array $session, string $extension): string {
    $_SESSION = $session + ['nombre'=>'Prueba','apellidos'=>'Llamadas','csrf_token'=>'test'];
    $_GET = ['display'=>'embed'];
    $asignacionTelefonica = ['extension'=>$extension,'permite_entrantes'=>1,'permite_salientes'=>1];
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
    file_put_contents($dir . '/telefonia_audio.local.php', '<?php throw new RuntimeException("config inválida");');
    if (str_contains(renderAudioHost($pilot,'155'),'IMPE_AUDIO_DEVICES_CONFIG')) throw new RuntimeException('Configuración inválida no se cerró de forma segura');
    echo "PASS render host: bandera apagada, piloto único, orden de scripts y conservación del observador de transferencias de Hostinger.\n";
} finally {
    unlink($dir . '/telefonia_audio.local.php');rmdir($dir);
    putenv($before===false ? 'IMPE_PRIVATE_CONFIG_DIR' : 'IMPE_PRIVATE_CONFIG_DIR='.$before);
}
