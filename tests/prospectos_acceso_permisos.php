<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../app/controllers/ProspectoController.php';
$casos=[
    'marketing_permitido'=>['Marketing',['prospectos.ver_todos'],true],
    'marketing_sin'=>['Marketing',[],false],
    'marketing_convocatorias'=>['Marketing',['convocatorias.ver'],false],
    'cuenta_clave'=>['Cuenta Clave',['prospectos.ver_todos'],true],
    'cuenta_clave_sin'=>['Cuenta Clave',['convenios.ver'],false],
    'ventas_propios'=>['Asesor de Ventas',['prospectos.ver_propios'],false],
    'ventas_permitido'=>['Asesor de Ventas',['prospectos.ver_todos'],true],
    'admin'=>['Administrador',['prospectos.ver_todos'],true],
    'editar_no_es_consultar'=>['Marketing',['prospectos.editar'],false],
    'sin_sesion'=>['Marketing',['prospectos.ver_todos'],false],
];
if (($argv[1]??'')==='--gate') {
    if (!isset($casos[$argv[2]??''])) exit(2);
    [$rol,$permisos]=$casos[$argv[2]];
    $_SESSION=['rol'=>$rol,'rol_id'=>$rol==='Administrador'?1:6,'permisos'=>$permisos];
    if ($argv[2]!=='sin_sesion') $_SESSION['usuario_id']=2;
    register_shutdown_function(static function(){ echo '\nSTATUS='.(http_response_code()?:200); });
    (new ReflectionMethod(ProspectoController::class,'validarAcceso'))->invoke(new ProspectoController());
    echo 'ACCESS_OK';exit;
}
$count=0;
function checkProspectos($ok,$mensaje){global $count;if(!$ok)throw new RuntimeException($mensaje);$count++;}
foreach($casos as $nombre=>[$rol,$permisos,$permitido]){
    $_SESSION=['rol'=>$rol,'rol_id'=>$rol==='Administrador'?1:6,'permisos'=>$permisos];
    if($nombre!=='sin_sesion')$_SESSION['usuario_id']=2;
    $original=$_SESSION;$nombreCompleto='Usuario de prueba';$opcionActiva='prospectos';
    ob_start();require __DIR__.'/../app/views/layout/sidebar.php';$html=ob_get_clean();
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath=new DOMXPath($dom);
    $links=$xpath->query('//a[contains(@href,"controller=prospecto&action=index")]');
    checkProspectos($links->length===($permitido?2:0),'Menús escritorio/móvil: '.$nombre);
    foreach($links as $link){checkProspectos(trim($link->textContent)==='Prospectos','Etiqueta');checkProspectos(str_contains($link->getAttribute('class'),'active'),'Activo');}
    checkProspectos($_SESSION===$original,'Sin otorgar permisos');
    checkProspectos($xpath->query('//a[contains(@href,"controller=crearConvocatoria&action=index")]')->length===(puedeConsultarDirectorioConvenios()?2:0),'Convenios independiente');
    checkProspectos($xpath->query('//a[contains(@href,"controller=convocatoria&action=index")]')->length===(tienePermiso('convocatorias.ver')?2:0),'Convocatorias independiente');
    $p=proc_open([PHP_BINARY,__FILE__,'--gate',$nombre],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    checkProspectos(proc_close($p)===0&&$err==='','Guard sin errores '.$err);
    checkProspectos(str_contains($out,'ACCESS_OK')===$permitido,'Acceso directo: '.$nombre);
    if(!$permitido&&$nombre!=='sin_sesion')checkProspectos(str_contains($out,'STATUS=403'),'HTTP 403');
}
$source=file_get_contents(__DIR__.'/../app/controllers/ProspectoController.php');
checkProspectos(strpos($source,'$this->validarAcceso();')<strpos($source,'new ProspectoModel()'),'Guard antes de DB');
echo "PASS $count comprobaciones de Prospectos, 10 combinaciones de permisos, sesión, escritorio/móvil y guard real.\n";
