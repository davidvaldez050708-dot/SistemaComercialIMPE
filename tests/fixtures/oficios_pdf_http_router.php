<?php
// Router local de prueba; no forma parte de la aplicación ni del publicador.
if (($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1' || !getenv('IMPE_OFICIOS_HTTP_TOKEN')) { http_response_code(404); exit; }
$root=__DIR__;
if (session_status()!==PHP_SESSION_ACTIVE) { session_start(); }
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/__test_login') {
    if ($_SERVER['REQUEST_METHOD']!=='POST' || !hash_equals(getenv('IMPE_OFICIOS_HTTP_TOKEN'),(string)($_POST['token']??''))) { http_response_code(403); exit; }
    session_regenerate_id(true);
    $_SESSION=['usuario_id'=>9,'rol_id'=>2,'rol'=>'Analista','permisos'=>['oficios.ver','oficios.generar','seguimientos_vinculacion.operar_propios']];
    header('Content-Type: application/json'); echo '{"ok":true}'; exit;
}
if ($path==='/preview') { require $root.'/public/oficio_vista_previa_documento.php'; exit; }
if ($path==='/download') { require $root.'/public/oficio_pdf_documento.php'; exit; }
require_once $root.'/app/controllers/OficioVinculacionController.php';
if ($path==='/generate') { (new OficioVinculacionController())->generarPdf(); exit; }
if ($path==='/docx') { (new OficioVinculacionController())->descargarDocx(); exit; }
http_response_code(404);
