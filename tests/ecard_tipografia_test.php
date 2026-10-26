<?php
require_once __DIR__ . '/../app/services/EcardReunionService.php';
set_error_handler(static function ($n,$s,$f,$l) { if (error_reporting() & $n) throw new ErrorException($s,0,$n,$f,$l); return false; });
function comprobarET($v,$m) { if (!$v) throw new RuntimeException($m); }
function instanciaET($clase,$root) {
    $ref = new ReflectionClass($clase); $s = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('rootPath')->setValue($s,$root);
    $ref->getProperty('connection')->setValue($s,new stdClass());
    return $s;
}
function altoTintaET($imagen,$inicio,$fin) {
    $top=$fin;$bottom=$inicio;
    for($y=$inicio;$y<=$fin;$y++)for($x=120;$x<1080;$x++) {
        $c=imagecolorat($imagen,$x,$y);
        if((($c>>16)&255)<35&&(($c>>8)&255)<95&&($c&255)<135){$top=min($top,$y);$bottom=max($bottom,$y);}
    }
    return max(0,$bottom-$top+1);
}
$root=dirname(__DIR__);$out=$root.'/tmp/ecard_tipografia';$sandbox=$out.'/sandbox';
if(!is_dir($sandbox))mkdir($sandbox,0777,true);
foreach(array_merge(glob($root.'/public/img/ecards/templates/manuel-hq.part*.b64'),[
    $root.'/public/img/ecards/manuel-porcayo.jpg',
    $root.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf',
    $root.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf'
]) as $archivo){$destino=$sandbox.substr($archivo,strlen($root));if(!is_dir(dirname($destino)))mkdir(dirname($destino),0777,true);copy($archivo,$destino);}
$antes=shell_exec('git show 050428d9:app/services/EcardReunionService.php');
comprobarET(hash('sha256',$antes)==='6b77dae491cb9ff3009d357b7e469683b2f41199c1fa4e489bdbff49c73e33fc','La base debe ser exactamente el generador diagnosticado en Hostinger');
// El historial ya especificaba 38 pt institución, 44 pt fecha y 34 pt hora.
// El método de la plantilla se conserva; se restaura la fuente y su métrica real.
$historia=shell_exec('git show 49639833:app/services/EcardReunionService.php');
$actual=str_replace("\r\n","\n",file_get_contents($root.'/app/services/EcardReunionService.php'));
$patron='/    private function crearImagenManuelPlantilla\([\s\S]*?(?=    private function )/';
preg_match($patron,$antes,$m1);preg_match($patron,$actual,$m2);
comprobarET($m1[0]===$m2[0],'No rediseñar la plantilla ni cambiar los tamaños aprobados');
foreach(['$tamanoFecha = $this->tamanoParaAncho(', '$tamanoHora = $this->tamanoParaAncho('] as $texto)comprobarET(str_contains($historia,$texto),'El tamaño se ajustaba al ancho desde la versión histórica');
$antes=str_replace("require_once __DIR__ . '/../../config/db_connection.php';",'',$antes);
$antes=str_replace('class EcardReunionService','class EcardAnteriorET',$antes);
// Hostinger tiene las fuentes de Dompdf pero el generador anterior no las buscaba.
$antes=str_replace(['C:/Windows/Fonts/','/usr/share/fonts/'],['/fuente-ausente/','/fuente-ausente/'],$antes);
eval('?>'.$antes);
$viejo=instanciaET('EcardAnteriorET',$sandbox);$nuevo=instanciaET(EcardReunionService::class,$sandbox);
$reunion=['id'=>900001,'nombre_entidad'=>'PRUEBA 2','fecha_propuesta'=>'2026-10-26 12:37:00','modalidad'=>'VIRTUAL'];
$r1=$viejo->generar($reunion,'MANUEL');comprobarET($r1['ok'],'Reproducir el generador anterior');copy($r1['ruta'],$out.'/antes.jpg');
$sentinela=dirname($r1['ruta']).'/reunion_900002_protegida.jpg';file_put_contents($sentinela,'No tocar otras reuniones');
$r2=$nuevo->generar($reunion,'MANUEL');comprobarET($r2['ok'],'Generación corregida');copy($r2['ruta'],$out.'/corregida.jpg');
comprobarET($r1['archivo']!==$r2['archivo']&&!is_file($r1['ruta']),'La nueva versión no reutiliza el JPEG de letra pequeña');
comprobarET(file_get_contents($sentinela)==='No tocar otras reuniones','No eliminar caché de otras reuniones');
$a=imagecreatefromjpeg($out.'/antes.jpg');$b=imagecreatefromjpeg($out.'/corregida.jpg');
comprobarET(imagesx($b)===1200&&imagesy($b)===1212,'Conservar resolución aprobada');
$fechaAntes=altoTintaET($a,700,780);$fechaAhora=altoTintaET($b,700,780);
$horaAntes=altoTintaET($a,790,850);$horaAhora=altoTintaET($b,790,850);
comprobarET($fechaAntes<=16&&$horaAntes<=16,'Reproducir la letra bitmap pequeña de la captura');
comprobarET($fechaAhora>=40&&$horaAhora>=30,'Fecha y hora deben tener un tamaño real legible');
foreach([[0,150],[340,600],[870,1211]] as [$top,$bottom])for($y=$top;$y<=$bottom;$y++)for($x=0;$x<1200;$x++) {
    comprobarET(imagecolorat($a,$x,$y)===imagecolorat($b,$x,$y),'Fondos, logos y retrato deben permanecer iguales');
}
foreach(['antes'=>$a,'corregida'=>$b] as $nombre=>$imagen){$small=imagescale($imagen,720);imagepng($small,$out.'/'.$nombre.'.png');imagedestroy($small);imagedestroy($imagen);}
$hash=hash_file('sha256',$r2['ruta']);touch($r2['ruta'],time()-600);clearstatcache(true,$r2['ruta']);$mtime=filemtime($r2['ruta']);
$r3=$nuevo->generar($reunion,'MANUEL');clearstatcache(true,$r3['ruta']);
comprobarET($r3['archivo']===$r2['archivo']&&hash_file('sha256',$r3['ruta'])===$hash&&filemtime($r3['ruta'])===$mtime,'La nueva caché válida se reutiliza');
echo "OK: base exacta de Hostinger; historial de tamaños conservado; fecha {$fechaAntes}→{$fechaAhora} px, hora {$horaAntes}→{$horaAhora} px; caché anterior sustituida; fondos/logos/retrato intactos. Sin BD ni envíos.\n";
