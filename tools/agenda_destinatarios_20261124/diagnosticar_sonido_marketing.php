<?php
// Diagnóstico de fuentes: no inicia sesión, conecta a BD ni modifica archivos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=realpath($argv[1]??(getenv('IMPE_AGENDA_PUBLIC')?:getenv('HOME').'/domains/fundacionrededumex.org/public_html'));
if (!$root || !is_file($root.'/index.php')) { fwrite(STDERR,"No se encontró la raíz del CRM.\n");exit(1); }
echo "DIAGNÓSTICO DE SONIDO DE AGENDA EN MARKETING. SITIO INTACTO.\n";
function mostrarFuente(string $root,string $name,bool $complete=false,int $context=5): void {
    $path=$root.'/'.$name;
    if (!is_file($path)) { echo "NO EXISTE: $name\n";return; }
    $text=file_get_contents($path);
    if ($text===false) throw new RuntimeException('No se pudo leer '.$name);
    echo "\nARCHIVO: $name\nSHA256: ".hash('sha256',$text)."\n";
    $lines=preg_split('/\r?\n/',$text);
    if ($complete) {
        echo $text."\nFIN ARCHIVO: $name\n";
        return;
    }
    $selected=[];
    foreach ($lines as $index=>$line) {
        if (!preg_match('/IMPEAgendaAlerts|agenda|horarios|AudioContext|onToastShown|setPresenter|canAnnounceComment|hasScheduleSource|convocatoria_notificaciones|esMarketingTopbar|data-reminder-root/i',$line)) continue;
        for ($i=max(0,$index-$context);$i<=min(count($lines)-1,$index+$context);$i++) $selected[$i]=true;
    }
    if (!$selected) echo "Sin referencias a la conexión de sonido de Agenda.\n";
    ksort($selected);
    $previous=-2;
    foreach (array_keys($selected) as $index) {
        if ($index>$previous+1) echo "...\n";
        echo ($index+1).': '.$lines[$index]."\n";
        $previous=$index;
    }
}

mostrarFuente($root,'public/javascript/convocatoria_notificaciones.js',true);
mostrarFuente($root,'public/javascript/recordatorios.js');
$engines=[];
foreach (glob($root.'/public/javascript/*.js')?:[] as $path) {
    $text=file_get_contents($path);
    if ($text!==false && str_contains($text,'IMPEAgendaAlerts') && !in_array(basename($path),['recordatorios.js','convocatoria_notificaciones.js'],true)) {
        $name='public/javascript/'.basename($path);
        $engines[]=$name;
        mostrarFuente($root,$name,true);
    }
}
if (!$engines) echo "\nNo se encontró un motor que defina IMPEAgendaAlerts en public/javascript.\n";
foreach (['app/views/layout/dashboard_head.php','app/views/layout/dashboard_footer.php','app/views/layout/topbar.php','app/controllers/ConvocatoriaNotificacionController.php'] as $name) mostrarFuente($root,$name);
echo "\nFIN DIAGNÓSTICO. No se modificó ningún archivo ni dato del CRM.\n";
