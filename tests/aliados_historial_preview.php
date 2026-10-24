<?php
// Vista real con datos sintéticos. Sin conexión ni consultas a la base de datos.
if(PHP_SAPI!=='cli')exit(2);
parse_str($argv[1]??'',$_GET);
define('BASE_URL','http://127.0.0.1:8135/');
require_once __DIR__.'/../app/services/AliadoHistorialService.php';
$permisos=['aliados.ver','aliados.ver_historial','aliados.gestionar_programas','aliados.editar_manual','aliados.compartir_correo','aliados.preparar_whatsapp','convocatorias.ver'];
if(($_GET['role']??'')==='consulta')$permisos=['aliados.ver','aliados.ver_historial'];
if(($_GET['role']??'')==='sin-historial')$permisos=['aliados.ver','aliados.gestionar_programas'];
$_SESSION=['usuario_id'=>2,'rol_id'=>4,'rol'=>'Cuenta Clave','permisos'=>$permisos];
$event=AliadoHistorialService::normalizarManual(['id'=>8,'canal'=>'CORREO','realizado_at'=>'2026-10-23 22:32:18','responsable'=>'Susana Molina','nota'=>'Convocatoria #13 · Bachillerato General · Campus A · BACHILLERATO EXAMEN ÚNICO IEPFI · contacto@example.test']);
$common=['difusion_avance'=>['total'=>2,'compartidas'=>1,'pendientes'=>1],'id'=>1,'estado_id'=>1,'estado_nombre'=>'Guanajuato','municipio_id'=>1,'municipio_nombre'=>'Celaya','contacto_nombre'=>'Lic. Contacto de prueba','contacto_cargo'=>'Coordinación académica','correo_contacto'=>'contacto@example.test','analista_nombre'=>'Diego Bahena','convenio_formalizado_at'=>'2025-12-05','registro_programas'=>'Bachillerato General · Inglés','situacion'=>'VIGENTE','ultimo_envio_id'=>8,'ultimo_envio_canal'=>'CORREO','ultimo_envio_at'=>$event['enviado_at'],'seguimiento_convocatoria_estado'=>'ESPERANDO_RESPUESTA'];
$manual=$common+['origen_tipo'=>'manual','origen_id'=>1,'seguimiento_id'=>0,'nombre_entidad'=>'Instituto de Estudios de Prevención y Formación Interdisciplinaria (IEPFI)','ultima_convocatoria_titulo'=>$event['convocatoria_titulo']];
$inst=$common+['origen_tipo'=>'institucional','origen_id'=>1,'seguimiento_id'=>1,'nombre_entidad'=>'Instituto de la Juventud Celayense','ultima_convocatoria_titulo'=>'Bachillerato General · Convocatoria institucional'];
if(($_GET['preview']??'')==='historial'){
    $tipo=$_GET['origen_tipo']??'';
    $wa=array_replace($event,['id'=>7,'convocatoria_id'=>14,'convocatoria_titulo'=>'Inglés · Convocatoria de prueba','canal'=>'WHATSAPP_MANUAL','estado_envio'=>'COMPARTIDO','destinatario'=>'7771113333']);
    $legacy=array_replace($event,['id'=>6,'convocatoria_id'=>null,'convocatoria_titulo'=>'Difusión registrada','nota'=>'Nota histórica <script>prueba</script>','estado_envio'=>'REGISTRADO']);
    $institucional=array_replace($event,['convocatoria_titulo'=>'Convocatoria institucional de Bachillerato','id'=>30]);
    header('Content-Type: application/json');echo json_encode(['ok'=>true,'aliado'=>$tipo==='manual'?$manual:$inst,'historial'=>$tipo==='manual'?[$event,$wa,$legacy]:[$institucional]],JSON_UNESCAPED_UNICODE);exit;
}
$aliados=[$inst,$manual];$aliadosPorMunicipio=[['id'=>1,'nombre'=>'Celaya','aliados'=>$aliados]];$estado=['id'=>1,'nombre'=>'Guanajuato'];$municipiosAliados=[['id'=>1,'nombre'=>'Celaya']];
$estructuraAliadosDisponible=true;$puedeVerHistorial=tienePermiso('aliados.ver_historial');$puedeCompartirCorreo=tienePermiso('aliados.compartir_correo');$puedePrepararWhatsapp=tienePermiso('aliados.preparar_whatsapp');$puedeConsultarConvocatorias=tienePermiso('convocatorias.ver');
$resumenAliados=['total'=>2,'municipios'=>1,'formalizados'=>1,'con_correo'=>2,'con_whatsapp'=>0,'convocatorias_vigentes'=>4,'seguimientos_pendientes'=>2,'seguimientos_vencidos'=>0];
function renderRegistroPreview($estado,$texto){
    $catalogoRegistro=[['codigo'=>'bachillerato-general','nombre'=>'Bachillerato General']];
    $partial=file_get_contents(__DIR__.'/../app/views/aliados/partials/registro_gestion.php');
    $start=strpos($partial,'?>');
    // Sustituir exclusivamente la consulta inicial del catálogo por un catálogo sintético.
    eval('?><?php if (tienePermiso("aliados.gestionar_programas")): ?>'.substr($partial,$start+2));
}
function renderAvancePreview($texto){require __DIR__.'/../app/views/aliados/partials/avance_difusion.php';}
function renderAliadosPreview(){
    extract($GLOBALS,EXTR_SKIP);
    $view=file_get_contents(__DIR__.'/../app/views/aliados/estado.php');
    $view=str_replace("require __DIR__ . '/partials/registro_gestion.php';",'renderRegistroPreview($estado,$texto);',$view);
    $view=str_replace("require __DIR__ . '/partials/avance_difusion.php';",'renderAvancePreview($texto);',$view);
    eval('?>'.$view);
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= str_repeat('a',64) ?>">
<?php foreach(['/fixture-bootstrap.css','/fixture-icons.css','/fixture-font.css','/public/css/global.css','/public/css/dashboard.css','/public/css/aliados.css','/public/css/aliados_v2_extension.css','/public/css/responsive_sistema.css'] as $css): ?><link rel="stylesheet" href="<?= $css ?>"><?php endforeach; ?>
<style>body{background:#f4f7fb}main{padding:24px;min-width:0}@media(max-width:767px){main{padding:12px}}</style></head><body><main><?php renderAliadosPreview(); ?></main><script src="/fixture-bootstrap.js"></script><script src="/public/javascript/aliados_registro.js"></script><script src="/public/javascript/aliados.js"></script></body></html>
