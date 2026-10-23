<?php
if(getenv('IMPE_TEST_ALLOW_ROUTER')!=='1'||!preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:'')||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/public/javascript/convocatorias_aliado_modular.js'){header('Content-Type: application/javascript');readfile(__DIR__.'/fixtures/v3_hostinger/convocatorias_aliado_modular.js');return true;}
if($path==='/public/css/convocatorias_aliado_modular.css'){header('Content-Type: text/css');readfile(__DIR__.'/fixtures/v3_hostinger/convocatorias_aliado_modular.css');return true;}
if(!in_array($path,['/_fixture_v3','/index.php','/_v3_stats'],true))return false;
session_start();
require_once __DIR__.'/../app/services/AliadoRegistroService.php';
require_once __DIR__.'/../config/db_connection.php';
if($path==='/_v3_stats'){
    $db=(new Database())->connect(false);
    $filter='';if(isset($_GET['ids'])){if(!preg_match('/^[0-9]+(?:,[0-9]+){0,29}$/D',$_GET['ids'])){http_response_code(400);return true;}$filter=' AND c.id IN ('.implode(',',array_map('intval',explode(',',$_GET['ids']))).')';}
    $counts=$db->query("SELECT COUNT(*) n,COUNT(DISTINCT imagen) images,COUNT(DISTINCT enlace_registro) urls FROM convocatorias c WHERE c.titulo LIKE 'V3 TEST %'$filter")->fetch_assoc();
    $counts['links']=$db->query("SELECT COUNT(*) n FROM marketing_aliado_convocatorias_origen o JOIN convocatorias c ON c.id=o.convocatoria_id WHERE c.titulo LIKE 'V3 TEST %'$filter")->fetch_assoc()['n'];
    $counts['manual_links']=$db->query("SELECT COUNT(*) n FROM marketing_aliado_manual_convocatorias o JOIN convocatorias c ON c.id=o.convocatoria_id WHERE c.titulo LIKE 'V3 TEST %'$filter")->fetch_assoc()['n'];
    header('Content-Type: application/json');echo json_encode($counts);return true;
}
if(($_GET['action']??'')==='guardarProgramasAliado'){
    require_once __DIR__.'/../app/controllers/ConvocatoriaController.php';
    (new ConvocatoriaController())->guardarProgramasAliado();exit;
}
$case=$_GET['caso']??($_SESSION['v3_case']??'ssp');$role=$_GET['role']??($_SESSION['v3_role']??'marketing');
$_SESSION=['usuario_id'=>$role==='marketing'?4:($role==='sin-territorio'?21:2),'rol_id'=>$role==='marketing'?5:6,'rol'=>$role==='marketing'?'Marketing':'Cuenta Clave','permisos'=>['convenios.ver','convocatorias.ver','convocatorias.crear','formularios_aliados.gestionar_enlaces'],'csrf_token'=>str_repeat('a',64),'v3_case'=>$case,'v3_role'=>$role];
if($role==='sin-permiso')$_SESSION['permisos']=[];
$service=new AliadoRegistroService((new Database())->connect());$db=(new Database())->connect(false);
$_GET['aliado_tipo']=in_array($case,['iqj','ssp'],true)?'institucional':'manual';
$_GET['aliado_id']=$case==='iqj'?9101:9102;
if($_GET['aliado_tipo']==='manual'){
    $names=['uno'=>'Uno V3','tres'=>'Tres V3','sin-clase'=>'Sin clase V3'];
    if(!isset($names[$case])){http_response_code(404);return true;}
    $s=$db->prepare('SELECT id FROM aliados_manuales WHERE nombre_entidad=?');$s->bind_param('s',$names[$case]);$s->execute();$_GET['aliado_id']=(int)$s->get_result()->fetch_assoc()['id'];
}
try{$aliadoContextoConvocatoria=$service->opcionesConvocatoria($_GET['aliado_tipo'],$_GET['aliado_id']);}
catch(DomainException $e){http_response_code($e->getCode()===403?403:422);echo htmlspecialchars($e->getMessage());return true;}
$puedeCrear=puedeCrearConvocatoriaDesdeConvenio();$texto=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><link rel="stylesheet" href="/public/css/convocatorias.css"></head><body>
<div class="modal fade" id="modalCrearConvocatoria"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg system-form-dialog"><div class="modal-content system-form-modal">
<div class="modal-header system-form-modal-header"><div><h5 class="system-form-modal-title">Nueva convocatoria</h5><p class="system-form-modal-subtitle">Registra una publicación y su cobertura territorial</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
<form class="convocatoria-create-form" action="/index.php?controller=convocatoria&action=guardar" method="POST" enctype="multipart/form-data" novalidate><input name="csrf_token" type="hidden" value="<?= $texto($_SESSION['csrf_token']) ?>"><div class="modal-body"><div class="system-form-grid">
<div class="system-form-full"><label>Enlace de registro<input name="enlace_registro" class="form-control"></label></div><div class="system-form-full"><label>Título<input name="titulo" class="form-control"></label><input name="clasificacion" type="hidden"></div>
</div></div><div class="modal-footer"><button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-system-save">Guardar convocatoria</button></div></form>
</div></div></div>
<script src="/bootstrap.js"></script><script>window.legacySubmits=0;document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.legacySubmits++});</script>
<?php require __DIR__.'/fixtures/v3_hostinger/aliado_programas_modular.php'; ?>
<script>document.addEventListener('DOMContentLoaded',()=>bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCrearConvocatoria')).show());</script></body></html>
